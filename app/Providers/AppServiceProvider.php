<?php

namespace App\Providers;

use App\Models\Property;
use App\Models\RentableUnit;
use App\Models\UtilityAccount;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Login, sign-up and password reset: 5 tries a minute per email + IP,
        // and 20 a minute per IP overall.
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(20)->by($request->ip()),
        ]);

        // Short, stable names in polymorphic columns (price_rules.priceable_type, audit subjects).
        Relation::morphMap([
            'property' => Property::class,
            'rentable_unit' => RentableUnit::class,
            'utility_account' => UtilityAccount::class,
        ]);

        // Sliding expiry: a token dies after N days WITHOUT use. Sanctum
        // updates last_used_at on every request, so active users stay in.
        Sanctum::authenticateAccessTokensUsing(
            fn (PersonalAccessToken $token, bool $isValid) => $isValid
                && ($token->last_used_at ?? $token->created_at)->greaterThan(now()->subDays(config('boardmate.token_idle_days')))
        );
    }
}
