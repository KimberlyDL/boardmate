<?php

namespace App\Services\Tenancies;

use App\Enums\ApplicationStatus;
use App\Enums\AuditEvent;
use App\Enums\DepositRule;
use App\Enums\DiscountKind;
use App\Enums\NotificationEvent;
use App\Enums\RentalMode;
use App\Enums\TenancyPaymentKind;
use App\Enums\UnitKind;
use App\Enums\UnitStatus;
use App\Models\BookingApplication;
use App\Models\Property;
use App\Models\PropertySettings;
use App\Models\RentableUnit;
use App\Models\Room;
use App\Models\RoomLeader;
use App\Models\RoomOccupant;
use App\Models\Tenancy;
use App\Models\TenancyDiscount;
use App\Models\TenancyPayment;
use App\Models\User;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use App\Services\BillingCalendar\BillingCalendar;
use App\Services\Booking\BookingService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Pricing\PriceBook;
use App\Services\Properties\UnitStatusService;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tenancy lifecycle, up to move-in (System Design C1): a reservation (or a
 * walk-in) becomes a tenancy, the unit becomes Occupied, and the deposit and
 * the advance rent for the first period are recorded.
 *
 * Money rules (confirmed with the owner):
 * - The rent used is the unit's price on the move-in day minus any discount.
 * - The deposit (default rule: one month's rent) is that discounted rent.
 * - The advance rent is the discounted rent only: no utilities.
 * - With "activation required" on, both must be recorded in full before
 *   moving in, unless the owner or a Manager overrides with a reason.
 *
 * Move-in takes the same locks, in the same order, as booking approval:
 * boarder, property, application, unit.
 *
 * Move-in data (`$data`): moved_in_on, emergency_contact{name,relationship,phone},
 * discount{kind,value}, deposit{amount_centavos,method,received_on,reference},
 * first_rent{...same}, override_reason, leader_consent, make_leader, occupants[].
 */
class TenancyService
{
    /** A move-in may be recorded up to this many days after it happened. */
    public const MAX_DAYS_BACK = 30;

    public function __construct(
        private readonly AuditService $audit,
        private readonly UnitStatusService $unitStatus,
        private readonly RoomLeaderService $leaders,
        private readonly BookingService $bookings,
        private readonly PriceBook $prices,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * What a move-in on a day would cost, for the form and for the checks.
     *
     * @param  array{kind: string, value: int}|null  $discount
     * @return array{rent_centavos: int, discount_centavos: int, rent_after_discount_centavos: int, deposit_centavos: int, first_rent_centavos: int, activation_required: bool}
     *
     * @throws ValidationException when the unit has no rent or the discount is not valid
     */
    public function quote(RentableUnit $unit, CarbonImmutable $movedInOn, ?array $discount, ?PropertySettings $settings): array
    {
        $settings ??= new PropertySettings;
        $rent = $this->prices->amountOn($unit, $movedInOn);
        if ($rent === null) {
            throw ValidationException::withMessages(['unit_id' => "Set the rent for {$unit->label} first."]);
        }

        $off = 0;
        if ($discount !== null) {
            $kind = DiscountKind::from($discount['kind']);
            $value = (int) $discount['value'];
            if ($kind === DiscountKind::Percent && $value > 10000) {
                throw ValidationException::withMessages(['discount.value' => 'A discount cannot be more than 100%.']);
            }
            if ($kind === DiscountKind::Fixed && $value > $rent) {
                throw ValidationException::withMessages(['discount.value' => 'A discount cannot be more than the rent.']);
            }
            $off = (new TenancyDiscount(['kind' => $kind, 'value' => $value]))->amountOff($rent);
        }

        $after = $rent - $off;
        $deposit = match ($settings->deposit_rule) {
            DepositRule::OneMonthRent => $after,
            DepositRule::FixedAmount => (int) $settings->deposit_fixed_centavos,
            DepositRule::None => 0,
        };

        return [
            'rent_centavos' => $rent,
            'discount_centavos' => $off,
            'rent_after_discount_centavos' => $after,
            'deposit_centavos' => $deposit,
            'first_rent_centavos' => $after,
            'activation_required' => (bool) $settings->activation_required,
        ];
    }

    /**
     * Move a boarder in from their reservation.
     *
     * @param  array<string, mixed>  $data
     */
    public function moveInFromReservation(BookingApplication $application, array $data, User $actor): Tenancy
    {
        [$tenancy, $override, $withdrawn] = DB::transaction(function () use ($application, $data, $actor) {
            $tenant = User::whereKey($application->boarder_id)->lockForUpdate()->firstOrFail();
            $property = Property::withTrashed()->whereKey($application->property_id)->lockForUpdate()->firstOrFail();
            $locked = BookingApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $unit = RentableUnit::whereKey($locked->unit_id)->lockForUpdate()->first();

            if ($locked->status !== ApplicationStatus::Approved || ! $unit) {
                throw ValidationException::withMessages(['application' => 'Only a reservation can be moved in.']);
            }
            if ($locked->reserved_until && $locked->reserved_until->lt(ManilaDate::today())) {
                throw ValidationException::withMessages(['application' => 'This reservation has expired.']);
            }
            if ($unit->status !== UnitStatus::Reserved) {
                throw ValidationException::withMessages(['application' => "{$unit->label} is not reserved any more."]);
            }

            [$tenancy, $override] = $this->start($property, $unit, $tenant, $locked, $data, $actor);

            $locked->forceFill(['status' => ApplicationStatus::MovedIn, 'moved_in_at' => now()])->save();
            $withdrawn = $this->bookings->withdrawPendingFor($tenant, $locked->id, "You moved into {$property->name}.");

            return [$tenancy, $override, $withdrawn];
        }, attempts: 3);

        $this->recordMoveIn($tenancy, $override, $actor, $withdrawn);

        return $tenancy;
    }

    /**
     * Move someone in who never applied: the owner or a Manager picks an
     * available unit and the tenant's account.
     *
     * @param  array<string, mixed>  $data
     */
    public function moveInWalkIn(Property $property, User $tenant, int $unitId, array $data, User $actor): Tenancy
    {
        [$tenancy, $override, $withdrawn] = DB::transaction(function () use ($property, $tenant, $unitId, $data, $actor) {
            $tenant = User::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $property = Property::withTrashed()->whereKey($property->id)->lockForUpdate()->firstOrFail();
            $unit = RentableUnit::whereKey($unitId)->where('property_id', $property->id)->lockForUpdate()->first();

            if ($property->trashed()) {
                throw ValidationException::withMessages(['unit_id' => 'This property was deleted.']);
            }
            if (! $unit) {
                throw ValidationException::withMessages(['unit_id' => 'Choose a unit of this property.']);
            }
            if (! $unit->isAvailable()) {
                throw ValidationException::withMessages(['unit_id' => "{$unit->label} is not available."]);
            }
            $reservation = BookingApplication::with('property')->where('boarder_id', $tenant->id)->where('status', ApplicationStatus::Approved)->first();
            if ($reservation) {
                throw ValidationException::withMessages(['email' => "{$tenant->name} has a reservation at {$reservation->property->name}. Move them in from it, or cancel it first."]);
            }

            [$tenancy, $override] = $this->start($property, $unit, $tenant, null, $data, $actor);
            $withdrawn = $this->bookings->withdrawPendingFor($tenant, null, "You moved into {$property->name}.");

            return [$tenancy, $override, $withdrawn];
        }, attempts: 3);

        $this->recordMoveIn($tenancy, $override, $actor, $withdrawn);

        return $tenancy;
    }

    /**
     * @param  array{name: string, relationship?: string|null, phone: string}  $contact
     */
    public function updateEmergencyContact(Tenancy $tenancy, array $contact, User $actor): Tenancy
    {
        $before = $tenancy->only(['emergency_contact_name', 'emergency_contact_relationship', 'emergency_contact_phone']);
        $tenancy->update([
            'emergency_contact_name' => $contact['name'],
            'emergency_contact_relationship' => $contact['relationship'] ?? null,
            'emergency_contact_phone' => $contact['phone'],
        ]);

        // The leader's own row on the occupant list carries the same contact.
        RoomOccupant::where('tenancy_id', $tenancy->id)->update([
            'emergency_contact_name' => $tenancy->emergency_contact_name,
            'emergency_contact_relationship' => $tenancy->emergency_contact_relationship,
            'emergency_contact_phone' => $tenancy->emergency_contact_phone,
        ]);

        $tenancy->loadMissing('property.owner', 'tenant');
        $changes = AuditDiff::between($before, $tenancy->only(array_keys($before)));
        if ($changes !== []) {
            $this->audit->record(AuditEvent::EmergencyContactChanged, $tenancy, $changes, owner: $tenancy->property->owner,
                actor: $actor, note: "{$tenancy->property->name} · {$tenancy->tenant->name}");
        }

        return $tenancy;
    }

    /**
     * The shared part of both move-ins; runs inside the caller's transaction
     * with the tenant, property and unit already locked.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: Tenancy, 1: bool} the tenancy, and whether the activation requirement was overridden
     */
    private function start(Property $property, RentableUnit $unit, User $tenant, ?BookingApplication $application, array $data, User $actor): array
    {
        if ($tenant->isSuspended()) {
            throw ValidationException::withMessages(['tenant' => 'This account is suspended.']);
        }
        if (Tenancy::current()->where('tenant_id', $tenant->id)->exists()) {
            throw ValidationException::withMessages(['tenant' => "{$tenant->name} already has a tenancy. End it before moving them in somewhere else."]);
        }

        $today = ManilaDate::today();
        $movedInOn = ManilaDate::parse($data['moved_in_on'])->startOfDay();
        if ($movedInOn->gt($today)) {
            throw ValidationException::withMessages(['moved_in_on' => 'The move-in date cannot be in the future. The reservation holds the place until then.']);
        }
        if ($movedInOn->lt($today->subDays(self::MAX_DAYS_BACK))) {
            throw ValidationException::withMessages(['moved_in_on' => 'The move-in date can be at most '.self::MAX_DAYS_BACK.' days ago.']);
        }

        $room = Room::whereKey($unit->room_id)->lockForUpdate()->firstOrFail();
        $settings = $property->settings ?? new PropertySettings;
        $quote = $this->quote($unit, $movedInOn, $data['discount'] ?? null, $settings);

        $becomesLeader = $room->rental_mode === RentalMode::Whole
            ? true
            : (bool) ($data['make_leader'] ?? $application?->leader_on_move_in ?? false);
        if ($becomesLeader && empty($data['leader_consent'])) {
            throw ValidationException::withMessages(['leader_consent' => 'Confirm that this person agreed to be the room\'s leader.']);
        }
        if ($becomesLeader && $room->rental_mode === RentalMode::Bedspaces && $room->leader()->exists()) {
            throw ValidationException::withMessages(['make_leader' => 'This room already has a leader.']);
        }
        $occupants = $this->occupantsFor($unit, $application, $data);

        $deposit = $data['deposit'] ?? null;
        $firstRent = $data['first_rent'] ?? null;
        $overridden = $this->checkActivation($quote, $deposit, $firstRent, $data['override_reason'] ?? null);

        $tenancy = Tenancy::create([
            'property_id' => $property->id,
            'room_id' => $room->id,
            'unit_id' => $unit->id,
            'tenant_id' => $tenant->id,
            'booking_application_id' => $application?->id,
            'moved_in_on' => $movedInOn->toDateString(),
            'anchor_day' => BillingCalendar::anchorDay($movedInOn),
            'emergency_contact_name' => $data['emergency_contact']['name'],
            'emergency_contact_relationship' => $data['emergency_contact']['relationship'] ?? null,
            'emergency_contact_phone' => $data['emergency_contact']['phone'],
            'moved_in_by' => $actor->id,
            'activation_override_reason' => $overridden ? $data['override_reason'] : null,
            'activation_overridden_by' => $overridden ? $actor->id : null,
        ]);

        if (isset($data['discount'])) {
            $tenancy->discounts()->create([
                'kind' => $data['discount']['kind'],
                'value' => $data['discount']['value'],
                'effective_from' => $movedInOn->toDateString(),
                'set_by' => $actor->id,
            ]);
        }
        foreach ([TenancyPaymentKind::Deposit->value => $deposit, TenancyPaymentKind::FirstRent->value => $firstRent] as $kind => $payment) {
            if ($payment !== null && (int) $payment['amount_centavos'] > 0) {
                $this->recordPayment($tenancy, TenancyPaymentKind::from($kind), $payment, $movedInOn, $actor);
            }
        }

        $this->unitStatus->move($unit, UnitStatus::Occupied);

        if ($becomesLeader) {
            $this->leaders->appoint($room, $tenant, $actor, true, notify: false);
        }
        if ($room->rental_mode === RentalMode::Whole) {
            $this->listOccupants($tenancy, $tenant, $occupants, $actor);
        }

        return [$tenancy, $overridden];
    }

    /**
     * The other people who will stay in a room rented whole: those sent with
     * the move-in, or the ones planned at approval. Each needs an emergency
     * contact by now, and with the tenant they must fit the unit.
     *
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function occupantsFor(RentableUnit $unit, ?BookingApplication $application, array $data): array
    {
        $occupants = array_values($data['occupants'] ?? $application?->planned_occupants ?? []);

        if ($unit->kind === UnitKind::Bedspace) {
            if ($occupants !== []) {
                throw ValidationException::withMessages(['occupants' => 'People who will stay are listed only for rooms rented whole.']);
            }

            return [];
        }

        if (count($occupants) + 1 > (int) $unit->capacity) {
            throw ValidationException::withMessages(['occupants' => "{$unit->label} fits {$unit->capacity} people, counting the tenant."]);
        }
        $errors = [];
        foreach ($occupants as $i => $person) {
            if (empty($person['emergency_contact_name'])) {
                $errors["occupants.{$i}.emergency_contact_name"] = "Add an emergency contact for {$person['name']}.";
            }
            if (empty($person['emergency_contact_phone'])) {
                $errors["occupants.{$i}.emergency_contact_phone"] = "Add an emergency contact number for {$person['name']}.";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $occupants;
    }

    /**
     * Is the deposit and first rent recorded in full? If not, and activation
     * is required, the owner or a Manager must give a reason.
     *
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>|null  $deposit
     * @param  array<string, mixed>|null  $firstRent
     * @return bool true when the requirement was overridden
     */
    private function checkActivation(array $quote, ?array $deposit, ?array $firstRent, ?string $reason): bool
    {
        if (! $quote['activation_required']) {
            return false;
        }

        $missing = [];
        if ((int) ($deposit['amount_centavos'] ?? 0) < $quote['deposit_centavos']) {
            $missing[] = 'the deposit of ₱'.number_format($quote['deposit_centavos'] / 100, 2);
        }
        if ((int) ($firstRent['amount_centavos'] ?? 0) < $quote['first_rent_centavos']) {
            $missing[] = 'the first rent of ₱'.number_format($quote['first_rent_centavos'] / 100, 2);
        }
        if ($missing === []) {
            return false;
        }
        if (mb_strlen(trim((string) $reason)) < 3) {
            throw ValidationException::withMessages([
                'activation' => 'Record '.implode(' and ', $missing).' before moving in, or give a reason for moving in without it.',
            ]);
        }

        return true;
    }

    /** @param  array<string, mixed>  $payment */
    private function recordPayment(Tenancy $tenancy, TenancyPaymentKind $kind, array $payment, CarbonImmutable $movedInOn, User $actor): TenancyPayment
    {
        $receivedOn = ManilaDate::parse($payment['received_on'] ?? $movedInOn->toDateString())->startOfDay();
        if ($receivedOn->gt(ManilaDate::today())) {
            throw ValidationException::withMessages([$kind->value.'.received_on' => 'The date received cannot be in the future.']);
        }

        return $tenancy->payments()->create([
            'kind' => $kind,
            'amount_centavos' => (int) $payment['amount_centavos'],
            'method' => $payment['method'],
            'received_on' => $receivedOn->toDateString(),
            'reference' => $payment['reference'] ?? null,
            'received_by' => $actor->id,
        ]);
    }

    /**
     * The occupant list of a room rented whole: the tenant (who is also the
     * leader) first, then everyone planned. They all joined on move-in day.
     *
     * @param  list<array<string, mixed>>  $occupants
     */
    private function listOccupants(Tenancy $tenancy, User $tenant, array $occupants, User $actor): void
    {
        $joined = $tenancy->moved_in_on->toDateString();

        RoomOccupant::create([
            'room_id' => $tenancy->room_id,
            'tenancy_id' => $tenancy->id,
            'user_id' => $tenant->id,
            'name' => $tenant->name,
            'contact_phone' => $tenant->phone,
            'joined_on' => $joined,
            'emergency_contact_name' => $tenancy->emergency_contact_name,
            'emergency_contact_relationship' => $tenancy->emergency_contact_relationship,
            'emergency_contact_phone' => $tenancy->emergency_contact_phone,
            'added_by' => $actor->id,
        ]);

        foreach ($occupants as $person) {
            RoomOccupant::create([
                'room_id' => $tenancy->room_id,
                'name' => $person['name'],
                'contact_phone' => $person['contact_phone'] ?? null,
                'joined_on' => $joined,
                'emergency_contact_name' => $person['emergency_contact_name'],
                'emergency_contact_relationship' => $person['emergency_contact_relationship'] ?? null,
                'emergency_contact_phone' => $person['emergency_contact_phone'],
                'added_by' => $actor->id,
            ]);
        }
    }

    /**
     * After the move-in is saved: the audit trail, and the messages to the tenant.
     *
     * @param  Collection<int, BookingApplication>  $withdrawn  the tenant's other applications that were withdrawn
     */
    private function recordMoveIn(Tenancy $tenancy, bool $overridden, User $actor, Collection $withdrawn): void
    {
        $tenancy->load('property.owner', 'tenant', 'unit');
        $note = "{$tenancy->tenant->name} → {$tenancy->property->name} · {$tenancy->unit->label} from ".$tenancy->moved_in_on->format('M j, Y');

        $this->audit->record(AuditEvent::TenancyStarted, $tenancy, owner: $tenancy->property->owner, actor: $actor,
            note: $note.($withdrawn->isNotEmpty() ? ' · '.$withdrawn->count().' other application(s) withdrawn' : ''));

        if ($overridden) {
            $this->audit->record(AuditEvent::ActivationOverridden, $tenancy, owner: $tenancy->property->owner, actor: $actor,
                reason: $tenancy->activation_override_reason, note: $note);
        }

        $this->notifications->send($tenancy->tenant, NotificationEvent::TenancyStarted, [
            'property_name' => $tenancy->property->name,
            'unit_label' => $tenancy->unit->label,
            'moved_in_on' => $tenancy->moved_in_on->format('M j, Y'),
            'anchor_day' => $tenancy->anchor_day,
            'next_due_on' => $tenancy->nextRentDueOn(ManilaDate::today())->format('M j, Y'),
            'is_leader' => RoomLeader::current()->where('room_id', $tenancy->room_id)->where('user_id', $tenancy->tenant_id)->exists(),
        ]);
        foreach ($withdrawn as $application) {
            $this->notifications->send($tenancy->tenant, NotificationEvent::ApplicationWithdrawnOnMoveIn, [
                'property_name' => $application->property->name,
                'moved_property' => $tenancy->property->name,
            ]);
        }
    }
}
