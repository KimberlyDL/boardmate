<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Enums\AuditEvent;
use App\Enums\CaretakerAccessLevel;
use App\Enums\NotificationEvent;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\CaretakerInvitationResource;
use App\Http\Resources\CaretakerLinkResource;
use App\Http\Responses\ApiResponse;
use App\Models\CaretakerInvitation;
use App\Models\OwnerCaretaker;
use App\Models\Property;
use App\Models\PropertyCaretaker;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner's caretakers: invite, change access level, remove. Owner only
 * (managers cannot add or remove caretakers).
 *
 * @group Owner
 */
class CaretakerController extends Controller
{
    /**
     * List caretakers and invitations
     */
    public function index(Request $request): JsonResponse
    {
        $owner = $request->user();

        $caretakers = $owner->caretakerLinks()->active()->with('caretaker')->latest()->get();
        $invitations = CaretakerInvitation::where('owner_id', $owner->id)
            ->whereNull('accepted_at')
            ->latest()
            ->limit(50)
            ->get();

        return ApiResponse::ok([
            'caretakers' => $caretakers->map(fn ($link) => new CaretakerLinkResource($link, 'caretaker')),
            'invitations' => CaretakerInvitationResource::collection($invitations),
            'access_levels' => array_map(fn (CaretakerAccessLevel $l) => [
                'value' => $l->value,
                'label' => $l->label(),
                'description' => $l->description(),
            ], CaretakerAccessLevel::cases()),
        ]);
    }

