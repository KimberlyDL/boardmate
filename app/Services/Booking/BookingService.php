<?php

namespace App\Services\Booking;

use App\Enums\ApplicationStatus as Status;
use App\Enums\AuditEvent;
use App\Enums\FilePurpose;
use App\Enums\NotificationEvent;
use App\Enums\UnitStatus;
use App\Models\BookingApplication;
use App\Models\Property;
use App\Models\PropertyCaretaker;
use App\Models\RentableUnit;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Files\Contracts\FileService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Services\Properties\UnitStatusService;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking flow (Features guide F2), up to the reservation:
 * apply → approve (reservation, unit Reserved) / decline → cancel or expire.
 *
 * - A boarder holds at most one reservation; approving one withdraws their
 *   other pending applications.
 * - Approving the last free unit declines the property's other pending
 *   applications ("fully booked").
 * - A reservation lasts until planned move-in + reservation_expiry_days.
 */
class BookingService
{
    public const MAX_PENDING = 5;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly FileService $files,
        private readonly UnitStatusService $unitStatus,
    ) {}

    /**
     * A property was deleted: its pending applications are declined and the
     * boarders told. (Reservations block deletion, so none are open.)
     */
    public function closeForDeletedProperty(Property $property, User $actor): int
    {
        return $this->declinePending([$property->id], $actor, 'This place is no longer listed.');
    }

    /**
     * An owner was suspended: nobody can review their applicants, so pending
     * applications on all their properties are declined (freeing the
     * boarders' application slots). Reservations stay until they expire.
     */
    public function closeForSuspendedOwner(User $owner, User $admin): int
    {
        $propertyIds = Property::withTrashed()->where('owner_id', $owner->id)->pluck('id')->all();

        return $this->declinePending($propertyIds, $admin, 'This place is not taking bookings right now.');
    }

    /** @param  list<int>  $propertyIds */
    private function declinePending(array $propertyIds, User $actor, string $reason): int
    {
        $pending = BookingApplication::with(['boarder', 'property' => fn ($q) => $q->withTrashed()])
            ->whereIn('property_id', $propertyIds)
            ->where('status', Status::Pending)
            ->get();

        $declined = 0;
        foreach ($pending as $application) {
            $stillPending = DB::transaction(function () use ($application, $actor, $reason) {
                $locked = BookingApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== Status::Pending) {
                    return false;
                }
                $this->close($application, Status::Declined, $actor, $reason);

                return true;
            });
            if (! $stillPending) {
                continue;
            }
            $declined++;
            $this->notifications->send($application->boarder, NotificationEvent::BookingDeclined, [
                'property_name' => $application->property->name,
                'reason' => $reason,
            ]);
        }

        return $declined;
    }

    /**
     * An account was suspended: their pending applications and reservation
     * are cancelled, reserved units freed, and the owners told.
     */
    public function closeForSuspendedBoarder(User $boarder, User $admin): int
    {
        $open = BookingApplication::with(['property.owner', 'unit'])
            ->where('boarder_id', $boarder->id)
            ->open()
            ->get();

        $closed = 0;
        foreach ($open as $application) {
            $stillOpen = DB::transaction(function () use ($application, $admin) {
                $locked = BookingApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
                if (! $locked->status->isOpen()) {
                    return false; // decided meanwhile
                }
                $this->releaseUnit($locked);
                $this->close($application, Status::Cancelled, $admin, 'The applicant\'s account was suspended.');

                return true;
            });
            if (! $stillOpen) {
                continue;
            }
            $closed++;

            $this->notifications->send($application->property->bookingApprovers(), NotificationEvent::BookingCancelled, [
                'property_name' => $application->property->name,
                'unit_label' => $application->unit?->label ?? 'a place',
                'reason' => 'The applicant\'s account was suspended by BoardMate.',
                'cancelled_by' => 'BoardMate',
                'action_url' => '/applications',
            ]);
            $this->audit->record(AuditEvent::BookingCancelled, $application, owner: $application->property->owner,
                reason: 'Account suspended', note: "{$boarder->name} → {$application->property->name}");
        }

        return $closed;
    }

    /** @param  array{planned_move_in_on: string, message?: string|null, contact_phone: string}  $data */
    public function apply(User $boarder, Property $property, array $data, ?UploadedFile $idDocument): BookingApplication
    {
        $this->assertCanApply($boarder, $property);

        $application = DB::transaction(function () use ($boarder, $property, $data, $idDocument) {
            $application = new BookingApplication($data);
            $application->property_id = $property->id;
            $application->boarder_id = $boarder->id;
            if ($idDocument) {
                $application->id_document_path = $this->files->store($idDocument, FilePurpose::IdDocument);
            }
            $application->save();

            return $application;
        });

        $this->notifications->send($property->bookingApprovers(), NotificationEvent::BookingReceived, [
            'boarder_name' => $boarder->name,
            'property_name' => $property->name,
            'move_in' => $application->planned_move_in_on->format('M j, Y'),
        ]);

        return $application;
    }

    public function approve(BookingApplication $application, int $unitId, User $actor): BookingApplication
    {
        [$application, $withdrawn, $fullyBooked] = DB::transaction(function () use ($application, $unitId, $actor) {
            // Always lock in the same order: boarder → property → application →
            // unit. Approvals for the same boarder or the same property queue up
            // instead of deadlocking, and a bed can never be reserved twice.
            $boarder = User::whereKey($application->boarder_id)->lockForUpdate()->first();
            $property = Property::withTrashed()->whereKey($application->property_id)->lockForUpdate()->first();
            $application = BookingApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $unit = RentableUnit::whereKey($unitId)->where('property_id', $application->property_id)->lockForUpdate()->first();

            if ($application->status !== Status::Pending) {
                throw ValidationException::withMessages(['application' => "This application is already {$application->status->label()}."]);
            }
            if ($property->trashed()) {
                throw ValidationException::withMessages(['application' => 'This property was deleted.']);
            }
            if ($boarder?->suspended_at !== null) {
                throw ValidationException::withMessages(['application' => 'This applicant\'s account is suspended.']);
            }
            if (! $unit) {
                throw ValidationException::withMessages(['unit_id' => 'Choose a unit of this property.']);
            }
            if (! $unit->isAvailable()) {
                throw ValidationException::withMessages(['unit_id' => "{$unit->label} is not available."]);
            }
            if (BookingApplication::where('boarder_id', $application->boarder_id)->where('status', Status::Approved)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['application' => 'This boarder already has a reservation elsewhere.']);
            }

            $settings = $application->property->settings;
            $today = ManilaDate::today();
            $start = $application->planned_move_in_on->greaterThan($today) ? $application->planned_move_in_on : $today;

            $application->forceFill([
                'status' => Status::Approved,
                'unit_id' => $unit->id,
                'decided_by' => $actor->id,
                'decided_at' => now(),
                'reserved_until' => $start->addDays($settings?->reservation_expiry_days ?? 7)->toDateString(),
            ])->save();

            $this->unitStatus->move($unit, UnitStatus::Reserved);

            // One reservation per boarder: withdraw their other pending applications.
            $withdrawn = BookingApplication::with('property')
                ->where('boarder_id', $application->boarder_id)
                ->where('status', Status::Pending)
                ->whereKeyNot($application->id)
                ->get();
            foreach ($withdrawn as $other) {
                $this->close($other, Status::Cancelled, null, 'You have a reservation at '.$application->property->name.'.');
            }

            // Last free unit taken: the property's other applicants are told now.
            $fullyBooked = collect();
            if (! $application->property->bookableUnits()->exists()) {
                $fullyBooked = BookingApplication::with('boarder')
                    ->where('property_id', $application->property_id)
                    ->where('status', Status::Pending)
                    ->get();
                foreach ($fullyBooked as $other) {
                    $this->close($other, Status::Declined, $actor, 'The place is now fully booked.');
                }
            }

            return [$application, $withdrawn, $fullyBooked];
        }, attempts: 3); // retried if the database still reports a deadlock

        $property = $application->property;
        $unit = $application->unit;

        $this->audit->record(AuditEvent::BookingApproved, $application, owner: $property->owner,
            note: "{$application->boarder->name} → {$property->name} · {$unit->label} until ".$application->reserved_until->format('M j, Y'));

        $this->notifications->send($application->boarder, NotificationEvent::BookingApproved, [
            'property_name' => $property->name,
            'unit_label' => $unit->label,
            'address' => $property->fullAddress(),
            'reserved_until' => $application->reserved_until->format('M j, Y'),
        ]);
        foreach ($withdrawn as $other) {
            $this->notifications->send($application->boarder, NotificationEvent::BookingAutoCancelled, [
                'property_name' => $other->property->name,
                'reserved_property' => $property->name,
            ]);
        }
        foreach ($fullyBooked as $other) {
            $this->notifications->send($other->boarder, NotificationEvent::BookingDeclined, [
                'property_name' => $property->name,
                'reason' => 'The place is now fully booked.',
            ]);
        }

        return $application;
    }

    public function decline(BookingApplication $application, ?string $reason, User $actor): BookingApplication
    {
        DB::transaction(function () use ($application, $reason, $actor) {
            $locked = BookingApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Status::Pending) {
                throw ValidationException::withMessages(['application' => "This application is already {$locked->status->label()}."]);
            }
            $this->close($application, Status::Declined, $actor, $reason);
        });

        $this->audit->record(AuditEvent::BookingDeclined, $application, owner: $application->property->owner,
            reason: $reason, note: "{$application->boarder->name} → {$application->property->name}");
        $this->notifications->send($application->boarder, NotificationEvent::BookingDeclined, [
            'property_name' => $application->property->name,
            'reason' => $reason,
        ]);

        return $application;
    }

    /** Boarder withdraws (pending or reserved), or owner/Manager cancels a reservation. */
    public function cancel(BookingApplication $application, User $actor, ?string $reason): BookingApplication
    {
        $byBoarder = $actor->id === $application->boarder_id;

        DB::transaction(function () use ($application, $actor, $reason, $byBoarder) {
            $locked = BookingApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();
            $allowed = $byBoarder ? $locked->status->isOpen() : $locked->status === Status::Approved;
            if (! $allowed) {
                throw ValidationException::withMessages(['application' => "This booking is already {$locked->status->label()}."]);
            }
            $this->releaseUnit($locked);
            $this->close($application, Status::Cancelled, $actor, $reason);
        });

        $property = $application->property;
        $data = [
            'property_name' => $property->name,
            'unit_label' => $application->unit?->label ?? 'a place',
            'reason' => $reason,
            'cancelled_by' => $byBoarder ? $application->boarder->name : $property->owner->ownerDisplayName(),
        ];

        if ($byBoarder) {
            $this->notifications->send($property->bookingApprovers(), NotificationEvent::BookingCancelled, $data + ['action_url' => '/applications']);
        } else {
            $this->notifications->send($application->boarder, NotificationEvent::BookingCancelled, $data + ['action_url' => '/boarder/bookings']);
        }
        $this->audit->record(AuditEvent::BookingCancelled, $application, owner: $property->owner, reason: $reason,
            note: "{$application->boarder->name} → {$property->name}".($application->unit ? " · {$application->unit->label}" : ''));

        return $application;
    }

    /**
     * Daily: reservations whose last day is before $day expire (unit back to
     * Available); boarders whose reservation ends tomorrow get a reminder.
     *
     * @return array{expired: int, reminded: int}
     */
    public function expireReservations(CarbonImmutable $day): array
    {
        $due = BookingApplication::with(['property.owner', 'unit', 'boarder'])
            ->where('status', Status::Approved)
            ->where('reserved_until', '<', $day->toDateString())
            ->get();

        foreach ($due as $application) {
            DB::transaction(function () use ($application) {
                $this->releaseUnit($application);
                $this->close($application, Status::Expired, null, 'No move-in by '.$application->reserved_until->format('M j, Y').'.');
            });

            $data = [
                'property_name' => $application->property->name,
                'unit_label' => $application->unit?->label ?? 'the unit',
                'boarder_name' => $application->boarder->name,
            ];
            $this->notifications->send($application->property->bookingApprovers()->push($application->boarder), NotificationEvent::ReservationExpired, $data);
            $this->audit->record(AuditEvent::ReservationExpired, $application, owner: $application->property->owner, actor: null,
                note: "{$data['boarder_name']} → {$data['property_name']} · {$data['unit_label']}");
        }

        $soon = BookingApplication::with(['property', 'unit', 'boarder'])
            ->where('status', Status::Approved)
            ->where('reserved_until', $day->addDay()->toDateString())
            ->get();
        foreach ($soon as $application) {
            $this->notifications->send($application->boarder, NotificationEvent::ReservationExpiringSoon, [
                'property_name' => $application->property->name,
                'unit_label' => $application->unit?->label ?? 'the unit',
                'reserved_until' => $application->reserved_until->format('M j, Y'),
            ]);
        }

        return ['expired' => $due->count(), 'reminded' => $soon->count()];
    }

    /** Daily: delete ID files of applications closed over 30 days ago (minimum personal data). */
    public function purgeClosedIdDocuments(CarbonImmutable $day): int
    {
        $old = BookingApplication::query()
            ->whereNotNull('id_document_path')
            ->whereNotIn('status', Status::openValues())
            ->where('closed_at', '<', $day->subDays(30)->startOfDay())
            ->get();

        foreach ($old as $application) {
            $this->files->delete($application->id_document_path, FilePurpose::IdDocument);
            $application->forceFill(['id_document_path' => null, 'id_document_purged_at' => now()])->save();
        }

        return $old->count();
    }

    private function assertCanApply(User $boarder, Property $property): void
    {
        $fail = fn (string $message, string $code) => throw new BookingRefused($message, $code);

        if ($property->owner_id === $boarder->id) {
            $fail('You cannot book your own property.', 'own_property');
        }
        if (PropertyCaretaker::where('property_id', $property->id)->where('caretaker_id', $boarder->id)->exists()) {
            $fail('You help run this property, so you cannot book it.', 'caretaker_property');
        }
        if (BookingApplication::where('boarder_id', $boarder->id)->where('status', Status::Approved)->exists()) {
            $fail('You already have a reservation. Cancel it first to apply somewhere else.', 'has_reservation');
        }
        if (BookingApplication::where('boarder_id', $boarder->id)->where('property_id', $property->id)->open()->exists()) {
            $fail('You already applied here. Wait for the owner, or cancel that application first.', 'already_applied');
        }
        if (BookingApplication::where('boarder_id', $boarder->id)->where('status', Status::Pending)->count() >= self::MAX_PENDING) {
            $fail('You can have up to '.self::MAX_PENDING.' applications waiting at a time.', 'too_many_pending');
        }
    }

    private function releaseUnit(BookingApplication $application): void
    {
        if ($application->status === Status::Approved && $application->unit_id) {
            $this->unitStatus->releaseReservation($application->unit_id);
        }
    }

    private function close(BookingApplication $application, Status $status, ?User $by, ?string $reason): void
    {
        $application->forceFill([
            'status' => $status,
            'closed_at' => now(),
            'closed_by' => $by?->id,
            'closed_reason' => $reason,
            'decided_by' => $status === Status::Declined ? $by?->id : $application->decided_by,
            'decided_at' => $status === Status::Declined ? now() : $application->decided_at,
        ])->save();
    }
}
