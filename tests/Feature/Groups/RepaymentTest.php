<?php

use App\Enums\GroupExpenseKind;
use App\Enums\GroupRole;
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
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Notification;

/**
 * The room: Kim (leader), Ben and Ana.
 * - Meralco ₱900 billed to Kim, ₱300 each.
 * - Internet ₱600 paid by Ben, ₱200 each.
 * So Ana owes Kim ₱300 and Ben ₱200; Ben owes Kim ₱300 − ₱200 = ₱100 (the pair cancels out).
 */
beforeEach(function () {
    Notification::fake();
    Date::setTestNow(CarbonImmutable::parse('2026-10-20 10:00', 'Asia/Manila'));
    $this->owner = User::factory()->owner()->create();
    $this->property = makeProperty($this->owner);
    $room = $this->property->rooms()->first();
    foreach (['kim', 'ben', 'ana'] as $i => $name) {
        $this->{$name} = User::factory()->boarder()->create(['name' => ucfirst($name)]);
        Tenancy::factory()->forUnit($room->units()->get()[$i])->movedInOn('2026-09-01')->create(['tenant_id' => $this->{$name}->id]);
    }
    RoomLeader::factory()->create(['room_id' => $room->id, 'user_id' => $this->kim->id]);
    $this->actingAs($this->kim, 'sanctum')->getJson('/api/v1/me/groups')->assertOk();
    $this->group = ContributionGroup::sole();
    $this->m = $this->group->members()->get()->keyBy('name');
    foreach ([['Kim', 90000, GroupExpenseKind::OwnerBill], ['Ben', 60000, GroupExpenseKind::MemberExpense]] as [$payer, $amount, $kind]) {
        $line = GroupExpense::factory()->paidBy($this->m[$payer])->create(['kind' => $kind, 'amount_centavos' => $amount]);
        foreach ($this->m as $member) {
            ExpenseShare::create(['expense_id' => $line->id, 'member_id' => $member->id, 'amount_centavos' => $amount / 3]);
        }
    }
    $this->g = "/api/v1/groups/{$this->group->id}";
});

afterEach(fn () => Date::setTestNow());

it('works out who owes whom pair by pair; members see their own, the leader sees all, the owner nothing', function () {
    $this->actingAs($this->kim, 'sanctum')->getJson("{$this->g}/balances")->assertOk()
        ->assertJsonPath('data.me.owe_centavos', 0)
        ->assertJsonPath('data.me.owed_to_me_centavos', 40000)
        ->assertJsonCount(3, 'data.pairs')
        ->assertJsonPath('data.pairs.0.from.name', 'Ana')->assertJsonPath('data.pairs.0.to.name', 'Kim')->assertJsonPath('data.pairs.0.amount_centavos', 30000)
        ->assertJsonPath('data.pairs.1.from.name', 'Ana')->assertJsonPath('data.pairs.1.to.name', 'Ben')->assertJsonPath('data.pairs.1.amount_centavos', 20000)
        ->assertJsonPath('data.pairs.2.from.name', 'Ben')->assertJsonPath('data.pairs.2.to.name', 'Kim')->assertJsonPath('data.pairs.2.amount_centavos', 10000);

    $this->actingAs($this->ben, 'sanctum')->getJson("{$this->g}/balances")->assertOk()
        ->assertJsonPath('data.me.owe_centavos', 10000)
        ->assertJsonPath('data.me.owed_to_me_centavos', 20000)
        ->assertJsonCount(2, 'data.pairs');

    $this->actingAs($this->owner, 'sanctum')->getJson("{$this->g}/balances")->assertForbidden();
    $this->actingAs(User::factory()->boarder()->create(), 'sanctum')->getJson("{$this->g}/balances")->assertNotFound();
});

