<?php

namespace App\Models;

use App\Enums\HouseRuleCategory;
use Database\Factories\HouseRuleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One rule of a house-rules version (D3). */
class HouseRule extends Model
{
    /** @use HasFactory<HouseRuleFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => HouseRuleCategory::class,
            'position' => 'integer',
            'is_auto' => 'boolean',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(HouseRuleVersion::class, 'version_id');
    }
}
