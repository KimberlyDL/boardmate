<?php

namespace App\Authorization;

use App\Enums\CaretakerAccessLevel;
use App\Enums\PropertyAbility as A;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\PropertyCaretaker;
use App\Models\User;

/**
 * Who may do what on a property: the single source for every permission check
 * (Billing guide, "Roles and permissions" and "Caretaker access levels").
 *
 * - Owner: everything on their own properties.
 * - Manager caretaker: everything the owner can on assigned properties,
 *   except managing caretakers, the owner's payment details, deleting a
 *   property, transferring ownership (and the owner's audit log).
 * - Collector caretaker: collection work only.
 *
 * can(User, PropertyAbility, Property) looks up whether the user owns the
 * property or is assigned to it (with that property's level), then allows().
 */
final class PropertyAccess
{
    /** Is the user allowed to do $ability on this property? */
    public static function can(User $user, A $ability, Property $property): bool
    {
        if ($user->isSuspended()) {
            return false;
        }

        if ($property->owner_id === $user->id) {
            return $user->hasAccountRole(UserRole::Owner) && self::allows(null, $ability);
        }

        $level = self::caretakerLevel($user, $property);

        return $level !== null && self::allows($level, $ability);
    }

    /** The user's access level on this property as a caretaker, or null if not assigned. */
    public static function caretakerLevel(User $user, Property $property): ?CaretakerAccessLevel
    {
        if (! $user->hasAccountRole(UserRole::Caretaker)) {
            return null;
        }

        // The owner–caretaker link must still be active, not just the assignment.
        $assignment = PropertyCaretaker::query()
            ->where('property_id', $property->id)
            ->where('caretaker_id', $user->id)
            ->whereExists(fn ($q) => $q->from('owner_caretakers')
                ->whereColumn('owner_caretakers.caretaker_id', 'property_caretakers.caretaker_id')
                ->where('owner_caretakers.owner_id', $property->owner_id)
                ->whereNull('owner_caretakers.removed_at'))
            ->first();

        return $assignment?->access_level;
    }

    /** How the user relates to the property: owner, manager, collector, or null. */
    public static function roleOn(User $user, Property $property): ?string
    {
        if ($property->owner_id === $user->id) {
            return 'owner';
        }

        return self::caretakerLevel($user, $property)?->value;
    }

    /** @var list<A> */
    public const COLLECTOR = [
        A::View, A::RecordPayments, A::ConfirmPaymentProofs, A::SendReminders,
        A::MarkLate, A::ApproveMoveOut, A::ViewArrears,
    ];

    /** @var list<A> */
    public const OWNER_ONLY = [
        A::ManageCaretakers, A::EditOwnerPaymentDetails, A::DeleteProperty,
        A::TransferOwnership, A::ViewAuditLog,
    ];

    /**
     * @param  CaretakerAccessLevel|null  $level  null means the property owner
     */
    public static function allows(?CaretakerAccessLevel $level, A $ability): bool
    {
        return match ($level) {
            null => true,
            CaretakerAccessLevel::Manager => ! in_array($ability, self::OWNER_ONLY, true),
            CaretakerAccessLevel::Collector => in_array($ability, self::COLLECTOR, true),
        };
    }

    /** @return list<A> */
    public static function abilitiesFor(?CaretakerAccessLevel $level): array
    {
        return array_values(array_filter(A::cases(), fn (A $a) => self::allows($level, $a)));
    }
}