it('takes a repayment in two steps: marked by the payer, confirmed by the one paid', function () {
    [$kim, $ana] = [$this->m['Kim'], $this->m['Ana']];
    $this->actingAs($this->ana, 'sanctum');

    $this->postJson("{$this->g}/repayments", ['to_member_id' => $kim->id, 'amount_centavos' => 40000, 'method' => 'cash'])
        ->assertUnprocessable()->assertJsonPath('errors.amount_centavos.0', 'Ana owes Kim ₱300.00.');
    $id = $this->postJson("{$this->g}/repayments", ['to_member_id' => $kim->id, 'amount_centavos' => 30000, 'method' => 'online', 'reference' => 'GC-55'])
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('message', 'Marked as paid. Kim confirms it.')->json('data.id');
    Notification::assertSentTo($this->kim, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::GroupRepayment && $n->data['what'] === 'marked');
    $this->getJson("{$this->g}/balances")->assertJsonPath('data.me.owe_centavos', 50000); // pending does not count yet

    $this->postJson("{$this->g}/repayments/{$id}/confirm")->assertForbidden(); // not the payer
    $this->actingAs($this->kim, 'sanctum')->postJson("{$this->g}/repayments/{$id}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
    $this->postJson("{$this->g}/repayments/{$id}/confirm")->assertUnprocessable();
    Notification::assertSentTo($this->ana, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::GroupRepayment && $n->data['what'] === 'confirmed');

    $this->actingAs($this->ana, 'sanctum')->getJson("{$this->g}/balances")->assertJsonPath('data.me.owe_centavos', 20000);
});

it('lets the one paid reject with a reason, or record cash they got at once', function () {
    [$kim, $ben, $ana] = [$this->m['Kim'], $this->m['Ben'], $this->m['Ana']];
    $id = $this->actingAs($this->ana, 'sanctum')->postJson("{$this->g}/repayments", ['to_member_id' => $ben->id, 'amount_centavos' => 20000, 'method' => 'cash'])->json('data.id');

    $this->actingAs($this->ben, 'sanctum')->postJson("{$this->g}/repayments/{$id}/reject")->assertUnprocessable()->assertJsonValidationErrors(['reason']);
    $this->postJson("{$this->g}/repayments/{$id}/reject", ['reason' => 'I did not get it'])->assertOk()->assertJsonPath('data.status', 'rejected');
    Notification::assertSentTo($this->ana, DormLinkNotification::class, fn ($n) => $n->event === NotificationEvent::GroupRepayment && $n->data['reason'] === 'I did not get it');

    // Kim (the leader) records cash Ana handed him: confirmed at once.
    $this->actingAs($this->kim, 'sanctum')->postJson("{$this->g}/repayments", ['from_member_id' => $ana->id, 'to_member_id' => $kim->id, 'amount_centavos' => 30000, 'method' => 'cash'])
        ->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('message', 'Repayment recorded.');
    // A member cannot record for someone else.
    $this->actingAs($this->ben, 'sanctum')->postJson("{$this->g}/repayments", ['from_member_id' => $ana->id, 'to_member_id' => $ben->id, 'amount_centavos' => 100, 'method' => 'cash'])
        ->assertUnprocessable()->assertJsonValidationErrors(['from_member_id']);
});

it('checks again on confirming, so two marks never pay back more than owed', function () {
    [$ben] = [$this->m['Ben']];
    $this->actingAs($this->ana, 'sanctum');
    $first = $this->postJson("{$this->g}/repayments", ['to_member_id' => $ben->id, 'amount_centavos' => 15000, 'method' => 'cash'])->json('data.id');
    $second = $this->postJson("{$this->g}/repayments", ['to_member_id' => $ben->id, 'amount_centavos' => 15000, 'method' => 'cash'])->json('data.id');

    $this->actingAs($this->ben, 'sanctum')->postJson("{$this->g}/repayments/{$first}/confirm")->assertOk();
    $this->postJson("{$this->g}/repayments/{$second}/confirm")->assertUnprocessable()->assertJsonValidationErrors(['repayment']);
});

it('lets a moderator decide for others, and someone who left still settle up', function () {
    [$kim, $ben, $ana] = [$this->m['Kim'], $this->m['Ben'], $this->m['Ana']];
    $ben->update(['role' => GroupRole::Moderator]);
    $id = $this->actingAs($this->ana, 'sanctum')->postJson("{$this->g}/repayments", ['to_member_id' => $kim->id, 'amount_centavos' => 30000, 'method' => 'cash'])->json('data.id');
    $this->actingAs($this->ben, 'sanctum')->postJson("{$this->g}/repayments/{$id}/confirm")->assertOk();

    $ana->update(['left_on' => '2026-10-15']);
    $this->actingAs($this->ana, 'sanctum')->getJson("{$this->g}/balances")->assertOk()->assertJsonPath('data.me.owe_centavos', 20000);
    $this->postJson("{$this->g}/repayments", ['to_member_id' => $ben->id, 'amount_centavos' => 20000, 'method' => 'online'])->assertCreated();
    expect(GroupRepayment::where('status', RepaymentStatus::Pending)->count())->toBe(1);
});
