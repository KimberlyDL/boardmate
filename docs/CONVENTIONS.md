# BoardMate conventions

Agreed before the first domain migration (Features Guide §6). Specs: *BoardMate System Features Guide* and *BoardMate Billing and Tenancy Design: Revised Subsystem Guide*.

## Database

| Item | Rule | Example |
| --- | --- | --- |
| Tables | snake_case, plural | `rentable_units`, `utility_accounts` |
| Pivot tables | both singular names, alphabetical | `property_user` (or a named pivot such as `property_caretakers` when it has its own data) |
| Foreign keys | `<singular>_id`, constrained | `property_id` |
| Money | `bigint` integer centavos, suffix `_centavos`, never float or decimal | `base_rent_centavos` |
| Dates | `date` columns end in `_on` | `move_in_on`, `end_on` |
| Timestamps | `timestamp` columns end in `_at` | `verified_at`, `consented_at` |
| Booleans | `is_` / `has_` prefix | `is_published` |
| Status | `string` column holding a backed-enum value | `status = 'available'` |
| Deletes | soft deletes on anything with money attached; never hard-delete tenancies, bills or payments | `deleted_at` |
| Writes with money | wrapped in `DB::transaction` | bill generation, payment allocation |

## Time

- App timezone is `Asia/Manila` (`config/app.php`); use `App\Support\ManilaDate`.
- End dates (move-out, notices) last until 23:59:59 of that day: `ManilaDate::endOfDay()` / `hasEnded()`.
- Scheduled jobs run at 09:00 Asia/Manila.

## Money

- Use `App\Support\Money`: `toCentavos()` for input, `format()` for display (`₱1,333.33`).
- Splits always go through the Split module (centavo round-down, leftovers to the largest remainders).

## Enums

- PHP string-backed enums in `app/Enums`, with case names in StudlyCase, values in snake_case, and an optional `label()`.
- After adding or changing an enum, run `php artisan boardmate:export-enums`. It regenerates `boardmate-app/src/types/enums.ts`; never edit that file by hand.

## API

- Prefix `/api/v1`, kebab-case paths, plural resources: `GET /api/v1/rentable-units/{id}`.
- Route names: `api.v1.<resource>.<action>`.
- Success body: `{ "data": …, "meta"?: …, "message"?: … }` via `App\Http\Responses\ApiResponse` or API Resources.
- Error body: `{ "message": "…", "errors"?: { "field": ["…"] } }` (Laravel default; validation returns 422).
- Auth: Sanctum bearer token, `Authorization: Bearer <token>`.
- Every endpoint gets Scribe docblocks (`@group`, description, `@unauthenticated` where public); regenerate with `php artisan scribe:generate` (served at `/docs`).
- Controllers live in `app/Http/Controllers/Api/V1`; validation lives in Form Requests; authorization lives in Policies.

## Service modules

Reusable capabilities (files, notifications, audit, numbering, split, later chat, documents and payments) live in modules:

```
app/Services/<Module>/
    Contracts/<Module>Service.php   the interface features depend on
    <Implementation>.php
```

Bindings live in `app/Providers/ServiceModulesProvider.php`. Features type-hint the contract, never the implementation. A module is added only when a phase needs it.

The app mirrors this in `boardmate-app/src/services/<module>.ts` on top of the shared `api` client.

Current modules:

| Module | Use it for |
| --- | --- |
| `Files` | Every upload: `rules()`, `store()`, `url()` (public, or a signed private link) |
| `Notifications` | Every message to a person: `send()` / `sendToAddress()`; wording in `Templates/` |
| `Audit` | Every change to money, prices, approvals, overrides, caretaker actions: `record()`; `AuditDiff::between()` for before → after |
| `Numbering` | Receipt / bill / notice numbers: `next()` inside the transaction that saves the document |
| `Scheduler` | Daily work: implement `DailyJob`, add it to `config/boardmate.php` `daily_jobs` |
| `Split` | Dividing any amount among people (S6): `SplitEngine` |
| `BillingCalendar` | Due dates, rent periods, proration, utility due dates (S5): `BillingCalendar` |
| `SocialAuth` | Google ID token verification |

## Scheduler

- `php artisan boardmate:daily` runs every job in `config/boardmate.php` → `daily_jobs` once per Manila day; it is scheduled at 09:00 Asia/Manila.
- Safe to run twice: `scheduler_runs` records each job per day, and a finished job is skipped. Failed jobs are retried on the next run. `--date=YYYY-MM-DD` catches up a missed day.
- A job computes what is due **as of** the given day, so a missed or repeated run never double-charges anyone.
- Development: keep `php artisan schedule:work` running. Server: one cron entry:
  `* * * * * cd /path/to/boardmate && php artisan schedule:run >> /dev/null 2>&1`
- Set `NOTIFICATIONS_QUEUE=true` on the server and run `php artisan queue:work` (e.g. under Supervisor) so emails are retried by the worker.

## Audit log

- Entries are never edited or deleted; `activitylog:clean` must not be scheduled.
- Account numbers are masked (`•••• 6789`); passwords and tokens are never recorded.
- Scopes: `owner` (the owner's business log), `admin` (platform-admin actions), `account` (personal security events, kept for support).

## Tests

- Pest. Feature tests run against the `boardmate_test` Postgres database (reset per test). Mail uses the `array` mailer, so no real email is sent.
- `tests/Concurrency` runs real parallel processes (e.g. four cashiers numbering receipts at once); it truncates tables instead of rolling back.
- Money and date logic must have tests that use the billing guide's worked examples as the expected results.
- Run `php artisan test` and `vendor/bin/pint --test` before handing over a phase.
