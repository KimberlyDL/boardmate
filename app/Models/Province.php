<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A province from the official PSGC list; Metro Manila is listed as one (Phase 4). */
class Province extends Model
{
    protected $fillable = ['psgc_code', 'name'];

    /** @return HasMany<City, $this> */
    public function cities(): HasMany
    {
        return $this->hasMany(City::class)->orderBy('name');
    }
}
