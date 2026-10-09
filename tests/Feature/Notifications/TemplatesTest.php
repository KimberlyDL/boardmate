<?php

use App\Enums\NotificationEvent;
use App\Models\User;
use App\Services\Notifications\BoardMateNotification;
use Illuminate\Notifications\AnonymousNotifiable;

/*
| Renders every template for real (Notification::fake() never does), both to
| an account and to a bare email address, so a template can't break at send
| time. Add sample data here when adding an event.
*/

$sample = [
    'url' => 'https://example.test/link',
    'masked_email' => 'k***@gmail.com',
    'owner_name' => 'Santos Boarding House',
    'reason' => 'Please add the property address.',
    'access_level_label' => 'Collector',
    'access_level_description' => 'Records payments.',
    'valid_days' => 7,
    'caretaker_name' => 'Carla Cruz',
    'property_name' => 'Santos Boarding House',
    'boarder_name' => 'Kim Santos',
    'move_in' => 'Oct 20, 2026',
    'unit_label' => 'Bed 2',
    'address' => '12 Dapitan St, Sampaloc, Manila',
    'reserved_until' => 'Oct 27, 2026',
    'reserved_property' => 'Reyes Dorm',
    'cancelled_by' => 'Kim Santos',
    'anchor_day' => 25,
    'moved_in_on' => 'Oct 25, 2026',
    'next_due_on' => 'Nov 25, 2026',
    'is_leader' => true,
    'room_code' => 'B1-F2-03',
    'appointed_by' => 'Santos Boarding House',
    'new_leader' => 'Ben Reyes',
    'leader_name' => 'Kim Santos',
    'summary' => 'added Ericka Cruz',
    'property_id' => 12,
    'moved_property' => 'Reyes Dorm',
    'change' => 'set',
    'from' => 'Nov 26, 2026',
    'description' => '₱500.00 off your rent',
];

it('renders every event as email and in-app, for an account and for a bare address', function (NotificationEvent $event) use ($sample) {
    $user = User::factory()->create(['name' => 'Kim Santos']);
    $channels = (new BoardMateNotification($event, $sample))->via($user);

    foreach ([$user, (new AnonymousNotifiable)->route('mail', 'someone@example.com')] as $to) {
        $notification = new BoardMateNotification($event, $sample);

        if (in_array('mail', $channels, true)) {
            $mail = $notification->toMail($to);
            expect($mail->subject)->not->toBeEmpty()
                ->and((string) $mail->render())->toContain($to instanceof User ? 'Hi Kim,' : 'Hi,');
        }

        if (in_array('database', $channels, true)) {
            expect($notification->toArray($to)['title'])->not->toBeEmpty();
        }
    }
})->with(NotificationEvent::cases());
