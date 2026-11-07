<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A city or municipality from the official PSGC list, under the province people expect (Phase 4). */
class City extends Model
{
    protected $fillable = ['psgc_code', 'province_id', 'name', 'official_name', 'is_city'];

    protected function casts(): array
    {
        return ['is_city' => 'boolean'];
    }

    /** @return BelongsTo<Province, $this> */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
