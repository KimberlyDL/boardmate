<?php

namespace App\Models;

use App\Enums\SplitMethod;
use Database\Factories\ContributionGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A room's shared-contributions group (D5), one per room. The leader is its
 * admin; owner bills the leader receives arrive as locked lines; members add
 * their own shared expenses and pay each other back.
 */
class ContributionGroup extends Model
{
    /** @use HasFactory<ContributionGroupFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['default_split_method' => SplitMethod::class];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class)->withTrashed();
    }

    /** Everyone who is or was in the group (former members keep their leave date). */
    public function members(): HasMany
    {
        return $this->hasMany(GroupMember::class, 'group_id')->orderBy('joined_on')->orderBy('id');
    }

    /** Members living in the room now. */
    public function currentMembers(): HasMany
    {
        return $this->members()->whereNull('left_on');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(GroupExpense::class, 'group_id')->latest('spent_on')->latest('id');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(GroupRepayment::class, 'group_id')->latest('id');
    }
}
