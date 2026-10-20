<?php

namespace App\Services\Properties;

use App\Enums\HouseRuleAcceptanceMethod;
use App\Models\BookingApplication;
use App\Models\HouseRuleAcceptance;
use App\Models\HouseRuleVersion;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Accepting house rules (D3): when applying, at move-in, and each later
 * version during the stay. A property without rules needs no acceptance.
 */
class HouseRuleAcceptances
{
    public function current(Property $property): ?HouseRuleVersion
    {
        return $property->houseRuleVersions()->published()->orderByDesc('version')->first();
    }

    /**
     * Applying: the boarder must accept the version in force, sent as
     * `$version`. Nothing to accept if the place has no rules.
     */
    public function requireForApplication(Property $property, ?int $version): ?HouseRuleVersion
    {
        $current = $this->current($property);
        if ($current && $version !== $current->version) {
            throw ValidationException::withMessages(['house_rules_version' => $version === null
                ? 'Read and accept the house rules first.'
                : "The house rules changed. Read version {$current->version} and accept it."]);
        }

        return $current;
    }

    public function recordForApplication(HouseRuleVersion $version, BookingApplication $application): void
    {
        HouseRuleAcceptance::create([
            'version_id' => $version->id,
            'user_id' => $application->boarder_id,
            'booking_application_id' => $application->id,
            'method' => HouseRuleAcceptanceMethod::App,
            'accepted_at' => now(),
        ]);
        $application->forceFill(['house_rules_version' => $version->version])->save();
    }

    /**
     * Moving in (inside the move-in transaction): the acceptance given when
     * applying carries over if it is for the version in force; otherwise
     * staff must confirm it was given in person.
     */
    public function recordAtMoveIn(Tenancy $tenancy, ?BookingApplication $application, bool $inPerson, User $actor): void
    {
        $current = $this->current($tenancy->property);
        if (! $current) {
            return;
        }

        $given = $application
            ? HouseRuleAcceptance::where('booking_application_id', $application->id)->where('version_id', $current->id)->first()
            : null;
        if (! $given && ! $inPerson) {
            $name = $tenancy->tenant->name;
            throw ValidationException::withMessages(['house_rules_accepted_in_person' => $application
                ? "{$name} accepted older house rules when applying. Confirm they accepted version {$current->version} in person."
                : "Confirm {$name} accepted the house rules (version {$current->version}) in person."]);
        }

        $acceptance = HouseRuleAcceptance::create([
            'version_id' => $current->id,
            'user_id' => $tenancy->tenant_id,
            'tenancy_id' => $tenancy->id,
            'booking_application_id' => $application?->id,
            'method' => $given ? HouseRuleAcceptanceMethod::App : HouseRuleAcceptanceMethod::InPerson,
            'recorded_by' => $given ? null : $actor->id,
            'accepted_at' => $given?->accepted_at ?? now(),
        ]);
        $tenancy->forceFill(['house_rules_accepted_at' => $acceptance->accepted_at])->save();
    }

    /** The tenant acknowledges the version in force (sent as `$version`); doing it twice is harmless. */
    public function acknowledge(Tenancy $tenancy, int $version): HouseRuleAcceptance
    {
        $current = $this->current($tenancy->property);
        if (! $current || $current->version !== $version) {
            throw ValidationException::withMessages(['version' => $current
                ? "The house rules in force are version {$current->version}. Read them and accept that one."
                : 'This place has no house rules to accept.']);
        }

        return HouseRuleAcceptance::firstOrCreate(
            ['tenancy_id' => $tenancy->id, 'version_id' => $current->id],
            ['user_id' => $tenancy->tenant_id, 'method' => HouseRuleAcceptanceMethod::App, 'accepted_at' => now()],
        );
    }

    /** Has the tenant accepted the version in force? True when there is none. */
    public function isUpToDate(Tenancy $tenancy, ?HouseRuleVersion $current): bool
    {
        return ! $current || $tenancy->houseRuleAcceptances()->where('version_id', $current->id)->exists();
    }
}
