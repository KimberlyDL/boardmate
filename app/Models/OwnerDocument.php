<?php

namespace App\Models;

use App\Enums\OwnerDocumentKind;
use Database\Factories\OwnerDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A proof file sent with an owner application (FilePurpose::OwnerDocument, private). */
class OwnerDocument extends Model
{
    /** @use HasFactory<OwnerDocumentFactory> */
    use HasFactory;

    protected $fillable = ['kind', 'path', 'original_name', 'mime_type', 'size_bytes'];

    protected function casts(): array
    {
        return [
            'kind' => OwnerDocumentKind::class,
            'size_bytes' => 'integer',
            'purged_at' => 'datetime',
        ];
    }

    public function ownerProfile(): BelongsTo
    {
        return $this->belongsTo(OwnerProfile::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeKept(Builder $query): void
    {
        $query->whereNull('purged_at');
    }
}
