<?php

namespace App\Services\Groups;

use App\Enums\BillKind;
use App\Enums\GroupExpenseKind;
use App\Enums\NotificationEvent;
use App\Enums\SplitMethod;
use App\Enums\UnitKind;
use App\Models\Bill;
use App\Models\ContributionGroup;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Models\Room;
use App\Services\Notifications\Contracts\NotificationService;
use App\Support\ManilaDate;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owner bills the room's leader receives, posted to the room's group as
 * locked lines (D5): the amount is the bill's and never changes here; only
 * the split does. Shares are owed to the member who was billed (the leader
 * at the time; when the leader changes, earlier lines stay theirs).
 *
 * Which bills: a room's utility-share bills; in a room rented whole also the
 * monthly bills and the final bill. One-time charges are not posted. Bills
 * issued before the group existed are left out.
 *
 * Posting runs when a bill is issued or corrected, and again whenever the
 * group is read, so nothing is missed.
 */
class OwnerBillPoster
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly GroupSplitter $splitter,
        private readonly GroupLedger $ledger,
        private readonly NotificationService $notifications,
    ) {}

    /** After a bill is issued or corrected. */
    public function forBill(Bill $bill): void
    {
        if (! $bill->room_id || ! $this->isPostable($bill)) {
            return;
        }
        $room = Room::withTrashed()->with('property.settings')->find($bill->room_id);
        $this->post($this->groups->forRoom($room), $bill);
    }

    /** Every postable bill of the room, posted or brought up to date. */
    public function sync(ContributionGroup $group): void
    {
        Bill::where('room_id', $group->room_id)
            ->whereDate('issued_on', '>=', $group->created_at->timezone('Asia/Manila')->toDateString())
            ->whereIn('kind', [BillKind::Utility->value, BillKind::Periodic->value, BillKind::Final->value])
            ->with(['lines', 'tenancy.unit', 'utilityBill'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Bill $b) => $this->isPostable($b))
            ->each(fn (Bill $b) => $this->post($group, $b));
    }

    /**
     * The leader or a moderator changes how a line is split. Only until a
     * repayment to the one who paid it has been confirmed; after that,
     * corrections are separate lines.
     *
     * @param  array<int|string, int|string>  $params
     */
    public function resplit(GroupExpense $line, SplitMethod $method, array $params): GroupExpense
    {
        if ($this->repaidSince($line)) {
            throw ValidationException::withMessages(['method' => 'Someone already paid back for this. Add a correction instead of changing the split.']);
        }

        DB::transaction(function () use ($line, $method, $params) {
            $line->update(['split_method' => $method, 'split_params' => $params ?: null]);
            $this->writeShares($line, $this->sharesFor($line, $method, $params));
        });

        return $line->load('shares.member');
    }

    private function post(ContributionGroup $group, Bill $bill): void
    {
        $payer = GroupMember::where('group_id', $group->id)->where('user_id', $bill->payer_id)->orderByRaw('left_on is not null')->first();
        if (! $payer) {
            return; // billed to someone outside the group
        }
        // Read fresh: a correction may have just added a line the caller's copy does not have.
        $total = (int) $bill->lines()->sum('amount_centavos');
        $line = GroupExpense::where('group_id', $group->id)->where('bill_id', $bill->id)->first();

        if (! $line) {
            $line = DB::transaction(function () use ($group, $bill, $payer, $total) {
                $line = GroupExpense::create([
                    'group_id' => $group->id,
                    'kind' => GroupExpenseKind::OwnerBill,
                    'bill_id' => $bill->id,
                    'paid_by_member_id' => $payer->id,
                    'description' => $this->describe($bill),
                    'amount_centavos' => $total,
                    'spent_on' => $bill->issued_on->toDateString(),
                    'split_method' => $group->default_split_method,
                ]);
                $this->writeShares($line, $this->sharesFor($line, $group->default_split_method, []));

                return $line;
            });
            $this->announce($line);

            return;
        }

        // The owner corrected the bill: follow the new total.
        $posted = $line->amount_centavos + (int) GroupExpense::where('adjusts_expense_id', $line->id)->sum('amount_centavos');
        if ($posted === $total) {
            return;
        }
        DB::transaction(function () use ($line, $total, $posted, $bill) {
            if (! $this->repaidSince($line)) {
                $line->update(['amount_centavos' => $total - ($posted - $line->amount_centavos)]);
                $this->writeShares($line, $this->sharesFor($line, $line->split_method, $line->split_params ?? []));

                return;
            }
            $fix = GroupExpense::create([
                'group_id' => $line->group_id,
                'kind' => GroupExpenseKind::Adjustment,
                'adjusts_expense_id' => $line->id,
                'paid_by_member_id' => $line->paid_by_member_id,
                'description' => "Correction: {$line->description}",
                'amount_centavos' => $total - $posted,
                'spent_on' => ManilaDate::today()->toDateString(),
                'split_method' => $line->split_method,
                'split_params' => $line->split_params,
                'reason' => "The owner corrected bill {$bill->number}",
            ]);
            $this->writeShares($fix, $this->sharesFor($fix, $line->split_method, $line->split_params ?? [], $line));
        });
    }

    /** @return array<int, int> member id => centavos */
    private function sharesFor(GroupExpense $line, SplitMethod $method, array $params, ?GroupExpense $datesFrom = null): array
    {
        [$from, $to] = $this->dates($datesFrom ?? $line);
        $members = GroupMember::where('group_id', $line->group_id)->get();

        return $this->splitter->split($line->amount_centavos, $method, $from, $to, $members, $line->paid_by_member_id, $params);
    }

    /** @param  array<int, int>  $shares */
    private function writeShares(GroupExpense $line, array $shares): void
    {
        $this->ledger->writeShares($line, $shares);
    }

    /** The dates a line covers: the bill's period, a utility bill's coverage, or the day it was spent. */
    private function dates(GroupExpense $line): array
    {
        $bill = $line->bill;
        if ($bill?->kind === BillKind::Utility && $bill->utilityBill) {
            return [CarbonImmutable::parse($bill->utilityBill->coverage_from_on), CarbonImmutable::parse($bill->utilityBill->coverage_to_on)];
        }
        if ($bill?->period_start_on) {
            return [$bill->period_start_on, $bill->period_end_on];
        }

        return [$line->spent_on, $line->spent_on];
    }

    private function repaidSince(GroupExpense $line): bool
    {
        return $this->ledger->repaidSince($line);
    }

    private function isPostable(Bill $bill): bool
    {
        return match ($bill->kind) {
            BillKind::Utility => true,
            BillKind::Periodic, BillKind::Final => $bill->tenancy?->unit?->kind === UnitKind::Whole,
            default => false,
        };
    }

    private function describe(Bill $bill): string
    {
        $span = $bill->period_start_on ? ', '.$bill->period_start_on->format('M j').' – '.$bill->period_end_on->format('M j, Y') : '';
        if ($bill->kind === BillKind::Utility && $bill->utilityBill) {
            $span = ', '.CarbonImmutable::parse($bill->utilityBill->coverage_from_on)->format('M j').' – '.CarbonImmutable::parse($bill->utilityBill->coverage_to_on)->format('M j, Y');
        }

        return "{$bill->kind->label()} {$bill->number}{$span}";
    }

    /** In-app only: each member with an account (other than the one who paid) hears their share. */
    private function announce(GroupExpense $line): void
    {
        $line->load('shares.member.user', 'paidBy', 'group.room');
        foreach ($line->shares as $share) {
            $user = $share->member->user;
            if (! $user || $share->member_id === $line->paid_by_member_id || ! $share->member->isCurrent()) {
                continue;
            }
            $this->notifications->send($user, NotificationEvent::GroupLinePosted, [
                'group_id' => $line->group_id,
                'room_label' => $line->group->room->label(),
                'description' => $line->description,
                'share' => Money::format($share->amount_centavos),
                'owed_to' => $line->paidBy->name,
            ]);
        }
    }
}
