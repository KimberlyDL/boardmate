<?php

namespace App\Services\Billing;

use App\Enums\AuditEvent;
use App\Enums\BilledBy;
use App\Enums\BillKind;
use App\Enums\BillLineKind;
use App\Enums\DocumentType;
use App\Enums\FilePurpose;
use App\Enums\NotificationEvent;
use App\Enums\TenancyStatus;
use App\Enums\UnitKind;
use App\Enums\UtilityDueRule;
use App\Enums\UtilityMethod;
use App\Models\Bill;
use App\Models\Room;
use App\Models\RoomLeader;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\UtilityAccount;
use App\Models\UtilityBill;
use App\Services\Audit\Contracts\AuditService;
use App\Services\BillingCalendar\BillingCalendar;
use App\Services\Files\Contracts\FileService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Numbering\Contracts\NumberingService;
use App\Services\Split\SplitEngine;
use App\Support\ManilaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Metered ("actual bill") utilities (C3 SC-03, C4 SC-20): the owner or a
 * Manager enters the provider's bill when it arrives, and each room's share
 * is billed to that room's leader as its own bill, due N days after entry.
 *
 * - A room's own meter bills that room in full.
 * - A whole-property meter is split across rooms by occupant-days within the
 *   bill's coverage dates: everyone staying (bedspace tenants, and the people
 *   listed in a room rented whole) counts the days they were there.
 * - A room with a share but no leader stops the bill until a leader is
 *   appointed or the owner waives that share (the owner absorbs it).
 * - A wrong total is corrected by a new entry that keeps the same split; each
 *   room's bill gets an adjustment line for the difference (SC-14).
 */
class MeteredBillService
{
    public function __construct(
        private readonly SplitEngine $split,
        private readonly NumberingService $numbering,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly PaymentLedger $ledger,
        private readonly FileService $files,
    ) {}

    /**
     * Each room's share, without saving anything (for the entry screen).
     *
     * @param  list<int>  $waivedRoomIds
     * @return list<array{room_id: int, room_label: string, occupant_days: int, amount_centavos: int, leader: array{id: int, name: string}|null, waived: bool}>
     */
    public function preview(UtilityAccount $account, string $from, string $to, int $totalCentavos, array $waivedRoomIds = []): array
    {
        $this->assertAccount($account);
        $this->assertDates($from, $to);

        $days = $this->occupantDays($account, $from, $to);
        if ($days === []) {
            throw ValidationException::withMessages(['coverage_from_on' => 'Nobody stayed in the rooms this meter covers during these dates.']);
        }
        $result = $this->split->weights($totalCentavos, $days);
        $rooms = Room::whereKey(array_keys($days))->get()->keyBy('id');
        $leaders = RoomLeader::current()->whereIn('room_id', array_keys($days))->with('user:id,name')->get()->keyBy('room_id');

        return collect($days)->map(fn (int $d, int $roomId) => [
            'room_id' => $roomId,
            'room_label' => $rooms[$roomId]->label(),
            'occupant_days' => $d,
            'amount_centavos' => $result->shareOf($roomId),
            'leader' => $leaders->has($roomId) ? ['id' => $leaders[$roomId]->user->id, 'name' => $leaders[$roomId]->user->name] : null,
            'waived' => in_array($roomId, $waivedRoomIds, true),
        ])->values()->all();
    }

