<?php

namespace App\Services\Maintenance;

use App\Authorization\PropertyAccess;
use App\Enums\AuditEvent;
use App\Enums\FilePurpose;
use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceUrgency;
use App\Enums\NotificationEvent;
use App\Enums\PropertyAbility;
use App\Models\MaintenanceRequest;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Billing\ChargeService;
use App\Services\Files\Contracts\FileService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Maintenance requests (D1), kept plain on purpose: a list with statuses and
 * a short history. Every change adds a line to the request's notes so the
 * boarder and staff see what happened and when.
 */
class MaintenanceService
{
    /** A fixed request closes by itself after this many days without a "still broken". */
    public const AUTO_CLOSE_DAYS = 3;

    public function __construct(
        private readonly FileService $files,
        private readonly ChargeService $charges,
        private readonly AuditService $audit,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * A boarder reports a problem in the place they stay now.
     *
     * @param  array{category: string, description: string, urgency?: ?string}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function report(User $boarder, array $data, array $photos): MaintenanceRequest
    {
        $stay = Tenancy::current()->where('tenant_id', $boarder->id)->first();
        if (! $stay) {
            throw ValidationException::withMessages(['tenancy' => 'You can report a problem once you have moved in somewhere.']);
        }

        return $this->create($stay->property_id, $stay->room_id, $stay->id, $boarder, $data, $photos);
    }

    /**
     * Staff log a problem themselves, for a room or the whole place.
     *
     * @param  array{category: string, description: string, urgency?: ?string, room_id?: ?int}  $data
     * @param  list<UploadedFile>  $photos
     */
    public function log(Property $property, User $staff, array $data, array $photos): MaintenanceRequest
    {
        $roomId = $data['room_id'] ?? null;
        if ($roomId && ! $property->rooms()->whereKey($roomId)->exists()) {
            throw ValidationException::withMessages(['room_id' => 'Choose a room of this property.']);
        }

        return $this->create($property->id, $roomId, null, $staff, $data, $photos);
    }

    public function start(MaintenanceRequest $request, User $by): MaintenanceRequest
    {
        $this->expect($request, [MaintenanceStatus::Submitted], 'Only a new request can be started.');
        $request->update(['status' => MaintenanceStatus::InProgress, 'started_at' => now()]);
        $this->tellReporter($request, $by, 'started');

        return $this->history($request, $by, 'Started work.');
    }

    public function resolve(MaintenanceRequest $request, User $by, ?string $resolution): MaintenanceRequest
    {
        $this->expect($request, [MaintenanceStatus::Submitted, MaintenanceStatus::InProgress], 'This request is not open.');
        $request->update(['status' => MaintenanceStatus::Resolved, 'resolved_at' => now(), 'resolution' => $resolution]);
        $this->tellReporter($request, $by, 'resolved', $resolution);

        return $this->history($request, $by, 'Marked as fixed.'.($resolution ? " {$resolution}" : ''));
    }

    public function cancel(MaintenanceRequest $request, User $by, ?string $reason): MaintenanceRequest
    {
        $this->expect($request, [MaintenanceStatus::Submitted, MaintenanceStatus::InProgress], 'Only an open request can be cancelled.');
        $request->update(['status' => MaintenanceStatus::Cancelled, 'cancelled_at' => now(), 'cancelled_by' => $by->id, 'cancel_reason' => $reason]);
        if ($request->reported_by === $by->id) {
            $this->tellStaff($request, $by, 'withdrawn');
        } else {
            $this->tellReporter($request, $by, 'cancelled', $reason);
        }

        return $this->history($request, $by, 'Cancelled.'.($reason ? " {$reason}" : ''));
    }

    /** Assign to the owner or a caretaker of the property, or nobody. */
    public function assign(MaintenanceRequest $request, ?User $to, User $by): MaintenanceRequest
    {
        if ($to && ! PropertyAccess::can($to, PropertyAbility::HandleMaintenance, $request->property)) {
            throw ValidationException::withMessages(['user_id' => 'Choose the owner or a caretaker of this property.']);
        }
        if ($request->assigned_to === $to?->id) {
            return $request;
        }
        $request->update(['assigned_to' => $to?->id]);
        if ($to && $to->id !== $by->id) {
            $this->notifications->send($to, NotificationEvent::MaintenanceForStaff, $this->data($request, $by, 'assigned', $request->description));
        }

        return $this->history($request, $by, $to ? "Assigned to {$to->name}." : 'No longer assigned.');
    }

    /** The repair's cost, for the owner's records (charging the boarder is separate). */
    public function setCost(MaintenanceRequest $request, ?int $centavos, User $by): MaintenanceRequest
    {
        if ($request->repair_cost_centavos === $centavos) {
            return $request;
        }
        $request->update(['repair_cost_centavos' => $centavos]);

        return $this->history($request, $by, $centavos === null ? 'Repair cost removed.' : 'Repair cost: '.Money::format($centavos).'.');
    }

    /** The boarder confirms the fix: the request is closed. */
    public function confirmFixed(MaintenanceRequest $request, User $by): MaintenanceRequest
    {
        $this->expect($request, [MaintenanceStatus::Resolved], 'Only a fixed request can be confirmed.');
        $request->update(['status' => MaintenanceStatus::Closed, 'closed_at' => now()]);

        return $this->history($request, $by, 'Confirmed it is fixed.');
    }

    /** The boarder says it is still broken: back to In progress, and the 3-day wait starts again next time. */
    public function stillBroken(MaintenanceRequest $request, User $by, string $reason): MaintenanceRequest
    {
        $this->expect($request, [MaintenanceStatus::Resolved], 'Only a request marked as fixed can be reopened.');
        $request->update(['status' => MaintenanceStatus::InProgress, 'resolved_at' => null]);
        $this->tellStaff($request, $by, 'still_broken', $reason);

        return $this->history($request, $by, "Still broken: {$reason}");
    }

    /**
     * Close what was fixed 3 or more days before $day and never confirmed
     * (resolved on the 18th closes on the 21st).
     */
    public function closeResolved(CarbonImmutable $day): int
    {
        $cutoff = $day->subDays(self::AUTO_CLOSE_DAYS - 1)->startOfDay();
        $closed = 0;
        MaintenanceRequest::where('status', MaintenanceStatus::Resolved)->where('resolved_at', '<', $cutoff)->each(function (MaintenanceRequest $request) use (&$closed) {
            $request->update(['status' => MaintenanceStatus::Closed, 'closed_at' => now()]);
            $request->notes()->create(['user_id' => null, 'body' => 'Closed: nobody said it was still broken within '.self::AUTO_CLOSE_DAYS.' days.']);
            $closed++;
        });

        return $closed;
    }

    /**
     * A one-time charge to the boarder who reported it, for damage they
     * caused (never automatic; one per request). Uses the normal charge, so
     * the boarder is told and it shows in their bills.
     */
    public function charge(MaintenanceRequest $request, int $centavos, ?string $description, string $reason, ?string $dueOn, User $by): MaintenanceRequest
    {
        $tenancy = $request->tenancy;
        if (! $tenancy) {
            throw ValidationException::withMessages(['amount_centavos' => 'Only a request a boarder reported for their stay can be charged.']);
        }
        if ($request->charge_bill_id) {
            throw ValidationException::withMessages(['amount_centavos' => 'This repair was already charged. Correct that bill instead.']);
        }

        $bill = $this->charges->charge($tenancy, $centavos, $description ?: "Repair: {$request->category->label()}", $reason, $dueOn, $by);
        $request->update(['charge_bill_id' => $bill->id]);

        return $this->history($request, $by, 'Charged '.Money::format($centavos)." to {$tenancy->tenant->name} ({$bill->number}): {$reason}");
    }

    /**
     * Set the unit Not ready while it is being repaired, or ready again.
     * The unit: the one given (of the request's room), else the stay's.
     */
    public function setNotReady(MaintenanceRequest $request, bool $notReady, ?string $reason, ?int $unitId, User $by): MaintenanceRequest
    {
        $unit = RentableUnit::where('property_id', $request->property_id)
            ->when($request->room_id, fn ($q) => $q->where('room_id', $request->room_id))
            ->find($unitId ?? $request->unit_id ?? $request->tenancy?->unit_id);
        if (! $unit) {
            throw ValidationException::withMessages(['unit_id' => 'Choose the room or bedspace to mark.']);
        }

        $before = ['not_ready' => $unit->not_ready, 'not_ready_reason' => $unit->not_ready_reason];
        $unit->forceFill(['not_ready' => $notReady, 'not_ready_reason' => $notReady ? ($reason ?: "Repair: {$request->category->label()}") : null])->save();
        $request->update(['unit_id' => $unit->id]);

        $property = $request->property;
        $this->audit->record(AuditEvent::UnitNotReadyChanged, $unit,
            AuditDiff::between($before, ['not_ready' => $unit->not_ready, 'not_ready_reason' => $unit->not_ready_reason]),
            owner: $property->owner, actor: $by, note: "{$property->name} · {$unit->label} · maintenance request #{$request->id}");

        return $this->history($request, $by, $notReady ? "{$unit->label} set Not ready." : "{$unit->label} is ready again.");
    }

    public function addNote(MaintenanceRequest $request, User $by, string $body): MaintenanceRequest
    {
        if (in_array($request->status, [MaintenanceStatus::Closed, MaintenanceStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['body' => 'This request is closed.']);
        }
        if ($request->reported_by === $by->id) {
            $this->tellStaff($request, $by, 'note', $body);
        } else {
            $this->tellReporter($request, $by, 'note', $body);
        }

        return $this->history($request, $by, $body);
    }

    /** @param  list<UploadedFile>  $photos */
    private function create(int $propertyId, ?int $roomId, ?int $tenancyId, User $by, array $data, array $photos): MaintenanceRequest
    {
        $paths = array_map(fn (UploadedFile $p) => $this->files->store($p, FilePurpose::MaintenancePhoto), array_slice($photos, 0, MaintenanceRequest::MAX_PHOTOS));

        $request = DB::transaction(function () use ($propertyId, $roomId, $tenancyId, $by, $data, $paths) {
            $request = MaintenanceRequest::create([
                'property_id' => $propertyId,
                'room_id' => $roomId,
                'tenancy_id' => $tenancyId,
                'reported_by' => $by->id,
                'category' => MaintenanceCategory::from($data['category']),
                'description' => trim($data['description']),
                'urgency' => MaintenanceUrgency::from($data['urgency'] ?? MaintenanceUrgency::Normal->value),
                'status' => MaintenanceStatus::Submitted,
            ]);
            foreach ($paths as $path) {
                $request->photos()->create(['path' => $path]);
            }

            return $request;
        });

        // Urgent: in-app and email at once; otherwise in-app only.
        $event = $request->urgency === MaintenanceUrgency::Urgent ? NotificationEvent::MaintenanceUrgent : NotificationEvent::MaintenanceReported;
        $this->notifications->send($this->staffExcept($request, $by), $event, $this->data($request, $by) + [
            'reporter' => $by->name,
            'problem' => mb_strimwidth($request->description, 0, 160, '…'),
        ]);

        return $request;
    }

    /** The owner and every caretaker of the property, except whoever acted. */
    private function staffExcept(MaintenanceRequest $request, User $by): Collection
    {
        return $request->property->paymentConfirmers()->unique('id')->reject(fn (User $u) => $u->id === $by->id)->values();
    }

    /** @return array<string, mixed> */
    private function data(MaintenanceRequest $request, User $by, ?string $what = null, ?string $detail = null): array
    {
        $request->loadMissing('property', 'room');

        return [
            'property_id' => $request->property_id,
            'property_name' => $request->property->name,
            'where' => $request->room ? "room {$request->room->label()}" : 'the shared areas',
            'category' => $request->category->label(),
            'by' => $by->name,
            'what' => $what,
            'detail' => $detail,
        ];
    }

    private function tellReporter(MaintenanceRequest $request, User $by, string $what, ?string $detail = null): void
    {
        if ($request->reported_by !== $by->id && $request->reporter) {
            $this->notifications->send($request->reporter, NotificationEvent::MaintenanceUpdated, $this->data($request, $by, $what, $detail));
        }
    }

    /** The one assigned, or else every staff member (never whoever acted). */
    private function tellStaff(MaintenanceRequest $request, User $by, string $what, ?string $detail = null): void
    {
        $assignee = $request->assignee;
        $to = $assignee && $assignee->id !== $by->id ? collect([$assignee]) : $this->staffExcept($request, $by);
        $this->notifications->send($to, NotificationEvent::MaintenanceForStaff, $this->data($request, $by, $what, $detail));
    }

    /** @param  list<MaintenanceStatus>  $allowed */
    private function expect(MaintenanceRequest $request, array $allowed, string $message): void
    {
        if (! in_array($request->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function history(MaintenanceRequest $request, User $by, string $body): MaintenanceRequest
    {
        $request->notes()->create(['user_id' => $by->id, 'body' => mb_substr($body, 0, 1000)]);

        return $request;
    }
}
