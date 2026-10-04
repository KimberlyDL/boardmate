<?php

namespace App\Models;

use App\Enums\OwnerVerificationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'business_name', 'application_notes',
    'gcash_name', 'gcash_number', 'bank_name', 'bank_account_name', 'bank_account_number',
])]
class OwnerProfile extends Model
{
    protected function casts(): array
    {
        return [
            'verification_status' => OwnerVerificationStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Proof files sent with the application, oldest first (purged ones included, without a file). */
    public function documents(): HasMany
    {
        return $this->hasMany(OwnerDocument::class)->orderBy('id');
    }

    public function isVerified(): bool
    {
        return $this->verification_status === OwnerVerificationStatus::Verified;
    }
}