    /**
     * Save the provider's bill and bill each room's leader for their share.
     *
     * @param  array{coverage_from_on: string, coverage_to_on: string, total_centavos: int, waive_room_ids?: list<int>}  $data
     */
    public function enter(UtilityAccount $account, array $data, ?UploadedFile $photo, User $by): UtilityBill
    {
        $shares = $this->preview($account, $data['coverage_from_on'], $data['coverage_to_on'], $data['total_centavos'], $data['waive_room_ids'] ?? []);
        $missing = collect($shares)->filter(fn ($s) => ! $s['waived'] && $s['amount_centavos'] > 0 && $s['leader'] === null);
        if ($missing->isNotEmpty()) {
            throw ValidationException::withMessages(['waive_room_ids' => $missing->map(fn ($s) => "Room {$s['room_label']} has no leader. Appoint one, or waive its share.")->values()->all()]);
        }

        $property = $account->property;
        $settings = $property->settings;
        $today = ManilaDate::today();
        $dueOn = BillingCalendar::utilityDueDate($today, UtilityDueRule::DaysAfterIssue, (int) ($settings?->utility_due_days ?? 7));

        [$utilityBill, $bills] = DB::transaction(function () use ($account, $data, $photo, $by, $shares, $property, $today, $dueOn) {
            $utilityBill = UtilityBill::create([
                'property_id' => $property->id,
                'utility_account_id' => $account->id,
                'coverage_from_on' => $data['coverage_from_on'],
                'coverage_to_on' => $data['coverage_to_on'],
                'total_centavos' => $data['total_centavos'],
                'shares' => $shares,
                'photo_path' => $photo ? $this->files->store($photo, FilePurpose::UtilityBillPhoto) : null,
                'entered_by' => $by->id,
            ]);

            $bills = collect();
            foreach ($shares as $share) {
                if ($share['waived'] || $share['amount_centavos'] <= 0) {
                    continue;
                }
                $tenancy = Tenancy::where('room_id', $share['room_id'])->where('tenant_id', $share['leader']['id'])
                    ->where('status', TenancyStatus::Active->value)->first();
                $bill = Bill::create([
                    'property_id' => $property->id,
                    'payer_id' => $share['leader']['id'],
                    'tenancy_id' => $tenancy?->id,
                    'room_id' => $share['room_id'],
                    'utility_bill_id' => $utilityBill->id,
                    'kind' => BillKind::Utility,
                    'number' => $this->numbering->next($property->owner, DocumentType::Bill, $today),
                    'period_start_on' => $data['coverage_from_on'],
                    'period_end_on' => $data['coverage_to_on'],
                    'issued_on' => $today->toDateString(),
                    'due_on' => $dueOn->toDateString(),
                    'issued_by' => $by->id,
                ]);
                $bill->lines()->create([
                    'kind' => BillLineKind::UtilityShare,
                    'description' => "{$account->name}, {$this->span($utilityBill)}: room {$share['room_label']}'s share"
                        .(count($shares) > 1 ? " ({$share['occupant_days']} occupant-days)" : ''),
                    'amount_centavos' => $share['amount_centavos'],
                    'utility_account_id' => $account->id,
                    'created_by' => $by->id,
                    'sort_order' => 1,
                ]);
                $bills->push($bill);
            }

            return [$utilityBill, $bills];
        });

        $waived = collect($shares)->where('waived', true)->pluck('room_label');
        $this->audit->record(AuditEvent::UtilityBillEntered, $utilityBill, owner: $property->owner,
            note: "{$property->name} · {$account->name} {$this->span($utilityBill)} · ".Money::format($utilityBill->total_centavos)
                .' · '.$bills->count().' room(s) billed'.($waived->isNotEmpty() ? ' · waived: '.$waived->implode(', ') : ''));

        foreach ($bills as $bill) {
            $this->ledger->settle($bill->property_id, $bill->payer_id);
            $this->notifications->send($bill->payer, NotificationEvent::BillIssued, [
                'property_name' => $property->name,
                'bill_number' => $bill->number,
                'total' => Money::format($bill->totalCentavos()),
                'due_on' => $bill->due_on->format('M j, Y'),
                'period' => $this->span($utilityBill),
                'summary' => "your room's share of the {$account->name} bill",
            ]);
        }

        return $utilityBill;
    }

