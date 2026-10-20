<?php

namespace App\Models;

use App\Enums\VisitorStatus;
use Database\Factories\VisitorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Someone visiting a tenant (D4). */
class Visitor extends Model
{
    /** @use HasFactory<VisitorFactory> */
    use HasFactory;

    /** Records are deleted this many days after the visit. */
    public const KEEP_DAYS = 90;

    /** A visit can be registered this far ahead. */
    public const MAX_DAYS_AHEAD = 30;

    /** At most this many nights in one registration. */
    public const MAX_NIGHTS = 7;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => VisitorStatus::class,
            'visit_on' => 'immutable_date',
            'overnight' => 'boolean',
            'nights' => 'integer',
            'is_walk_in' => 'boolean',
            'over_limit' => 'boolean',
            'decided_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
