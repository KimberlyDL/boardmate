<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A late arrival without an approved request (D2). A log only. */
class CurfewViolation extends Model
{
    /** This many or more within REPEAT_DAYS shows on the owner's home. */
    public const REPEAT_COUNT = 3;

    public const REPEAT_DAYS = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['on_date' => 'immutable_date'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function logger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'logged_by');
    }
}
