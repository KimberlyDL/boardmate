<?php

namespace App\Http\Controllers\Api\V1\Caretaker;

use App\Enums\AuditEvent;
use App\Enums\CaretakerInvitationStatus;
use App\Enums\NotificationEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Api\V1\Account\EmailChangeController;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\CaretakerInvitation;
use App\Models\OwnerCaretaker;
use App\Models\Property;
use App\Models\PropertyCaretaker;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The invited person's side of a caretaker invitation.
 *
 * @group Caretaker
 */
class InvitationController extends Controller
{
    /**
     * View an invitation
     *
     * Opened from the emailed link. Shows who invited whom; the email is
     * masked because anyone holding the link can open it.
     *
     * @unauthenticated
     */
    public function show(string $token): JsonResponse
    {
        $invitation = CaretakerInvitation::findByToken($token);
        abort_unless($invitation, 404, 'This invitation link is not valid.');

        $owner = $invitation->owner;
        $status = $invitation->status();

        return ApiResponse::ok([
            'owner_name' => $owner->ownerDisplayName(),
            'email_hint' => EmailChangeController::mask($invitation->email),
            'access_level' => $invitation->access_level->value,
            'access_level_label' => $invitation->access_level->label(),
            'access_level_description' => $invitation->access_level->description(),
            'status' => $status->value,
            'status_label' => $status->label(),
            'expires_at' => $invitation->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Accept an invitation
     *
     * Must be signed in with the invited email address.
     */
    public function accept(Request $request, string $token, NotificationService $notifications): JsonResponse
    {
        $invitation = $this->openInvitation($request, $token);
        if ($invitation instanceof JsonResponse) {
            return $invitation;
        }

        $user = $request->user();

        DB::transaction(function () use ($invitation, $user) {
            $link = OwnerCaretaker::firstOrNew(['owner_id' => $invitation->owner_id, 'caretaker_id' => $user->id]);
            $link->fill(['access_level' => $invitation->access_level, 'removed_at' => null])->save();

            $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

            // Properties chosen on the invitation (still the owner's, not deleted).
            $propertyIds = Property::where('owner_id', $invitation->owner_id)
                ->whereIn('id', $invitation->property_ids ?? [])
                ->pluck('id');
            foreach ($propertyIds as $propertyId) {
                PropertyCaretaker::updateOrCreate(
                    ['property_id' => $propertyId, 'caretaker_id' => $user->id],
                    ['access_level' => $invitation->access_level],
                );
            }

            $user->assignRole(UserRole::Caretaker->value);
            $user->forceFill(['active_role' => UserRole::Caretaker->value])->save();
        });

        app(AuditService::class)->record(AuditEvent::CaretakerInvitationAccepted, $invitation,
            owner: $invitation->owner, note: "{$user->name} as {$invitation->access_level->label()}");

        $notifications->send($invitation->owner, NotificationEvent::CaretakerInvitationAccepted, [
            'caretaker_name' => $user->name,
            'access_level_label' => $invitation->access_level->label(),
        ]);

        return ApiResponse::ok(
            new UserResource($user->load(['boarderProfile', 'ownerProfile'])),
            'You are now a caretaker for '.$invitation->owner->ownerDisplayName().'.',
        );
    }

    /**
     * Decline an invitation
     */
    public function decline(Request $request, string $token): JsonResponse
    {
        $invitation = $this->openInvitation($request, $token);
        if ($invitation instanceof JsonResponse) {
            return $invitation;
        }

        $invitation->forceFill(['declined_at' => now()])->save();
        app(AuditService::class)->record(AuditEvent::CaretakerInvitationDeclined, $invitation,
            owner: $invitation->owner, note: $request->user()->name);

        return ApiResponse::message('Invitation declined.');
    }

    /** The pending invitation for this signed-in user, or a refusal. */
    private function openInvitation(Request $request, string $token): CaretakerInvitation|JsonResponse
    {
        $invitation = CaretakerInvitation::findByToken($token);
        abort_unless($invitation, 404, 'This invitation link is not valid.');

        $status = $invitation->status();
        if ($status !== CaretakerInvitationStatus::Pending) {
            return response()->json([
                'message' => match ($status) {
                    CaretakerInvitationStatus::Accepted => 'This invitation was already accepted.',
                    CaretakerInvitationStatus::Expired => 'This invitation has expired. Ask the owner to send a new one.',
                    default => 'This invitation is no longer open.',
                },
                'code' => 'invitation_'.$status->value,
            ], 410);
        }

        $user = $request->user();

        if ($user->id === $invitation->owner_id) {
            return response()->json(['message' => 'You cannot accept your own invitation.', 'code' => 'invitation_self'], 403);
        }

        if (strtolower($user->email) !== $invitation->email) {
            return response()->json([
                'message' => 'This invitation was sent to '.EmailChangeController::mask($invitation->email).'. Log in with that email to accept it.',
                'code' => 'invitation_email_mismatch',
            ], 403);
        }

        return $invitation;
    }
}
