<?php

namespace App\Models;

use App\Enums\GroupExpenseKind;
use App\Enums\SplitMethod;
use Database\Factories\GroupExpenseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A line in a room's group (D5): an owner bill (locked amount), a member's
 * shared expense, or a correction. Its shares say who owes what to the
 * member who paid it (the leader, for owner bills).
 */
class GroupExpense extends Model
{
    /** @use HasFactory<GroupExpenseFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'kind' => GroupExpenseKind::class,
            'amount_centavos' => 'integer',
            'spent_on' => 'immutable_date',
            'split_method' => SplitMethod::class,
            'split_params' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ContributionGroup::class, 'group_id');
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(GroupMember::class, 'paid_by_member_id');
    }

    public function adjusts(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjusts_expense_id');
    }

    public function shares(): HasMany
    {
        // Created in member order (joined, then id), so id order is member order; without it Postgres may return any order.
        return $this->hasMany(ExpenseShare::class, 'expense_id')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** An owner bill's amount comes from the bill and never changes here; only its split does. */
    public function isLocked(): bool
    {
        return $this->kind === GroupExpenseKind::OwnerBill;
    }
}
