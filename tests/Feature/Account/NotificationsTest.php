<?php

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->user = User::factory()->boarder()->create();
});

it('stores in-app notifications and lists them with an unread count', function () {
    app(NotificationService::class)->send($this->user, NotificationEvent::Welcome);

    $this->actingAs($this->user, 'sanctum')
        ->getJson('/api/v1/notifications')
        ->assertOk()
        ->assertJsonPath('data.0.event', 'welcome')
        ->assertJsonPath('data.0.title', 'Welcome to BoardMate')
        ->assertJsonPath('data.0.read_at', null)
        ->assertJsonPath('meta.unread_count', 1);

    $this->getJson('/api/v1/notifications/unread-count')->assertJsonPath('data.unread_count', 1);
});

it('marks one or all notifications as read', function () {
    $service = app(NotificationService::class);
    $service->send($this->user, NotificationEvent::Welcome);
    $service->send($this->user, NotificationEvent::Welcome);

    $id = $this->user->notifications()->first()->id;

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertOk();
    expect($this->user->unreadNotifications()->count())->toBe(1);

    $this->postJson('/api/v1/notifications/read-all')->assertOk();
    expect($this->user->unreadNotifications()->count())->toBe(0);
});

it('cannot read another user\'s notification', function () {
    $other = User::factory()->boarder()->create();
    app(NotificationService::class)->send($other, NotificationEvent::Welcome);

    $id = $other->notifications()->first()->id;

    $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
});

it('sends nothing but password reset mail to suspended accounts', function () {
    $suspended = User::factory()->suspended()->create();

    app(NotificationService::class)->send($suspended, NotificationEvent::Welcome);

    expect($suspended->notifications()->count())->toBe(0);
});

it('queues notifications instead of sending them when NOTIFICATIONS_QUEUE is on', function () {
    Queue::fake();
    config()->set('boardmate.notifications_queue', true);

    app(NotificationService::class)->send($this->user, NotificationEvent::Welcome);

    expect($this->user->notifications()->count())->toBe(0);
    Queue::assertPushed(SendQueuedNotifications::class);
});

it('only accepts mutable events as preferences', function () {
    $this->actingAs($this->user, 'sanctum')
        ->putJson('/api/v1/me/notification-preferences', ['muted' => ['password_reset']])
        ->assertUnprocessable();

    $this->putJson('/api/v1/me/notification-preferences', ['muted' => []])->assertOk();
});