    /**
     * A wrong total: a new entry replaces the old one with the same split, and
     * each room's bill gets an adjustment line for the difference (SC-14).
     */
    public function correct(UtilityBill $old, int $newTotalCentavos, string $reason, User $by): UtilityBill
    {
        $old->loadMissing('account', 'property.owner');
        if (UtilityBill::where('replaces_id', $old->id)->exists()) {
            throw ValidationException::withMessages(['total_centavos' => 'This entry was already corrected. Correct the latest one.']);
        }
        if ($newTotalCentavos === $old->total_centavos) {
            throw ValidationException::withMessages(['total_centavos' => 'The total is the same as before.']);
        }

        $oldShares = collect($old->shares ?? [])->keyBy('room_id');
        $result = $this->split->weights($newTotalCentavos, $oldShares->map(fn ($s) => (int) $s['occupant_days'])->all());
        $newShares = $oldShares->map(fn ($s, $roomId) => ['amount_centavos' => $result->shareOf($roomId)] + $s)->values()->all();

        [$replacement, $adjusted] = DB::transaction(function () use ($old, $newTotalCentavos, $reason, $by, $newShares) {
            $replacement = UtilityBill::create([
                'property_id' => $old->property_id,
                'utility_account_id' => $old->utility_account_id,
                'coverage_from_on' => $old->coverage_from_on->toDateString(),
                'coverage_to_on' => $old->coverage_to_on->toDateString(),
                'total_centavos' => $newTotalCentavos,
                'shares' => $newShares,
                'photo_path' => $old->photo_path,
                'replaces_id' => $old->id,
                'correction_reason' => $reason,
                'entered_by' => $by->id,
            ]);

            $adjusted = collect();
            $bills = Bill::whereIn('utility_bill_id', $this->chainIds($old))->with('lines')->get()->keyBy('room_id');
            foreach ($newShares as $share) {
                $bill = $bills->get($share['room_id']);
                if (! $bill) {
                    continue; // waived: the owner absorbs it either way
                }
                $diff = $share['amount_centavos'] - $bill->totalCentavos();
                if ($diff === 0) {
                    continue;
                }
                $shareLine = $bill->lines->firstWhere('kind', BillLineKind::UtilityShare);
                $bill->lines()->create([
                    'kind' => BillLineKind::Adjustment,
                    'description' => "{$old->account->name} total corrected: ".Money::format($old->total_centavos).' → '.Money::format($newTotalCentavos),
                    'amount_centavos' => $diff,
                    'adjusts_line_id' => $shareLine?->id,
                    'reason' => $reason,
                    'created_by' => $by->id,
                    'sort_order' => 50,
                ]);
                $adjusted->push([$bill, $diff]);
            }

            return [$replacement, $adjusted];
        });

        $property = $old->property;
        $this->audit->record(AuditEvent::UtilityBillCorrected, $replacement,
            ['total' => [Money::format($old->total_centavos), Money::format($newTotalCentavos)]], owner: $property->owner, reason: $reason,
            note: "{$property->name} · {$old->account->name} {$this->span($old)}");

        foreach ($adjusted as [$bill, $diff]) {
            $this->ledger->settle($bill->property_id, $bill->payer_id);
            $this->notifications->send($bill->payer, NotificationEvent::BillAdjusted, [
                'property_name' => $property->name,
                'bill_number' => $bill->number,
                'change' => ($diff > 0 ? '+' : '').Money::format($diff),
                'total' => Money::format($bill->totalCentavos()),
                'reason' => $reason,
            ]);
        }

        return $replacement;
    }

    /**
     * Occupant-days per room within the coverage dates, rooms with none left out.
     *
     * @return array<int, int> room id => days
     */
    public function occupantDays(UtilityAccount $account, string $from, string $to): array
    {
        $roomIds = $account->room_id ? [$account->room_id] : $account->property->rooms()->pluck('id')->all();
        $tenancies = Tenancy::whereIn('room_id', $roomIds)
            ->where('status', TenancyStatus::Active->value)
            ->where('moved_in_on', '<=', $to)
            ->with(['unit', 'occupants'])
            ->get();

        $days = [];
        foreach ($tenancies as $tenancy) {
            $stays = $tenancy->unit->kind === UnitKind::Whole && $tenancy->occupants->isNotEmpty()
                ? $tenancy->occupants->map(fn ($o) => [$o->joined_on, $o->left_on])
                : collect([[$tenancy->moved_in_on, null]]);
            foreach ($stays as [$joined, $left]) {
                $days[$tenancy->room_id] = ($days[$tenancy->room_id] ?? 0) + SplitEngine::daysPresent($from, $to, $joined, $left);
            }
        }

        return array_filter($days);
    }

    /** @return list<int> the entry and every entry it replaced (bills hang on the first one) */
    private function chainIds(UtilityBill $bill): array
    {
        $ids = [$bill->id];
        while ($bill->replaces_id) {
            $bill = UtilityBill::find($bill->replaces_id);
            $ids[] = $bill->id;
        }

        return $ids;
    }

    private function assertAccount(UtilityAccount $account): void
    {
        if ($account->method !== UtilityMethod::ActualBill || $account->billed_by !== BilledBy::Owner) {
            throw ValidationException::withMessages(['utility_account_id' => 'Only an "actual bill" utility the owner pays can be entered here.']);
        }
    }

    private function assertDates(string $from, string $to): void
    {
        if (CarbonImmutable::parse($to)->lessThan(CarbonImmutable::parse($from))) {
            throw ValidationException::withMessages(['coverage_to_on' => 'The end date must be on or after the start date.']);
        }
    }

    private function span(UtilityBill $bill): string
    {
        return $bill->coverage_from_on->format('M j').' – '.$bill->coverage_to_on->format('M j, Y');
    }
}
