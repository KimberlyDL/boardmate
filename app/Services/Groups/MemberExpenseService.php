<?php

namespace App\Services\Groups;

use App\Enums\FilePurpose;
use App\Enums\GroupExpenseKind;
use App\Enums\NotificationEvent;
use App\Enums\SplitMethod;
use App\Models\ContributionGroup;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Models\User;
use App\Services\Files\Contracts\FileService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Support\ManilaDate;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Members' own shared expenses (D5, SC-31: e.g. the internet the boarders
 * pay themselves), and corrections to any line.
 *
 * - Any current member adds an expense they paid; the leader or a moderator
 *   may record one paid by someone else (e.g. a member without an account).
 *   It is shared among the members chosen (default: everyone there now).
 * - Until the one who paid it has been paid back, the leader, a moderator or
 *   whoever added it may edit or delete it. After that, it is corrected with
 *   a correction line that says who gains or loses what. A correction to an
 *   owner bill's line moves amounts between members only (adds up to ₱0).
 */
class MemberExpenseService
{
    public function __construct(
        private readonly GroupSplitter $splitter,
        private readonly GroupLedger $ledger,
        private readonly FileService $files,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  array{description: string, amount_centavos: int, spent_on: string, paid_by_member_id?: ?int, participants?: ?list<int>, method?: ?string, weights?: ?array, amounts?: ?array}  $data
     */
    public function add(ContributionGroup $group, GroupMember $me, array $data, ?UploadedFile $proof, User $by): GroupExpense
    {
        $payer = $this->payer($group, $me, $data['paid_by_member_id'] ?? null);
        [$method, $params, $participants] = $this->how($group, $data);
        $shares = $this->splitter->splitAmong($data['amount_centavos'], $method, $participants, $params);

        $expense = DB::transaction(function () use ($group, $payer, $data, $method, $params, $proof, $by, $shares) {
            $expense = GroupExpense::create([
                'group_id' => $group->id,
                'kind' => GroupExpenseKind::MemberExpense,
                'paid_by_member_id' => $payer->id,
                'description' => $data['description'],
                'amount_centavos' => $data['amount_centavos'],
                'spent_on' => $data['spent_on'],
                'split_method' => $method,
                'split_params' => $params ?: null,
                'proof_path' => $proof ? $this->files->store($proof, FilePurpose::GroupProof) : null,
                'created_by' => $by->id,
            ]);
            $this->ledger->writeShares($expense, $shares);

            return $expense;
        });

        $this->announce($expense);

        return $expense;
    }

    /** @param  array{description?: string, amount_centavos?: int, spent_on?: string, participants?: ?list<int>, method?: ?string, weights?: ?array, amounts?: ?array}  $data */
    public function edit(GroupExpense $expense, array $data): GroupExpense
    {
        $this->assertOpen($expense);
        $current = $expense->shares()->pluck('member_id')->all();
        [$method, $params, $participants] = $this->how($expense->group, $data + ['participants' => $current, 'method' => $expense->split_method->value]);
        $amount = $data['amount_centavos'] ?? $expense->amount_centavos;
        $shares = $this->splitter->splitAmong($amount, $method, $participants, $params);

        DB::transaction(function () use ($expense, $data, $amount, $method, $params, $shares) {
            $expense->update([
                'description' => $data['description'] ?? $expense->description,
                'amount_centavos' => $amount,
                'spent_on' => $data['spent_on'] ?? $expense->spent_on->toDateString(),
                'split_method' => $method,
                'split_params' => $params ?: null,
            ]);
            $this->ledger->writeShares($expense, $shares);
        });

        return $expense;
    }

    public function delete(GroupExpense $expense): void
    {
        $this->assertOpen($expense);
        DB::transaction(function () use ($expense) {
            $expense->shares()->delete();
            $expense->delete();
        });
        $this->files->delete($expense->proof_path, FilePurpose::GroupProof);
    }

    /**
     * A correction line: `changes` says who gains (+) or loses (−) how much.
     * For an owner bill's line they must add up to ₱0 (its amount is locked).
     *
     * @param  array<int|string, int>  $changes  member id => signed centavos
     */
    public function correct(GroupExpense $expense, array $changes, string $reason, User $by): GroupExpense
    {
        if ($expense->kind === GroupExpenseKind::Adjustment) {
            throw ValidationException::withMessages(['changes' => 'Correct the original line, not a correction.']);
        }
        $memberIds = $expense->group->members()->pluck('id')->all();
        $changes = array_filter(array_map('intval', $changes));
        if ($changes === [] || array_diff(array_keys($changes), $memberIds) !== []) {
            throw ValidationException::withMessages(['changes' => 'Say how much changes for each member, and only members of this room.']);
        }
        $total = array_sum($changes);
        if ($expense->isLocked() && $total !== 0) {
            throw ValidationException::withMessages(['changes' => 'An owner bill\'s amount is fixed: the changes must add up to ₱0 (now '.Money::format($total).').']);
        }

        return DB::transaction(function () use ($expense, $changes, $total, $reason, $by) {
            $fix = GroupExpense::create([
                'group_id' => $expense->group_id,
                'kind' => GroupExpenseKind::Adjustment,
                'adjusts_expense_id' => $expense->id,
                'paid_by_member_id' => $expense->paid_by_member_id,
                'description' => "Correction: {$expense->description}",
                'amount_centavos' => $total,
                'spent_on' => ManilaDate::today()->toDateString(),
                'split_method' => SplitMethod::Custom,
                'reason' => $reason,
                'created_by' => $by->id,
            ]);
            $this->ledger->writeShares($fix, $changes);

            return $fix;
        });
    }

    /** Who paid: the member adding it, or (for the leader or a moderator) another member of the room. */
    private function payer(ContributionGroup $group, GroupMember $me, ?int $paidById): GroupMember
    {
        if ($paidById === null || $paidById === $me->id) {
            return $me;
        }
        if (! $me->role->canManage()) {
            throw ValidationException::withMessages(['paid_by_member_id' => 'You can only add what you paid yourself.']);
        }
        $payer = $group->members()->whereKey($paidById)->first();
        if (! $payer) {
            throw ValidationException::withMessages(['paid_by_member_id' => 'Choose a member of this room.']);
        }

        return $payer;
    }

    /** @return array{0: SplitMethod, 1: array, 2: list<int>} */
    private function how(ContributionGroup $group, array $data): array
    {
        $method = SplitMethod::from($data['method'] ?? SplitMethod::Equal->value);
        $participants = array_values(array_map('intval', $data['participants'] ?? $group->currentMembers()->pluck('id')->all()));
        $known = $group->members()->pluck('id')->all();
        if (array_diff($participants, $known) !== []) {
            throw ValidationException::withMessages(['participants' => 'Choose members of this room only.']);
        }
        $params = match ($method) {
            SplitMethod::Weights => array_map('strval', $data['weights'] ?? []),
            SplitMethod::Custom => array_map('intval', $data['amounts'] ?? []),
            default => [],
        };

        return [$method, $params, $participants];
    }

    private function assertOpen(GroupExpense $expense): void
    {
        if ($expense->kind !== GroupExpenseKind::MemberExpense) {
            throw ValidationException::withMessages(['expense' => 'Only a member\'s own expense can be edited or deleted. Owner bills follow the owner; use a correction.']);
        }
        if ($this->ledger->repaidSince($expense)) {
            throw ValidationException::withMessages(['expense' => 'Someone already paid back for this. Add a correction instead.']);
        }
    }

    /** In-app: everyone else who shares it hears their share. */
    private function announce(GroupExpense $expense): void
    {
        $expense->load('shares.member.user', 'paidBy', 'group.room');
        foreach ($expense->shares as $share) {
            $user = $share->member->user;
            if (! $user || $share->member_id === $expense->paid_by_member_id || ! $share->member->isCurrent()) {
                continue;
            }
            $this->notifications->send($user, NotificationEvent::GroupLinePosted, [
                'group_id' => $expense->group_id,
                'room_label' => $expense->group->room->label(),
                'description' => $expense->description,
                'share' => Money::format($share->amount_centavos),
                'owed_to' => $expense->paidBy->name,
            ]);
        }
    }
}
