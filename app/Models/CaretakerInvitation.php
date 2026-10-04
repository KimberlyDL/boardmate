<?php

namespace App\Models;

use App\Enums\CaretakerAccessLevel;
use App\Enums\CaretakerInvitationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CaretakerInvitation extends Model
{
    public const VALID_DAYS = 7;

    protected $fillable = ['owner_id', 'email', 'access_level', 'token_hash', 'expires_at'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'access_level' => CaretakerAccessLevel::class,
            'property_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** Creates an invitation and returns it with the plain token (only time it exists). */
    public static function issue(User $owner, string $email, CaretakerAccessLevel $level): array
    {
        $token = Str::random(48);

        $invitation = self::create([
            'owner_id' => $owner->id,
            'email' => strtolower(trim($email)),
            'access_level' => $level,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::VALID_DAYS),
        ]);

        return [$invitation, $token];
    }

    public static function findByToken(string $token): ?self
    {
        return self::firstWhere('token_hash', hash('sha256', $token));
    }

    public function status(): CaretakerInvitationStatus
    {
        return match (true) {
            $this->accepted_at !== null => CaretakerInvitationStatus::Accepted,
            $this->declined_at !== null => CaretakerInvitationStatus::Declined,
            $this->revoked_at !== null => CaretakerInvitationStatus::Revoked,
            $this->expires_at->isPast() => CaretakerInvitationStatus::Expired,
            default => CaretakerInvitationStatus::Pending,
        };
    }

    public function isPending(): bool
    {
        return $this->status() === CaretakerInvitationStatus::Pending;
    }
}
