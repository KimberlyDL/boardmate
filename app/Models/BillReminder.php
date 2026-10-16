<?php

namespace App\Models;

use App\Enums\ReminderKind;
use Database\Factories\BillReminderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment reminder sent for one bill (C7), with what was still owed then.
 * One of each kind per bill per day (unique in the database).
 */
class BillReminder extends Model
{
    /** @use HasFactory<BillReminderFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'sent_on' => 'immutable_date',
            'balance_centavos' => 'integer',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
