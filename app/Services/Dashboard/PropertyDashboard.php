<?php

namespace App\Services\Dashboard;

use App\Authorization\PropertyAccess;
use App\Enums\ApplicationStatus;
use App\Enums\BillKind;
use App\Enums\LateEntryStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceUrgency;
use App\Enums\PaymentStatus;
use App\Enums\PropertyAbility as A;
use App\Enums\UnitStatus;
use App\Enums\VisitorStatus;
use App\Models\Bill;
use App\Models\BookingApplication;
use App\Models\LateEntryRequest;
use App\Models\MaintenanceRequest;
use App\Models\Payment;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Billing\CollectionService;
use App\Services\HouseOperations\CurfewService;
use App\Support\ManilaDate;

/**
 * The owner's and caretakers' dashboard for one property (A10). Every number
 * is computed from the same rows as the rest of the app on each request;
 * nothing is stored, so it can never differ from the ledger. A widget is
 * left out (null) when the viewer may not see it.
 */
class PropertyDashboard
{
    public function __construct(
        private readonly CollectionService $collections,
        private readonly CurfewService $curfew,
    ) {}

    /** @return array<string, mixed> */
    public function for(Property $property, User $viewer): array
    {
        $can = fn (A $ability) => PropertyAccess::can($viewer, $ability, $property);
        $today = ManilaDate::today();

        return [
            'property' => ['id' => $property->id, 'name' => $property->name],
            'occupancy' => $this->occupancy($property),
            'due_this_week' => $can(A::ViewArrears) ? $this->dueThisWeek($property) : null,
            'overdue' => $can(A::ViewArrears) ? $this->overdue($property) : null,
            'collected_this_month' => $can(A::ViewCollections) ? (int) Payment::where('property_id', $property->id)
                ->where('status', PaymentStatus::Confirmed)
                ->whereBetween('paid_on', [$today->startOfMonth()->toDateString(), $today->endOfMonth()->toDateString()])
                ->sum('amount_centavos') : null,
            'applications_waiting' => $can(A::ApproveBookings)
                ? BookingApplication::where('property_id', $property->id)->where('status', ApplicationStatus::Pending)->count() : null,
            'proofs_waiting' => $can(A::ConfirmPaymentProofs)
                ? Payment::where('property_id', $property->id)->where('status', PaymentStatus::Pending)->count() : null,
            'repairs' => $can(A::HandleMaintenance) ? [
                'open' => MaintenanceRequest::where('property_id', $property->id)->whereIn('status', MaintenanceStatus::openValues())->count(),
                'urgent' => MaintenanceRequest::where('property_id', $property->id)->whereIn('status', MaintenanceStatus::openValues())
                    ->where('urgency', MaintenanceUrgency::Urgent)->count(),
            ] : null,
            'house_operations' => $can(A::HandleHouseOperations) ? [
                'visitors_today' => Visitor::where('property_id', $property->id)
                    ->where(fn ($q) => $q->whereDate('visit_on', $today->toDateString())->orWhere('status', VisitorStatus::CheckedIn))
                    ->whereNotIn('status', [VisitorStatus::Declined, VisitorStatus::Cancelled])->count(),
                'overnight_waiting' => Visitor::where('property_id', $property->id)->where('status', VisitorStatus::Pending)->count(),
                'late_entries_waiting' => LateEntryRequest::where('property_id', $property->id)->where('status', LateEntryStatus::Pending)
                    ->whereDate('on_date', '>=', $today->toDateString())->count(),
                'repeated_late' => count($this->curfew->repeated($property)),
            ] : null,
            'unpaid_shared_bills' => $can(A::ViewArrears) ? $this->unpaidSharedBills($property) : null,
        ];
    }

    /** @return array{rooms: int, places: int, occupied: int, reserved: int, available: int, not_ready: int} */
    private function occupancy(Property $property): array
    {
        // Places: bedspaces and whole rooms; a bedspace room's whole-room option is not an extra place.
        $units = RentableUnit::where('property_id', $property->id)->where('is_whole_option', false)->get(['status', 'not_ready']);
        $occupied = [UnitStatus::Occupied, UnitStatus::Leaving, UnitStatus::Overstaying];

        return [
            'rooms' => $property->rooms()->count(),
            'places' => $units->count(),
            'occupied' => $units->filter(fn ($u) => in_array($u->status, $occupied, true))->count(),
            'reserved' => $units->where('status', UnitStatus::Reserved)->count(),
            'available' => $units->filter(fn ($u) => $u->status === UnitStatus::Available && ! $u->not_ready)->count(),
            'not_ready' => $units->filter(fn ($u) => $u->status === UnitStatus::Available && $u->not_ready)->count(),
        ];
    }

    /** @return array{bills: int, centavos: int} unpaid bills due today or in the next 6 days */
    private function dueThisWeek(Property $property): array
    {
        $today = ManilaDate::today();
        $bills = Bill::where('property_id', $property->id)->withBalance()->unpaid()
            ->whereBetween('due_on', [$today->toDateString(), $today->addDays(6)->toDateString()])->get();

        return ['bills' => $bills->count(), 'centavos' => (int) $bills->sum(fn (Bill $b) => $b->balanceCentavos())];
    }

    /** @return array{payers: int, centavos: int} the same figures as the overdue list (C7) */
    private function overdue(Property $property): array
    {
        $rows = $this->collections->overdue($property);

        return ['payers' => count($rows), 'centavos' => (int) array_sum(array_column($rows, 'overdue_centavos'))];
    }

    /**
     * Rooms whose shared utility bills (billed to the room's leader) still
     * have a balance: the room, who was billed, and how much.
     *
     * @return list<array{room: array{id: int, label: string}, billed_to: string|null, centavos: int, overdue: bool}>
     */
    private function unpaidSharedBills(Property $property): array
    {
        $today = ManilaDate::today();

        return Bill::where('property_id', $property->id)->where('kind', BillKind::Utility)->whereNotNull('room_id')
            ->withBalance()->unpaid()->with(['room', 'payer:id,name'])->orderBy('due_on')->get()
            ->groupBy('room_id')
            ->map(fn ($bills) => [
                'room' => ['id' => $bills->first()->room->id, 'label' => $bills->first()->room->label()],
                'billed_to' => $bills->first()->payer?->name,
                'centavos' => (int) $bills->sum(fn (Bill $b) => $b->balanceCentavos()),
                'overdue' => $bills->contains(fn (Bill $b) => $b->due_on->lt($today)),
            ])
            ->sortByDesc('centavos')->values()->all();
    }
}
