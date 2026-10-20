<?php

use App\Enums\GroupExpenseKind;
use App\Enums\NotificationEvent;
use App\Enums\RepaymentStatus;
use App\Models\ContributionGroup;
use App\Models\ExpenseShare;
use App\Models\GroupExpense;
use App\Models\GroupRepayment;
use App\Models\RoomLeader;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Notifications\DormLinkNotification;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('local');
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner, ['units' => ['count' => 4, 'rent_centavos' => 180000]]);
    $this->room = $this->property->rooms()->first();
    [$b1, $b2, $b3, $b4] = $this->room->units()->get();
    foreach (['kim' => [$b1, null], 'ben' => [$b2, null], 'ana' => [$b3, null], 'joy' => [$b4, '2026-10-05']] as $name => [$bed, $left]) {
        $this->{$name} = User::factory()->boarder()->create(['name' => ucfirst($name)]);
        Tenancy::factory()->forUnit($bed)->movedInOn('2026-09-01')->create(['tenant_id' => $this->{$name}->id] + ($left ? ['status' => 'ended', 'end_on' => $left, 'moved_out_on' => $left] : []));
    }
    RoomLeader::factory()->create(['room_id' => $this->room->id, 'user_id' => $this->kim->id]);
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/groups')->assertOk();
    $this->group = ContributionGroup::sole();
    $this->m = $this->group->members()->get()->keyBy('name');
    $this->url = "/api/v1/groups/{$this->group->id}/expenses";
});

afterEach(fn () => Date::setTestNow());

function sharesByName(int $expenseId): array
{
    return ExpenseShare::where('expense_id', $expenseId)->orderBy('id')->with('member')->get()->mapWithKeys(fn ($s) => [$s->member->name => $s->amount_centavos])->all();
}

it('lets a member share the internet they pay, equally among everyone there now (SC-31)', function () {
    $id = $this->actingAs($this->ben, 'sanctum')->post($this->url, [
        'description' => 'Internet, October', 'amount_centavos' => 150000, 'spent_on' => '2026-10-05', 'proof' => UploadedFile::fake()->image('receipt.jpg'),
    ], ['Accept' => 'application/json'])->assertCreated()
        ->assertJsonPath('data.kind', 'member_expense')
        ->assertJsonPath('data.paid_by.name', 'Ben')
        ->assertJsonPath('data.my_share_centavos', 50000)
        ->assertJsonPath('data.added_by_me', true)
        ->json('data.id');

    expect(sharesByName($id))->toBe(['Kim' => 50000, 'Ben' => 50000, 'Ana' => 50000]) // Joy left: not included
        ->and($this->getJson($this->url)->json('data.0.proof_url'))->toContain('/api/v1/files/group-proofs/');
    Notification::assertSentTo($this->ana, DormLinkNotification::class,
        fn ($n) => $n->event === NotificationEvent::GroupLinePosted && $n->data['share'] === '₱500.00' && $n->data['owed_to'] === 'Ben');
    Notification::assertNotSentTo($this->ben, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::GroupLinePosted);

    // The owner never sees members' own expenses.
    $this->actingAs($this->owner, 'sanctum')->getJson($this->url)->assertOk()->assertJsonCount(0, 'data');
    $this->postJson($this->url, ['description' => 'X', 'amount_centavos' => 100, 'spent_on' => '2026-10-05'])->assertForbidden();
});

it('shares among the members chosen, by weight or fixed amounts, and only the leader records for someone else', function () {
    [$kim, $ben, $ana] = [$this->m['Kim'], $this->m['Ben'], $this->m['Ana']];
    $this->actingAs($this->ben, 'sanctum');

    $id = $this->postJson($this->url, ['description' => 'Rice', 'amount_centavos' => 90000, 'spent_on' => '2026-10-10',
        'participants' => [$ben->id, $ana->id], 'method' => 'weights', 'weights' => [$ben->id => 2, $ana->id => 1]])->assertCreated()->json('data.id');
    expect(sharesByName($id))->toBe(['Ben' => 60000, 'Ana' => 30000]);

    $this->postJson($this->url, ['description' => 'Gas', 'amount_centavos' => 90000, 'spent_on' => '2026-10-10',
        'participants' => [$ben->id, $ana->id], 'method' => 'custom', 'amounts' => [$ben->id => 50000, $ana->id => 30000]])
        ->assertUnprocessable()->assertJsonValidationErrors(['amounts']);
    $this->postJson($this->url, ['description' => 'Gas', 'amount_centavos' => 90000, 'spent_on' => '2026-10-10', 'paid_by_member_id' => $kim->id])
        ->assertUnprocessable()->assertJsonValidationErrors(['paid_by_member_id']);
    $this->postJson($this->url, ['description' => 'Old', 'amount_centavos' => 100, 'spent_on' => '2026-06-01'])->assertUnprocessable()->assertJsonValidationErrors(['spent_on']);

    $this->actingAs($this->kim, 'sanctum')->postJson($this->url, ['description' => 'Soap', 'amount_centavos' => 30000, 'spent_on' => '2026-10-10', 'paid_by_member_id' => $ana->id])
        ->assertCreated()->assertJsonPath('data.paid_by.name', 'Ana');

    // Joy left: she can look but not add.
    $this->actingAs($this->joy, 'sanctum')->postJson($this->url, ['description' => 'X', 'amount_centavos' => 100, 'spent_on' => '2026-10-10'])->assertForbidden();
});

