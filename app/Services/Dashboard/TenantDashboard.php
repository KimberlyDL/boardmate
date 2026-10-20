<?php

namespace App\Services\Dashboard;

use App\Enums\GroupRole;
use App\Enums\LateEntryStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\NoticeStatus;
use App\Enums\TenancyStatus;
use App\Enums\VisitorStatus;
use App\Models\Bill;
use App\Models\GroupMember;
use App\Models\LateEntryRequest;
use App\Models\MaintenanceRequest;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Billing\PaymentLedger;
use App\Services\Groups\GroupBalances;
use App\Services\Properties\HouseRuleAcceptances;
use App\Support\ManilaDate;

/**
 * The tenant's home (A10): their stay, what they owe, their requests, their
 * room group and what needs them, all from the same rows as the rest of
 * the app. Leaders also see who in the room owes them.
 */
class TenantDashboard
{
    public function __construct(
        private readonly PaymentLedger $ledger,
        private readonly GroupBalances $balances,
        private readonly HouseRuleAcceptances $houseRules,
    ) {}

    /** @return array<string, mixed> */
    public function for(User $user): array
    {
        $stay = Tenancy::current()->where('tenant_id', $user->id)->with(['property', 'room', 'unit'])->first();

        return [
            'stay' => $stay ? $this->stay($stay) : null,
            'money' => $stay ? $this->money($stay) : null,
            'requests' => $stay ? $this->requests($stay, $user) : null,
            'group' => $stay ? $this->group($stay, $user) : null,
            'house_rules_to_accept' => $stay && ! $this->houseRules->isUpToDate($stay, $this->houseRules->current($stay->property)),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function stay(Tenancy $stay): array
    {
        $notice = $stay->status === TenancyStatus::Active
            ? $stay->notices()->where('status', NoticeStatus::Pending)->latest('id')->first()
            : null;

        return [
            'id' => $stay->id,
            'property' => ['id' => $stay->property_id, 'name' => $stay->property->name],
            'room_label' => $stay->room?->label(),
            'unit_label' => $stay->unit?->label,
            'emergency_numbers' => $stay->property->emergency_numbers,
            // Leaving: the last day once approved, or the one asked for while it waits.
            'leaving' => $stay->status === TenancyStatus::NoticeGiven
                ? ['end_on' => $stay->end_on?->toDateString(), 'approved' => true]
                : ($notice ? ['end_on' => $notice->end_on->toDateString(), 'approved' => false] : null),
        ];
    }

    /** @return array<string, mixed> */
    private function money(Tenancy $stay): array
    {
        $summary = $this->ledger->summary($stay->property_id, $stay->tenant_id);
        $unpaid = Bill::where('property_id', $stay->property_id)->where('payer_id', $stay->tenant_id)
            ->withBalance()->unpaid()->orderBy('due_on')->orderBy('id')->get();
        $today = ManilaDate::today();
        $next = $unpaid->first();

        return [
            'owed_centavos' => $summary['owed_centavos'],
            'credit_centavos' => $summary['credit_centavos'],
            'overdue_centavos' => (int) $unpaid->filter(fn (Bill $b) => $b->due_on->lt($today))->sum(fn (Bill $b) => $b->balanceCentavos()),
            'next_bill' => $next ? [
                'id' => $next->id,
                'number' => $next->number,
                'due_on' => $next->due_on->toDateString(),
                'balance_centavos' => $next->balanceCentavos(),
            ] : null,
        ];
    }

    /** @return array<string, int> */
    private function requests(Tenancy $stay, User $user): array
    {
        return [
            'repairs_open' => MaintenanceRequest::where('reported_by', $user->id)->where('property_id', $stay->property_id)
                ->whereIn('status', MaintenanceStatus::openValues())->count(),
            'repairs_to_confirm' => MaintenanceRequest::where('reported_by', $user->id)->where('status', MaintenanceStatus::Resolved)->count(),
            'late_entries_waiting' => LateEntryRequest::where('tenancy_id', $stay->id)->where('status', LateEntryStatus::Pending)
                ->whereDate('on_date', '>=', ManilaDate::today()->toDateString())->count(),
            'visitors_waiting' => Visitor::where('tenancy_id', $stay->id)->where('status', VisitorStatus::Pending)->count(),
        ];
    }

    /**
     * My room group (D5): what I owe and am owed. The leader also gets who
     * owes them, largest first.
     *
     * @return array<string, mixed>|null
     */
    private function group(Tenancy $stay, User $user): ?array
    {
        $me = GroupMember::where('user_id', $user->id)->whereNull('left_on')
            ->whereHas('group', fn ($q) => $q->where('room_id', $stay->room_id))->with('group')->first();
        if (! $me) {
            return null;
        }

        $pairs = collect($this->balances->pairs($me->group));
        $owingMe = $pairs->where('to', $me->id);
        $names = GroupMember::whereIn('id', $owingMe->pluck('from'))->pluck('name', 'id');

        return [
            'id' => $me->group_id,
            'owe_centavos' => (int) $pairs->where('from', $me->id)->sum('amount_centavos'),
            'owed_to_me_centavos' => (int) $owingMe->sum('amount_centavos'),
            'is_leader' => $me->role === GroupRole::Admin,
            'members_owing_me' => $me->role === GroupRole::Admin
                ? $owingMe->sortByDesc('amount_centavos')->map(fn ($p) => ['name' => $names[$p['from']] ?? null, 'amount_centavos' => (int) $p['amount_centavos']])->values()->all()
                : null,
        ];
    }
}
