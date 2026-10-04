<?php

namespace App\Models;

use App\Enums\NotificationEvent;
use App\Enums\UserRole;
use App\Services\Notifications\Contracts\NotificationService;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'phone', 'active_role', 'consented_at', 'privacy_policy_version', 'muted_notifications'])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'consented_at' => 'datetime',
            'pending_email_requested_at' => 'datetime',
            'suspended_at' => 'datetime',
            'muted_notifications' => 'array',
            'password' => 'hashed',
        ];
    }

    public function boarderProfile(): HasOne
    {
        return $this->hasOne(BoarderProfile::class);
    }

    public function ownerProfile(): HasOne
    {
        return $this->hasOne(OwnerProfile::class);
    }

    /** Caretakers working for this owner (including removed ones; use ->active()). */
    public function caretakerLinks(): HasMany
    {
        return $this->hasMany(OwnerCaretaker::class, 'owner_id');
    }

    /** Owners this user works for as a caretaker. */
    public function employerLinks(): HasMany
    {
        return $this->hasMany(OwnerCaretaker::class, 'caretaker_id');
    }

    public function hasAccountRole(UserRole $role): bool
    {
        return $this->hasRole($role->value);
    }

    /**
     * Keep active_role pointing at a role the user still holds (e.g. after a
     * caretaker is removed). Falls back to admin > owner > caretaker > boarder.
     */
    public function syncActiveRole(): void
    {
        $held = $this->accountRoles();

        if ($held === [] || in_array(UserRole::tryFrom((string) $this->active_role), $held, true)) {
            return;
        }

        foreach ([UserRole::PlatformAdmin, UserRole::Owner, UserRole::Caretaker, UserRole::Boarder] as $role) {
            if (in_array($role, $held, true)) {
                $this->forceFill(['active_role' => $role->value])->save();

                return;
            }
        }
    }

    /** How an owner is named to caretakers and boarders: business name, else their own. */
    public function ownerDisplayName(): string
    {
        return $this->ownerProfile?->business_name ?: $this->name;
    }

    public function isVerifiedOwner(): bool
    {
        return $this->hasAccountRole(UserRole::Owner) && (bool) $this->ownerProfile?->isVerified();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** False for accounts created with Google that never set a password. */
    public function hasPassword(): bool
    {
        return $this->password !== null;
    }

    /** @return list<UserRole> */
    public function accountRoles(): array
    {
        return $this->getRoleNames()
            ->map(fn (string $name) => UserRole::tryFrom($name))
            ->filter()
            ->values()
            ->all();
    }

    public function hasMuted(NotificationEvent $event): bool
    {
        return in_array($event->value, $this->muted_notifications ?? [], true);
    }

    /** Password reset email, sent through the Notifications module. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $url = rtrim(config('app.frontend_url'), '/').'/reset-password?'.http_build_query([
            'token' => $token,
            'email' => $this->email,
        ]);

        app(NotificationService::class)->send($this, NotificationEvent::PasswordReset, ['url' => $url]);
    }

    /** Email verification link, sent through the Notifications module. */
    public function sendEmailVerificationNotification(): void
    {
        $url = URL::temporarySignedRoute('api.v1.auth.verification.verify', now()->addHours(config('boardmate.email_link_hours')), [
            'id' => $this->getKey(),
            'hash' => sha1($this->getEmailForVerification()),
        ]);

        app(NotificationService::class)->send($this, NotificationEvent::VerifyEmail, ['url' => $url]);
    }

    /** Confirmation link to the pending (new) email address. */
    public function sendEmailChangeVerification(): void
    {
        $url = URL::temporarySignedRoute('api.v1.auth.email-change.verify', now()->addHours(config('boardmate.email_link_hours')), [
            'id' => $this->getKey(),
            'hash' => sha1((string) $this->pending_email),
        ]);

        app(NotificationService::class)->sendToAddress($this->pending_email, $this, NotificationEvent::EmailChangeConfirm, ['url' => $url]);
    }
}
