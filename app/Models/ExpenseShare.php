<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One member's share of a group line (D5), owed to the member who paid the line. */
class ExpenseShare extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_centavos' => 'integer'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(GroupExpense::class, 'expense_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'member_id');
    }
}
