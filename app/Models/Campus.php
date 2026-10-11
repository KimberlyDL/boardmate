<?php

namespace App\Models;

use Database\Factories\CampusFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A campus the Dorm Finder measures distance from; at most one is the default. */
class Campus extends Model
{
    /** @use HasFactory<CampusFactory> */
    use HasFactory;

    protected $fillable = ['name', 'latitude', 'longitude'];

    protected $attributes = ['is_default' => false];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_default' => 'boolean',
        ];
    }
}
