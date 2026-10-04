<?php

namespace App\Models;

use App\Enums\CaretakerAccessLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A caretaker assigned to one property, with the access level for that property. */
class PropertyCaretaker extends Model
{
    protected $fillable = ['property_id', 'caretaker_id', 'access_level'];

    protected function casts(): array
    {
        return ['access_level' => CaretakerAccessLevel::class];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }

    public function caretaker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caretaker_id');
    }
}
