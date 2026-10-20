<?php

namespace App\Services\HouseOperations;

use App\Enums\NotificationEvent;
use App\Models\CurfewViolation;
use App\Models\Property;
use App\Models\PropertySettings;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Notifications\Contracts\NotificationService;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Curfew (D2): the violation log and the reminder before curfew. Both only
 * where the property has a curfew. No fee is ever added by the app.
 */
class CurfewService
{
    /** A violation can be logged up to this many days after the night. */
    public const LOG_DAYS_BACK = 7;

    public function __construct(
        private readonly LateEntryService $lateEntries,
        private readonly NotificationService $notifications,
    ) {}

    /** @param  array{tenancy_id: int, on_date: string, arrived_at?: ?string, note?: ?string}  $data */
    public function logViolation(Property $property, array $data, User $by): CurfewViolation
    {
        if (! $property->settings?->curfew_time) {
            throw ValidationException::withMessages(['on_date' => 'This place has no curfew.']);
        }
        $tenancy = Tenancy::current()->where('property_id', $property->id)->find($data['tenancy_id']);
        if (! $tenancy) {
            throw ValidationException::withMessages(['tenancy_id' => 'Choose someone who stays here now.']);
        }
        $today = ManilaDate::today();
        $on = CarbonImmutable::parse($data['on_date'], 'Asia/Manila')->startOfDay();
        if ($on->gt($today) || $on->lt($today->subDays(self::LOG_DAYS_BACK))) {
            throw ValidationException::withMessages(['on_date' => 'Choose a night in the last '.self::LOG_DAYS_BACK.' days.']);
        }
        if ($this->lateEntries->isApproved($tenancy, $on)) {
            throw ValidationException::withMessages(['on_date' => 'Coming back late that night was approved, so it is not a violation.']);
        }

        return CurfewViolation::create([
            'property_id' => $property->id,
            'tenancy_id' => $tenancy->id,
            'on_date' => $on->toDateString(),
            'arrived_at' => $data['arrived_at'] ?? null,
            'note' => isset($data['note']) ? trim($data['note']) : null,
            'logged_by' => $by->id,
        ]);
    }

    /**
     * Stays with REPEAT_COUNT or more violations in the last REPEAT_DAYS days.
     *
     * @return array<int, int> tenancy id => count
     */
    public function repeated(Property $property): array
    {
        return CurfewViolation::where('property_id', $property->id)
            ->whereDate('on_date', '>=', ManilaDate::today()->subDays(CurfewViolation::REPEAT_DAYS - 1)->toDateString())
            ->whereIn('tenancy_id', Tenancy::current()->where('property_id', $property->id)->select('id'))
            ->selectRaw('tenancy_id, count(*) as nights')
            ->groupBy('tenancy_id')->havingRaw('count(*) >= ?', [CurfewViolation::REPEAT_COUNT])
            ->pluck('nights', 'tenancy_id')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * Send the reminder before curfew at every property whose reminder time
     * has come and whose curfew has not passed, once per night. Tenants who
     * muted it, or whose late entry that night was approved, are skipped.
     * Returns how many reminders were sent.
     */
    public function sendReminders(CarbonImmutable $now): int
    {
        $sent = 0;
        PropertySettings::whereNotNull('curfew_time')->with('property')->each(function (PropertySettings $settings) use ($now, &$sent) {
            $property = $settings->property;
            if (! $property || $property->trashed()) {
                return;
            }
            $curfew = $this->nextCurfew($settings, $now);
            if (! $curfew) {
                return;
            }
            $claimed = DB::table('curfew_reminder_runs')->insertOrIgnore([
                'property_id' => $property->id, 'curfew_on' => $curfew->toDateString(), 'sent_at' => now(),
            ]);
            if ($claimed === 0) {
                return; // already sent for this night
            }

            $nightOf = $curfew->hour < 12 ? $curfew->subDay() : $curfew; // a 00:30 curfew belongs to the evening before
            $tenancies = Tenancy::current()->where('property_id', $property->id)->where('curfew_reminders_muted', false)->with('tenant')->get()
                ->reject(fn (Tenancy $t) => $this->lateEntries->isApproved($t, $nightOf->startOfDay()));
            $this->notifications->send($tenancies->pluck('tenant')->filter()->values(), NotificationEvent::CurfewReminder, [
                'property_name' => $property->name,
                'curfew' => $curfew->format('g:i A'),
                'minutes' => (int) $settings->curfew_reminder_minutes,
            ]);
            $sent += $tenancies->count();
        });

        return $sent;
    }

    /** The curfew (today's or tonight's after midnight) whose reminder window [curfew − minutes, curfew) holds $now. */
    private function nextCurfew(PropertySettings $settings, CarbonImmutable $now): ?CarbonImmutable
    {
        $now = $now->setTimezone('Asia/Manila');
        [$h, $m] = array_map('intval', explode(':', substr((string) $settings->curfew_time, 0, 5)));
        $minutes = max(1, (int) $settings->curfew_reminder_minutes);
        foreach ([$now->startOfDay(), $now->startOfDay()->addDay()] as $day) {
            $curfew = $day->setTime($h, $m);
            if ($now->gte($curfew->subMinutes($minutes)) && $now->lt($curfew)) {
                return $curfew;
            }
        }

        return null;
    }
}
