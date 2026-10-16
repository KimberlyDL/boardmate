<?php

namespace App\Models;

use Database\Factories\CollectionNoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A remark about a payer who is behind (C7, SC-32), e.g. after a visit, with
 * an optional promised date. Shown on the overdue list; nothing is automated
 * from it. Never edited or deleted.
 */
class CollectionNote extends Model
{
    /** @use HasFactory<CollectionNoteFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['promised_on' => 'immutable_date'];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
