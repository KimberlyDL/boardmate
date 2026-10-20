<?php

namespace App\Models;

use App\Enums\MaintenanceCategory;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceUrgency;
use Database\Factories\MaintenanceRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A reported problem and its repair (D1). */
class MaintenanceRequest extends Model
{
    /** @use HasFactory<MaintenanceRequestFactory> */
    use HasFactory;

    /** A boarder attaches up to this many photos. */
    public const MAX_PHOTOS = 4;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category' => MaintenanceCategory::class,
            'urgency' => MaintenanceUrgency::class,
            'status' => MaintenanceStatus::class,
            'repair_cost_centavos' => 'integer',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class)->withTrashed();
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(RentableUnit::class, 'unit_id');
    }

    public function chargeBill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'charge_bill_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(MaintenancePhoto::class, 'request_id')->orderBy('id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(MaintenanceNote::class, 'request_id')->orderBy('id');
    }
}
