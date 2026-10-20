<?php

namespace App\Services\HouseOperations;

use App\Enums\NotificationEvent;
use App\Models\CurfewViolation;
use App\Models\LateEntryRequest;
use App\Models\PropertySettings;
use App\Models\Tenancy;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Notifications\Contracts\NotificationService;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The in-app messages of curfew and visitors (D2, D4). Staff (the owner and
 * every caretaker) hear about requests to decide; tenants hear the
 * decisions; going over the overnight limit reminds the tenant and tells
 * the owner. Nobody is told about their own action.
 */
class HouseOpsNotifier
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly VisitorService $visitors,
    ) {}

    public function lateEntryRequested(LateEntryRequest $r, User $by): void
    {
        $this->notifications->send($this->staffExcept($r->tenancy, $by), NotificationEvent::LateEntryRequested, $this->base($r->tenancy, 'staff') + [
            'night' => $this->day($r->on_date),
            'arrival' => $this->clock($r->expected_arrival),
            'reason' => $r->reason,
        ]);
    }

    public function lateEntryDecided(LateEntryRequest $r): void
    {
        $this->toTenant($r->tenancy, NotificationEvent::LateEntryDecided, [
            'what' => $r->status->value,
            'night' => $this->day($r->on_date),
            'arrival' => $this->clock($r->expected_arrival),
            'reason' => $r->decline_reason,
        ]);
    }

    public function overnightRequested(Visitor $v, User $by): void
    {
        $this->notifications->send($this->staffExcept($v->tenancy, $by), NotificationEvent::OvernightRequested, $this->base($v->tenancy, 'staff') + [
            'visitor_name' => $v->name,
            'nights' => $v->nights,
            'night' => $this->day($v->visit_on),
            'over_limit' => $v->over_limit,
        ]);
    }

    public function visitorDecided(Visitor $v): void
    {
        $this->toTenant($v->tenancy, NotificationEvent::VisitorDecided, [
            'what' => $v->status->value,
            'visitor_name' => $v->name,
            'night' => $this->day($v->visit_on),
            'reason' => $v->decline_reason,
        ]);
    }

    /** Over the monthly limit (once it counts): a reminder to the tenant, a notice to the owner. */
    public function overLimit(Visitor $v): void
    {
        $tenancy = $v->tenancy->loadMissing('property.owner', 'property.settings');
        $settings = $tenancy->property->settings ?? new PropertySettings(PropertySettings::DEFAULTS);
        $data = [
            'visitor_name' => $v->name,
            'used' => $this->visitors->nightsUsed($tenancy, $v->visit_on),
            'limit' => (int) $settings->overnight_limit_per_month,
            'fee' => $settings->extra_occupant_fee_enabled && $settings->extra_occupant_fee_centavos ? Money::format((int) $settings->extra_occupant_fee_centavos) : null,
        ];
        $this->toTenant($tenancy, NotificationEvent::OvernightOverLimit, $data);
        $this->notifications->send($tenancy->property->owner, NotificationEvent::OvernightOverLimit, $this->base($tenancy, 'owner') + $data);
    }

    public function violationLogged(CurfewViolation $v): void
    {
        $this->toTenant($v->tenancy, NotificationEvent::CurfewViolationLogged, [
            'night' => $this->day($v->on_date),
            'arrival' => $v->arrived_at ? $this->clock($v->arrived_at) : null,
        ]);
    }

    private function toTenant(Tenancy $tenancy, NotificationEvent $event, array $data): void
    {
        $tenancy->loadMissing('tenant');
        if ($tenancy->tenant) {
            $this->notifications->send($tenancy->tenant, $event, $this->base($tenancy, 'tenant') + $data);
        }
    }

    /** @return array<string, mixed> */
    private function base(Tenancy $tenancy, string $audience): array
    {
        $tenancy->loadMissing('property', 'tenant', 'room');

        return [
            'audience' => $audience,
            'property_id' => $tenancy->property_id,
            'property_name' => $tenancy->property->name,
            'tenant_name' => $tenancy->tenant?->name,
            'room_label' => $tenancy->room?->label(),
        ];
    }

    private function staffExcept(Tenancy $tenancy, User $by)
    {
        return $tenancy->property->paymentConfirmers()->unique('id')->reject(fn (User $u) => $u->id === $by->id)->values();
    }

    private function day(CarbonImmutable $date): string
    {
        return $date->format('D, M j');
    }

    private function clock(string $time): string
    {
        return CarbonImmutable::createFromFormat('H:i', substr($time, 0, 5))->format('g:i A');
    }
}
