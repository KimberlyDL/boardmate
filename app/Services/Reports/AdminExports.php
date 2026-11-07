<?php

namespace App\Services\Reports;

use App\Enums\OwnerVerificationStatus;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Dashboard\PlatformStats;
use App\Support\ManilaDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * CSV exports for the platform admin (improvements Phase 5): the admin
 * Overview's figures and the lists behind them. Counts and contact emails
 * only: no money (the admin Overview never shows money) and no phone numbers.
 * Dates are YYYY-MM-DD (Manila), yes/no as "yes" / "no".
 */
class AdminExports
{
    public function __construct(private readonly PlatformStats $stats) {}

    /** @return array<string, array{label: string, description: string}> */
    public function all(): array
    {
        return [
            'summary' => ['label' => 'Platform summary', 'description' => 'The Overview figures today, and sign-ups, applications, approvals and move-ins for each of the last 12 months.'],
            'owners' => ['label' => 'Owners', 'description' => 'Every owner: email, review status and dates, and how many properties, listings and places they have.'],
            'properties' => ['label' => 'Properties', 'description' => 'Every property: owner, type, province and city, listed or not, and its places, how many are taken and free.'],
            'accounts' => ['label' => 'Accounts', 'description' => 'Every account (admins left out): email, roles, verified email, suspended, joined.'],
        ];
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /** @return list<string> */
    public function headers(string $key): array
    {
        return match ($key) {
            'summary' => ['Section', 'Figure', 'Value'],
            'owners' => ['Owner ID', 'Name', 'Email', 'Business name', 'Review status', 'Applied on', 'Reviewed on', 'Suspended', 'Properties', 'On the Finder', 'Places', 'Joined on'],
            'properties' => ['Property ID', 'Name', 'Owner', 'Owner email', 'Type', 'Province', 'City or municipality', 'Barangay', 'Listed on the Finder', 'Published on', 'Rooms', 'Places', 'Taken', 'Free to book', 'Created on'],
            'accounts' => ['Account ID', 'Name', 'Email', 'Roles', 'Email verified', 'Suspended', 'Joined on'],
        };
    }

    /** @return iterable<list<string|int|null>> */
    public function rows(string $key): iterable
    {
        return match ($key) {
            'summary' => $this->summary(),
            'owners' => $this->owners(),
            'properties' => $this->properties(),
            'accounts' => $this->accounts(),
        };
    }

    /** @return iterable<list<string|int>> */
    private function summary(): iterable
    {
        $s = $this->stats->get();
        $labels = [
            'accounts' => ['total' => 'Accounts', 'new_this_month' => 'New this month', 'suspended' => 'Suspended', 'boarders' => 'Boarders', 'owners' => 'Owners', 'caretakers' => 'Caretakers'],
            'listings' => ['on_finder' => 'On the Finder', 'with_free_places' => 'With a free place', 'not_published' => 'Not published', 'places' => 'Places on the Finder', 'free_places' => 'Free places on the Finder'],
            'stays' => ['current' => 'Current stays', 'applications_waiting' => 'Applications waiting'],
        ];
        foreach ($labels as $section => $figures) {
            foreach ($figures as $field => $label) {
                yield [ucfirst($section), $label, (int) $s[$section][$field]];
            }
        }
        foreach ($s['owners'] as $status => $count) {
            yield ['Owners by review status', OwnerVerificationStatus::from($status)->label(), (int) $count];
        }
        yield ['Campuses', 'Campuses', (int) $s['campuses']];
        foreach ($s['months'] as $m) {
            yield ["Month {$m['month']}", 'Sign-ups', $m['sign_ups']];
            yield ["Month {$m['month']}", 'Applications', $m['applications']];
            yield ["Month {$m['month']}", 'Approved', $m['approved']];
            yield ["Month {$m['month']}", 'Move-ins', $m['move_ins']];
        }
    }

    /** @return iterable<list<string|int>> */
    private function owners(): iterable
    {
        $owners = User::query()->role(UserRole::Owner->value)->with('ownerProfile')->orderBy('id');
        $properties = Property::query()->selectRaw('owner_id, count(*) as total')->groupBy('owner_id')->pluck('total', 'owner_id');
        $listed = Property::query()->onFinder()->selectRaw('owner_id, count(*) as total')->groupBy('owner_id')->pluck('total', 'owner_id');

        foreach ($owners->lazyById() as $owner) {
            $profile = $owner->ownerProfile;
            yield [
                $owner->id,
                $owner->name,
                $owner->email,
                $profile?->business_name ?? '',
                $profile?->verification_status?->label() ?? '',
                $this->day($profile?->submitted_at),
                $this->day($profile?->reviewed_at),
                $this->yes($owner->suspended_at !== null),
                (int) ($properties[$owner->id] ?? 0),
                (int) ($listed[$owner->id] ?? 0),
                RentableUnit::query()->places()->whereIn('property_id', Property::where('owner_id', $owner->id)->select('id'))->count(),
                $this->day($owner->created_at),
            ];
        }
    }

    /** @return iterable<list<string|int>> */
    private function properties(): iterable
    {
        $onFinder = Property::query()->onFinder()->pluck('id')->flip();
        $properties = Property::query()
            ->with('owner:id,name,email')
            ->withCount([
                'rooms',
                'units as places_count' => fn (Builder $q) => $q->places(),
                'units as free_count' => fn (Builder $q) => $q->places()->free(),
            ])
            ->orderBy('id');

        foreach ($properties->lazyById() as $p) {
            yield [
                $p->id,
                $p->name,
                $p->owner?->name ?? '',
                $p->owner?->email ?? '',
                $p->type?->label() ?? '',
                $p->province ?? '',
                $p->city ?? '',
                $p->barangay ?? '',
                $this->yes($onFinder->has($p->id)),
                $this->day($p->published_at),
                $p->rooms_count,
                $p->places_count,
                Tenancy::query()->current()->where('property_id', $p->id)->count(),
                $p->free_count,
                $this->day($p->created_at),
            ];
        }
    }

    /** @return iterable<list<string|int>> */
    private function accounts(): iterable
    {
        $people = User::query()
            ->whereDoesntHave('roles', fn (Builder $r) => $r->where('name', UserRole::PlatformAdmin->value))
            ->with('roles')
            ->orderBy('id');

        foreach ($people->lazyById() as $user) {
            yield [
                $user->id,
                $user->name,
                $user->email,
                collect($user->accountRoles())->map(fn (UserRole $r) => $r->label())->join(', '),
                $this->yes($user->email_verified_at !== null),
                $this->yes($user->suspended_at !== null),
                $this->day($user->created_at),
            ];
        }
    }

    private function day(?CarbonInterface $date): string
    {
        return $date ? $date->copy()->setTimezone(ManilaDate::TIMEZONE)->toDateString() : '';
    }

    private function yes(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