    /**
     * Invite a caretaker
     *
     * Emails an invitation link (valid 7 days). Only verified owners can
     * invite. Inviting the same email again replaces the earlier invitation.
     */
    public function invite(Request $request, NotificationService $notifications): JsonResponse
    {
        $owner = $request->user();

        if (! $owner->isVerifiedOwner()) {
            return response()->json([
                'message' => 'You can invite caretakers once your owner account is verified.',
                'code' => 'owner_not_verified',
            ], 403);
        }

        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255', Rule::notIn([$owner->email])],
            'access_level' => ['nullable', Rule::enum(CaretakerAccessLevel::class)],
            // Optional: properties to assign once they accept.
            'property_ids' => ['sometimes', 'array'],
            'property_ids.*' => ['integer', 'distinct', Rule::exists('properties', 'id')->where('owner_id', $owner->id)->whereNull('deleted_at')],
        ], ['email.not_in' => 'You cannot invite yourself.', 'property_ids.*.exists' => 'That property is not yours.']);

        $existing = User::firstWhere('email', $data['email']);
        if ($existing && $owner->caretakerLinks()->active()->where('caretaker_id', $existing->id)->exists()) {
            return response()->json([
                'message' => 'This person is already one of your caretakers.',
                'errors' => ['email' => ['This person is already one of your caretakers.']],
            ], 422);
        }

        // One open invitation per email: replace any earlier one.
        CaretakerInvitation::where('owner_id', $owner->id)
            ->where('email', $data['email'])
            ->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $level = CaretakerAccessLevel::tryFrom($data['access_level'] ?? '') ?? CaretakerAccessLevel::Collector;
        [$invitation, $token] = CaretakerInvitation::issue($owner, $data['email'], $level);
        if (! empty($data['property_ids'])) {
            $invitation->forceFill(['property_ids' => array_values($data['property_ids'])])->save();
        }

        $this->sendInvitation($notifications, $owner, $invitation, $token);
        $this->audit()->record(AuditEvent::CaretakerInvited, $invitation, owner: $owner,
            note: "{$invitation->email} as {$level->label()}");

        return ApiResponse::created(new CaretakerInvitationResource($invitation), "Invitation sent to {$invitation->email}.");
    }

    /**
     * Resend an invitation
     *
     * Sends a fresh link (the old link stops working) and restarts the 7 days.
     */
    public function resend(Request $request, CaretakerInvitation $invitation, NotificationService $notifications): JsonResponse
    {
        $this->ensureOwns($request, $invitation);
        abort_if($invitation->accepted_at || $invitation->declined_at || $invitation->revoked_at, 409, 'This invitation is closed. Send a new one.');

        $token = str()->random(48);
        $invitation->forceFill([
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(CaretakerInvitation::VALID_DAYS),
        ])->save();

        $this->sendInvitation($notifications, $request->user(), $invitation, $token);
        $this->audit()->record(AuditEvent::CaretakerInvitationResent, $invitation, owner: $request->user(), note: $invitation->email);

        return ApiResponse::ok(new CaretakerInvitationResource($invitation), "Invitation sent again to {$invitation->email}.");
    }

    /**
     * Cancel an invitation
     */
    public function revoke(Request $request, CaretakerInvitation $invitation): JsonResponse
    {
        $this->ensureOwns($request, $invitation);

        if ($invitation->isPending()) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            $this->audit()->record(AuditEvent::CaretakerInvitationCancelled, $invitation, owner: $request->user(), note: $invitation->email);
        }

        return ApiResponse::ok(new CaretakerInvitationResource($invitation), 'Invitation cancelled.');
    }

    /**
     * Change a caretaker's access level
     */
    public function update(Request $request, OwnerCaretaker $caretaker, NotificationService $notifications): JsonResponse
    {
        $this->ensureLinkOwner($request, $caretaker);

        $data = $request->validate(['access_level' => ['required', Rule::enum(CaretakerAccessLevel::class)]]);
        $before = $caretaker->access_level;
        $caretaker->update(['access_level' => $data['access_level']]);

        if ($before !== $caretaker->access_level) {
            $this->audit()->record(AuditEvent::CaretakerAccessChanged, $caretaker,
                ['access_level' => [$before, $caretaker->access_level]],
                owner: $request->user(), note: $caretaker->caretaker->name);
        }

        $notifications->send($caretaker->caretaker, NotificationEvent::CaretakerAccessChanged, [
            'owner_name' => $request->user()->ownerDisplayName(),
            'access_level_label' => $caretaker->access_level->label(),
        ]);

        return ApiResponse::ok(new CaretakerLinkResource($caretaker->load('caretaker'), 'caretaker'), 'Access level updated.');
    }

    /**
     * Remove a caretaker
     *
     * They lose access to all of this owner's properties at once.
     */
    public function destroy(Request $request, OwnerCaretaker $caretaker, NotificationService $notifications): JsonResponse
    {
        $this->ensureLinkOwner($request, $caretaker);

        $caretaker->update(['removed_at' => now()]);

        // They lose every property assignment with this owner at once.
        PropertyCaretaker::where('caretaker_id', $caretaker->caretaker_id)
            ->whereIn('property_id', Property::withTrashed()->where('owner_id', $caretaker->owner_id)->select('id'))
            ->delete();

        $person = $caretaker->caretaker;
        if (! $person->employerLinks()->active()->exists()) {
            $person->removeRole(UserRole::Caretaker->value);
            $person->syncActiveRole();
        }

        $notifications->send($person, NotificationEvent::CaretakerRemoved, ['owner_name' => $request->user()->ownerDisplayName()]);
        $this->audit()->record(AuditEvent::CaretakerRemoved, $caretaker, owner: $request->user(),
            note: "{$person->name} ({$caretaker->access_level->label()})");

        return ApiResponse::message("{$person->name} is no longer your caretaker.");
    }

    private function sendInvitation(NotificationService $notifications, User $owner, CaretakerInvitation $invitation, string $token): void
    {
        $notifications->sendToAddress($invitation->email, null, NotificationEvent::CaretakerInvitation, [
            'owner_name' => $owner->ownerDisplayName(),
            'access_level_label' => $invitation->access_level->label(),
            'access_level_description' => $invitation->access_level->description(),
            'url' => rtrim(config('app.frontend_url'), '/').'/invitations/'.$token,
            'valid_days' => CaretakerInvitation::VALID_DAYS,
        ]);
    }

    private function audit(): AuditService
    {
        return app(AuditService::class);
    }

    private function ensureOwns(Request $request, CaretakerInvitation $invitation): void
    {
        abort_unless($invitation->owner_id === $request->user()->id, 404);
    }

    private function ensureLinkOwner(Request $request, OwnerCaretaker $link): void
    {
        abort_unless($link->owner_id === $request->user()->id && $link->removed_at === null, 404);
    }
}
