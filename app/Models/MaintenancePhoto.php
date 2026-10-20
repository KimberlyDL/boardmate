<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A private photo of the problem (D1). */
class MaintenancePhoto extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    public function request(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequest::class, 'request_id');
    }
}
