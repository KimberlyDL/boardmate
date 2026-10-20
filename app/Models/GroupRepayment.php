<?php

namespace App\Models;

use App\Enums\RepaymentMethod;
use App\Enums\RepaymentStatus;
use Database\Factories\GroupRepaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member paying another back (D5). Counts only once confirmed. The owner never sees these. */
class GroupRepayment extends Model
{
    /** @use HasFactory<GroupRepaymentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'amount_centavos' => 'integer',
            'method' => RepaymentMethod::class,
            'status' => RepaymentStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ContributionGroup::class, 'group_id');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'from_member_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'to_member_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
