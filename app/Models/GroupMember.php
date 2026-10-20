<?php

namespace App\Models;

use App\Enums\GroupRole;
use Database\Factories\GroupMemberFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Someone in a room's group (D5): a bedspacer (tenancy) or a listed occupant
 * of a whole room (an account is optional; the leader records for them).
 */
class GroupMember extends Model
{
    /** @use HasFactory<GroupMemberFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = ['role' => 'member'];

    protected function casts(): array
    {
        return [
            'role' => GroupRole::class,
            'joined_on' => 'immutable_date',
            'left_on' => 'immutable_date',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ContributionGroup::class, 'group_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenancy(): BelongsTo
    {
        return $this->belongsTo(Tenancy::class);
    }

    public function occupant(): BelongsTo
    {
        return $this->belongsTo(RoomOccupant::class, 'room_occupant_id');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(ExpenseShare::class, 'member_id');
    }

    public function isCurrent(): bool
    {
        return $this->left_on === null;
    }
}
