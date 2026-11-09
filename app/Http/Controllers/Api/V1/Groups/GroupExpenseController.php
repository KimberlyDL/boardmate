<?php

namespace App\Http\Controllers\Api\V1\Groups;

use App\Authorization\GroupAccess;
use App\Enums\FilePurpose;
use App\Enums\GroupExpenseKind;
use App\Enums\SplitMethod;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContributionGroup;
use App\Models\ExpenseShare;
use App\Models\GroupExpense;
use App\Models\GroupMember;
use App\Services\Files\Contracts\FileService;
use App\Services\Groups\GroupService;
use App\Services\Groups\MemberExpenseService;
use App\Services\Groups\OwnerBillPoster;
use App\Services\Groups\ShareProgress;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The lines a room group shares (D5).
 *
 * @group Room groups
 */
class GroupExpenseController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly OwnerBillPoster $poster,
        private readonly MemberExpenseService $expenses,
        private readonly FileService $files,
        private readonly ShareProgress $progress,
    ) {}

    /**
     * The group's lines
     *
     * Newest first. Members see every line with each member's share and
     * their own, and how much of each share is paid (`paid_centavos`).
     * Lines with a due date (owner bills, "collect first") add `due_on`,
     * `is_settled` and `is_overdue`. The owner and caretakers see only the
     * owner's bills posted to the group, without the shares.
     */
    public function index(Request $request, ContributionGroup $group): JsonResponse
    {
        $user = $request->user();
        $me = GroupAccess::member($user, $group);
        abort_unless($me || GroupAccess::isStaff($user, $group), 404);
        $this->groups->sync($group);
        $this->poster->sync($group);

        $lines = $group->expenses()
            ->when(! $me, fn ($q) => $q->where('kind', GroupExpenseKind::OwnerBill->value))
            ->with(['paidBy', 'shares.member', 'bill' => fn ($q) => $q->withBalance()])
            ->limit(200)
            ->get();
        $this->paid = $me ? $this->progress->paid($group) : [];

        return ApiResponse::ok($lines->map(fn (GroupExpense $e) => $this->present($e, $me)), meta: ['view' => $me ? 'member' : 'staff']);
    }

    /**
     * Change how a line is split
     *
     * `method`: equal, occupant_days, weights (`weights`: member id => weight)
     * or custom (`amounts`: member id => centavos, adding up to the line). By
     * the leader or a moderator, until someone has paid back for it.
     */
    public function split(Request $request, ContributionGroup $group, GroupExpense $expense): JsonResponse
    {
        abort_unless(GroupAccess::canManage($request->user(), $group), 403, 'Only the room leader or a moderator can change a split.');
        $data = $request->validate([
            'method' => ['required', Rule::enum(SplitMethod::class)->only([SplitMethod::Equal, SplitMethod::OccupantDays, SplitMethod::Weights, SplitMethod::Custom])],
            'weights' => ['required_if:method,weights', 'array'],
            'weights.*' => ['numeric', 'min:0', 'max:100'],
            'amounts' => ['required_if:method,custom', 'array'],
            'amounts.*' => ['integer', 'min:0', 'max:100000000'],
        ]);
        $method = SplitMethod::from($data['method']);
        $params = match ($method) {
            SplitMethod::Weights => array_map('strval', $data['weights']),
            SplitMethod::Custom => array_map('intval', $data['amounts']),
            default => [],
        };

        $expense = $this->poster->resplit($expense, $method, $params);

        $this->paid = $this->progress->paid($group);

        return ApiResponse::ok($this->present($this->fresh($expense), GroupAccess::member($request->user(), $group)), 'Split saved.');
    }

    /**
     * Add a shared bill
     *
     * Something a member paid for the room (SC-31: e.g. internet the boarders
     * pay themselves), or, with `due_on`, a bill due later to "collect first"
     * (leader or moderator only): members pay their share to the collector
     * (`paid_by_member_id`, default the one adding it) before it is due, then
     * the collector marks it paid. `participants` (member ids) default to
     * everyone there now; `method` equal (default), weights (`weights`) or custom
     * (`amounts`, adding up). `paid_by_member_id`: the leader or a moderator
     * may record someone else's. Optional `proof` (photo or PDF, private).
     */
    public function store(Request $request, ContributionGroup $group): JsonResponse
    {
        $me = $this->currentMember($request, $group);
        $this->groups->sync($group); // "everyone there now" includes whoever just joined the room
        $today = ManilaDate::today();
        $data = $request->validate($this->rules(true) + [
            'spent_on' => ['required_without:due_on', 'nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today->toDateString(), 'after_or_equal:'.$today->subDays(90)->toDateString()],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.$today->toDateString(), 'before_or_equal:'.$today->addYear()->toDateString()],
            'paid_by_member_id' => ['nullable', 'integer'],
            'proof' => ['nullable', ...array_slice($this->files->rules(FilePurpose::GroupProof), 1)],
        ]);

        $expense = $this->expenses->add($group, $me, $data, $request->file('proof'), $request->user());
        $this->paid = $this->progress->paid($group);

        return ApiResponse::created($this->present($this->fresh($expense), $me),
            $expense->due_on ? 'Bill added. Everyone sees their share to pay before it is due.' : 'Expense added.');
    }

    /**
     * Mark a "collect first" bill as paid
     *
     * The collector (or the leader or a moderator) paid the bill: `paid_on`
     * and an optional `proof` (the receipt). Members who have not paid their
     * share yet still owe it to the collector.
     */
    public function markPaid(Request $request, ContributionGroup $group, GroupExpense $expense): JsonResponse
    {
        $me = $this->currentMember($request, $group);
        abort_unless($me->role->canManage() || $me->id === $expense->paid_by_member_id, 403, 'Only the collector, the room leader or a moderator can mark this paid.');
        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.ManilaDate::today()->toDateString(), 'after_or_equal:'.$expense->spent_on->toDateString()],
            'proof' => ['nullable', ...array_slice($this->files->rules(FilePurpose::GroupProof), 1)],
        ]);
        $expense = $this->expenses->markPaid($expense, $data['paid_on'], $request->file('proof'));
        $this->paid = $this->progress->paid($group);

        return ApiResponse::ok($this->present($this->fresh($expense), $me), 'Marked as paid.');
    }

    /**
     * Edit a shared expense
     *
     * By the leader, a moderator or whoever added it, until the one who paid
     * has been paid back. After that, add a correction.
     */
    public function update(Request $request, ContributionGroup $group, GroupExpense $expense): JsonResponse
    {
        $me = $this->currentMember($request, $group);
        $this->allowEdit($me, $expense, $request);
        $data = $request->validate($this->rules(false) + [
            'spent_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.ManilaDate::today()->toDateString()],
            'due_on' => ['sometimes', 'date_format:Y-m-d', 'before_or_equal:'.ManilaDate::today()->addYear()->toDateString()],
        ]);
        $expense = $this->expenses->edit($expense, $data);
        $this->paid = $this->progress->paid($group);

        return ApiResponse::ok($this->present($this->fresh($expense), $me), 'Saved.');
    }

    /**
     * Delete a shared expense
     *
     * Same rule as editing.
     */
    public function destroy(Request $request, ContributionGroup $group, GroupExpense $expense): JsonResponse
    {
        $me = $this->currentMember($request, $group);
        $this->allowEdit($me, $expense, $request);
        $this->expenses->delete($expense);

        return ApiResponse::message('Expense deleted.');
    }

    /**
     * Add a correction
     *
     * `changes`: member id => signed centavos (+ owes more, − owes less), and
     * a `reason`. For an owner bill's line the changes must add up to ₱0. By
     * the leader or a moderator.
     */
    public function correct(Request $request, ContributionGroup $group, GroupExpense $expense): JsonResponse
    {
        abort_unless(GroupAccess::canManage($request->user(), $group), 403, 'Only the room leader or a moderator can correct a line.');
        $data = $request->validate([
            'changes' => ['required', 'array', 'max:20'],
            'changes.*' => ['integer', 'between:-100000000,100000000'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $fix = $this->expenses->correct($expense, $data['changes'], $data['reason'], $request->user());

        $this->paid = $this->progress->paid($group);

        return ApiResponse::created($this->present($this->fresh($fix), GroupAccess::member($request->user(), $group)), 'Correction added.');
    }

    private function currentMember(Request $request, ContributionGroup $group): GroupMember
    {
        $me = GroupAccess::member($request->user(), $group);
        abort_unless($me, GroupAccess::isStaff($request->user(), $group) ? 403 : 404, 'Only members of this room can do this.');
        abort_unless($me->isCurrent(), 403, 'You have left this room.');

        return $me;
    }

    private function allowEdit(GroupMember $me, GroupExpense $expense, Request $request): void
    {
        abort_unless($me->role->canManage() || $expense->created_by === $request->user()->id, 403, 'Only the room leader, a moderator or whoever added it can change this.');
    }

    private function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'description' => [$required, 'string', 'max:160'],
            'amount_centavos' => [$required, 'integer', 'min:1', 'max:100000000'],
            'participants' => ['nullable', 'array', 'max:20'],
            'participants.*' => ['integer'],
            'method' => ['nullable', Rule::enum(SplitMethod::class)->only([SplitMethod::Equal, SplitMethod::Weights, SplitMethod::Custom])],
            'weights' => ['required_if:method,weights', 'array'],
            'weights.*' => ['numeric', 'min:0', 'max:100'],
            'amounts' => ['required_if:method,custom', 'array'],
            'amounts.*' => ['integer', 'min:0', 'max:100000000'],
        ];
    }

    private function fresh(GroupExpense $expense): GroupExpense
    {
        return $expense->fresh(['paidBy', 'shares.member', 'bill' => fn ($q) => $q->withBalance()]);
    }

    /** @var array<int, array<int, int>> line id => member id => paid of their share (see ShareProgress) */
    private array $paid = [];

    /** @return array<string, mixed> */
    private function present(GroupExpense $e, ?GroupMember $me): array
    {
        // When the bill itself must be paid: the owner's bill's due date, or a "collect first" line's.
        $dueOn = $e->kind === GroupExpenseKind::OwnerBill ? $e->bill?->due_on : $e->due_on;
        $settled = match ($e->kind) {
            GroupExpenseKind::OwnerBill => ! $e->bill || $e->bill->balanceCentavos() <= 0,
            GroupExpenseKind::Collection => $e->paid_on !== null,
            default => true,
        };
        $data = [
            'id' => $e->id,
            'kind' => $e->kind->value,
            'kind_label' => $e->kind->label(),
            'is_locked' => $e->isLocked(),
            'bill_number' => $e->bill?->number,
            'description' => $e->description,
            'amount_centavos' => $e->amount_centavos,
            'spent_on' => $e->spent_on->toDateString(),
            'paid_by' => ['id' => $e->paid_by_member_id, 'name' => $e->paidBy->name],
            'adjusts_expense_id' => $e->adjusts_expense_id,
            'reason' => $e->reason,
            'due_on' => $dueOn?->toDateString(),
            'paid_on' => $e->paid_on?->toDateString(),
            // The bill itself is paid: to the owner (owner bill) or by the collector (collect first).
            'is_settled' => $settled,
            'is_overdue' => ! $settled && $dueOn !== null && $dueOn->lt(ManilaDate::today()),
        ];
        if (! $me) {
            return $data; // staff: the owner's own bills, no shares
        }

        return $data + [
            'proof_url' => $this->files->url($e->proof_path, FilePurpose::GroupProof),
            'added_by_me' => $e->created_by === $me->user_id,
            'split_method' => $e->split_method->value,
            'split_label' => $e->split_method->label(),
            'shares' => $e->shares->map(fn (ExpenseShare $s) => [
                'member_id' => $s->member_id,
                'name' => $s->member->name,
                'amount_centavos' => $s->amount_centavos,
                'paid_centavos' => $this->sharePaid($e, $s),
            ])->values(),
            'my_share_centavos' => (int) $e->shares->firstWhere('member_id', $me->id)?->amount_centavos,
            'my_paid_centavos' => ($mine = $e->shares->firstWhere('member_id', $me->id)) ? $this->sharePaid($e, $mine) : 0,
            // Collected so far: the collector's own share plus what the others paid them.
            'collected_centavos' => (int) $e->shares->sum(fn (ExpenseShare $s) => max(0, $this->sharePaid($e, $s))),
            'can_mark_paid' => $e->isCollecting() && $me->isCurrent() && ($me->role->canManage() || $me->id === $e->paid_by_member_id),
        ];
    }

    /** The paid part of a share; the payer's own share counts as paid. */
    private function sharePaid(GroupExpense $e, ExpenseShare $s): int
    {
        return $s->member_id === $e->paid_by_member_id ? $s->amount_centavos : ($this->paid[$e->id][$s->member_id] ?? 0);
    }
}
