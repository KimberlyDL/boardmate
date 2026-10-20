<?php

namespace App\Models;

use Database\Factories\HouseRuleVersionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One version of a property's house rules (D3). `version` null = the draft
 * being edited; published versions are numbered 1, 2, … and never change.
 */
class HouseRuleVersion extends Model
{
    /** @use HasFactory<HouseRuleVersionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(HouseRule::class, 'version_id')->orderBy('position');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function isDraft(): bool
    {
        return $this->version === null;
    }

    /** @param  Builder<HouseRuleVersion>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('version');
    }
}
