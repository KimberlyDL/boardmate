<?php

namespace App\Models;

use App\Enums\NoticeStatus;
use Database\Factories\TenancyNoticeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An end date asked for (a boarder's notice) or set by staff (C1). History is
 * kept: at most one is Pending and one Approved at a time per tenancy.
 */
class TenancyNotice extends Model
{
    /** @use HasFactory<TenancyNoticeFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => NoticeStatus::class,
            'end_on' => 'immutable_date',
            'is_short_notice' => 'boolean',
            'decided_at' => 'datetime',
        ];
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function filer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
