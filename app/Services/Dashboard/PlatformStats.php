<?php

namespace App\Services\Dashboard;

use App\Enums\ApplicationStatus;
use App\Enums\OwnerVerificationStatus;
use App\Enums\UserRole;
use App\Models\BookingApplication;
use App\Models\Campus;
use App\Models\OwnerProfile;
use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\Tenancy;
use App\Models\User;
use App\Support\ManilaDate;
use Illuminate\Database\Eloquent\Builder;

/**
 * The platform admin's numbers (A11): accounts, owners by review status,
 * listings, stays and month-by-month activity. Counts only: the admin never
 * sees anyone's bills, payments or income (A2).
 */
class PlatformStats
{
    /** @return array<string, mixed> */
    public function get(int $months = 12): array
    {
        $today = ManilaDate::today();
        $monthStart = $today->startOfMonth();
        $from = $monthStart->subMonths($months - 1);
        $people = fn () => User::query()->whereDoesntHave('roles', fn (Builder $r) => $r->where('name', UserRole::PlatformAdmin->value));
        $owners = OwnerProfile::query()->selectRaw('verification_status, count(*) as n')->groupBy('verification_status')->pluck('n', 'verification_status');
        $onFinder = Property::query()->onFinder();

        $byMonth = fn (Builder $query, string $column) => $query->where($column, '>=', $from->toDateString())
            ->selectRaw("to_char({$column}, 'YYYY-MM') as m, count(*) as n")->groupByRaw("to_char({$column}, 'YYYY-MM')")
            ->pluck('n', 'm');
        $signups = $byMonth($people(), 'created_at');
        $applications = $byMonth(BookingApplication::query(), 'created_at');
        $approved = $byMonth(BookingApplication::query()->whereNotNull('decided_at')->where('status', '!=', ApplicationStatus::Declined), 'decided_at');
        $moveIns = $byMonth(Tenancy::query(), 'moved_in_on');

        return [
            'accounts' => [
                'total' => $people()->count(),
                'new_this_month' => $people()->where('created_at', '>=', $monthStart->toDateString())->count(),
                'suspended' => $people()->whereNotNull('suspended_at')->count(),
                'boarders' => $people()->role(UserRole::Boarder->value)->count(),
                'owners' => $people()->role(UserRole::Owner->value)->count(),
                'caretakers' => $people()->role(UserRole::Caretaker->value)->count(),
            ],
            'owners' => collect(OwnerVerificationStatus::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($owners[$s->value] ?? 0)])->all(),
            'listings' => [
                'on_finder' => (clone $onFinder)->count(),
                'with_free_places' => Property::query()->listable()->count(),
                'not_published' => Property::query()->where('is_published', false)->count(),
                'places' => RentableUnit::query()->places()->whereIn('property_id', (clone $onFinder)->select('id'))->count(),
                'free_places' => RentableUnit::query()->places()->free()->whereIn('property_id', (clone $onFinder)->select('id'))->count(),
            ],
            'stays' => [
                'current' => Tenancy::query()->current()->count(),
                'applications_waiting' => BookingApplication::query()->where('status', ApplicationStatus::Pending)->count(),
            ],
            'campuses' => Campus::query()->count(),
            'months' => collect(range(0, $months - 1))->map(function (int $i) use ($from, $signups, $applications, $approved, $moveIns) {
                $m = $from->addMonths($i)->format('Y-m');

                return [
                    'month' => $m,
                    'sign_ups' => (int) ($signups[$m] ?? 0),
                    'applications' => (int) ($applications[$m] ?? 0),
                    'approved' => (int) ($approved[$m] ?? 0),
                    'move_ins' => (int) ($moveIns[$m] ?? 0),
                ];
            })->all(),
        ];
    }
}
