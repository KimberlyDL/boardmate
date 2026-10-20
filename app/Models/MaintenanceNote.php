<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A line in a request's history (D1): a note from staff or the reporter,
 * or what changed (written by the app, e.g. "Started work").
 */
class MaintenanceNote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