it('allows edits and deletes until the payer is paid back, then only corrections', function () {
    [$kim, $ben, $ana] = [$this->m['Kim'], $this->m['Ben'], $this->m['Ana']];
    $id = $this->actingAs($this->ben, 'sanctum')->postJson($this->url, ['description' => 'Internet', 'amount_centavos' => 150000, 'spent_on' => '2026-10-05'])->json('data.id');

    $this->actingAs($this->ana, 'sanctum')->patchJson("{$this->url}/{$id}", ['amount_centavos' => 180000])->assertForbidden();
    $this->actingAs($this->ben, 'sanctum')->patchJson("{$this->url}/{$id}", ['amount_centavos' => 180000])->assertOk();
    expect(sharesByName($id))->toBe(['Kim' => 60000, 'Ben' => 60000, 'Ana' => 60000]);

    GroupRepayment::factory()->between($kim, $ben)->create(['amount_centavos' => 60000, 'status' => RepaymentStatus::Confirmed]);
    $this->patchJson("{$this->url}/{$id}", ['amount_centavos' => 150000])->assertUnprocessable()->assertJsonValidationErrors(['expense']);
    $this->actingAs($this->kim, 'sanctum')->deleteJson("{$this->url}/{$id}")->assertUnprocessable();

    // A correction: Ana was away half the month, Kim and Ben take ₱150 each of hers.
    $this->actingAs($this->ben, 'sanctum')->postJson("{$this->url}/{$id}/corrections", ['changes' => [$ana->id => -30000], 'reason' => 'X'])->assertForbidden();
    $fix = $this->actingAs($this->kim, 'sanctum')->postJson("{$this->url}/{$id}/corrections", [
        'changes' => [$ana->id => -30000, $kim->id => 15000, $ben->id => 15000], 'reason' => 'Ana was away for two weeks',
    ])->assertCreated()->assertJsonPath('data.kind', 'adjustment')->assertJsonPath('data.amount_centavos', 0)->json('data.id');
    expect(sharesByName($fix))->toEqual(['Kim' => 15000, 'Ben' => 15000, 'Ana' => -30000]); // any order
});

it('keeps an owner bill\'s amount: its corrections must add up to zero, and it is never edited or deleted', function () {
    [$kim, $ben] = [$this->m['Kim'], $this->m['Ben']];
    $line = GroupExpense::factory()->paidBy($kim)->create(['kind' => GroupExpenseKind::OwnerBill, 'description' => 'Meralco', 'amount_centavos' => 60000]);

    $this->actingAs($this->kim, 'sanctum')->patchJson("{$this->url}/{$line->id}", ['amount_centavos' => 1])->assertUnprocessable()->assertJsonValidationErrors(['expense']);
    $this->deleteJson("{$this->url}/{$line->id}")->assertUnprocessable();
    $this->postJson("{$this->url}/{$line->id}/corrections", ['changes' => [$ben->id => 5000], 'reason' => 'X'])
        ->assertUnprocessable()->assertJsonValidationErrors(['changes']);
    $this->postJson("{$this->url}/{$line->id}/corrections", ['changes' => [$ben->id => 5000, $kim->id => -5000], 'reason' => 'Ben ran an aircon'])->assertCreated();
});

it('lets whoever added it or the leader delete it while nobody has paid back', function () {
    $id = $this->actingAs($this->ben, 'sanctum')->postJson($this->url, ['description' => 'Typo', 'amount_centavos' => 15000, 'spent_on' => '2026-10-05'])->json('data.id');

    $this->actingAs($this->kim, 'sanctum')->deleteJson("{$this->url}/{$id}")->assertOk();
    expect(GroupExpense::count())->toBe(0)->and(ExpenseShare::count())->toBe(0);
});
