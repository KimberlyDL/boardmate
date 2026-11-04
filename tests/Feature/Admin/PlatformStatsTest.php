<?php

use App\Enums\ApplicationStatus;
use App\Enums\OwnerVerificationStatus;
use App\Enums\UnitStatus;
use App\Models\BookingApplication;
use App\Models\Campus;
use App\Models\Tenancy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

beforeEach(function () {
    Date::setTestNow(CarbonImmutable::parse('2026-11-04 10:00', 'Asia/Manila'));
    $this->admin = User::factory()->admin()->create();
});

afterEach(fn () => Date::setTestNow());

function statsApplication(int $propertyId, int $boarderId, ApplicationStatus $status, string $createdAt, ?string $decidedAt = null): void
{
    BookingApplication::forceCreate([
        'property_id' => $propertyId, 'boarder_id' => $boarderId, 'status' => $status, 'planned_move_in_on' => '2026-11-15',
        'contact_phone' => '09170000000', 'created_at' => $createdAt, 'updated_at' => $createdAt, 'decided_at' => $decidedAt,
    ]);
}

it('counts accounts, owners, listings, stays and monthly activity, never money', function () {
    $owner = User::factory()->owner()->create(['created_at' => '2026-10-02']);
    User::factory()->owner(OwnerVerificationStatus::Pending)->create();
    User::factory()->owner(OwnerVerificationStatus::Rejected)->create(['created_at' => '2026-09-10']);
    $kim = User::factory()->boarder()->create(['created_at' => '2026-11-01']);
    $ben = User::factory()->boarder()->suspended()->create(['created_at' => '2026-11-02']);
    User::factory()->caretakerFor($owner)->create(['created_at' => '2026-10-20']);
    Campus::create(['name' => 'Mindoro State University', 'latitude' => 13.4, 'longitude' => 121.18]);

    $listed = makeProperty($owner);
    $listed->forceFill(['is_published' => true, 'published_at' => now()])->save();
    makeProperty($owner, ['details' => ['name' => 'Annex']]);   // not published
    $bed = $listed->units()->first();
    $bed->forceFill(['status' => UnitStatus::Occupied])->save();
    Tenancy::factory()->forUnit($bed)->movedInOn('2026-10-05')->create(['tenant_id' => $kim->id]);

    statsApplication($listed->id, $kim->id, ApplicationStatus::MovedIn, '2026-09-28', '2026-09-30');
    statsApplication($listed->id, $ben->id, ApplicationStatus::Declined, '2026-10-03', '2026-10-04');
    statsApplication($listed->id, $ben->id, ApplicationStatus::Pending, '2026-11-03');

    $data = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/stats')->assertOk()->json('data');
    $month = fn (string $m) => collect($data['months'])->firstWhere('month', $m);

    expect($data['accounts'])->toMatchArray(['total' => 6, 'suspended' => 1, 'boarders' => 6, 'owners' => 3, 'caretakers' => 1])   // the admin is left out
        ->and($data['accounts']['new_this_month'])->toBe(3)   // Kim, Ben and the pending owner (created today)
        ->and($data['owners'])->toBe(['pending' => 1, 'verified' => 1, 'rejected' => 1, 'suspended' => 0])
        ->and($data['listings'])->toBe(['on_finder' => 1, 'with_free_places' => 1, 'not_published' => 1, 'places' => 3, 'free_places' => 2])
        ->and($data['stays'])->toBe(['current' => 1, 'applications_waiting' => 1])
        ->and($data['campuses'])->toBe(1)
        ->and($data['months'])->toHaveCount(12)
        ->and($month('2026-09'))->toBe(['month' => '2026-09', 'sign_ups' => 1, 'applications' => 1, 'approved' => 1, 'move_ins' => 0])
        ->and($month('2026-10'))->toBe(['month' => '2026-10', 'sign_ups' => 2, 'applications' => 1, 'approved' => 0, 'move_ins' => 1])
        ->and(json_encode($data))->not->toContain('centavos');
});

it('is for the platform admin only', function () {
    $this->actingAs(User::factory()->owner()->create(), 'sanctum')->getJson('/api/v1/admin/stats')->assertForbidden();
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson('/api/v1/admin/stats')->assertForbidden();
});
