<?php

namespace App\Http\Controllers\Api\V1\Groups;

use App\Authorization\GroupAccess;
use App\Enums\FilePurpose;
use App\Enums\RepaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ContributionGroup;
use App\Models\GroupMember;
use App\Models\GroupRepayment;
use App\Services\Files\Contracts\FileService;
use App\Services\Groups\GroupBalances;
use App\Services\Groups\GroupService;
use App\Services\Groups\OwnerBillPoster;
use App\Services\Groups\RepaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Balances and repayments inside a room group (D5). Members only: the owner
 * and caretakers never see these.
 *
 * @group Room groups
 */
class RepaymentController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
        private readonly OwnerBillPoster $poster,
        private readonly GroupBalances $balances,
        private readonly RepaymentService $repayments,
        private readonly FileService $files,
    ) {}

    /**
     * Who owes whom
     *
     * Pair by pair, largest first. A member sees what they owe and are owed;
     * the leader and moderators see every pair. `me` totals what I owe and
     * what I am owed.
     */
    public function balances(Request $request, ContributionGroup $group): JsonResponse
    {
        $me = $this->member($request, $group);
        $this->groups->sync($group);
        $this->poster->sync($group);
        $names = $group->members()->pluck('name', 'id');
        $pairs = collect($this->balances->pairs($group));
        $seesAll = $me->isCurrent() && $me->role->canManage();
        $mine = $pairs->filter(fn ($p) => $p['from'] === $me->id || $p['to'] === $me->id);

        return ApiResponse::ok([
            'me' => [
                'owe_centavos' => (int) $mine->where('from', $me->id)->sum('amount_centavos'),
                'owed_to_me_centavos' => (int) $mine->where('to', $me->id)->sum('amount_centavos'),
            ],
            'pairs' => ($seesAll ? $pairs : $mine)->map(fn ($p) => [
                'from' => ['id' => $p['from'], 'name' => $names[$p['from']] ?? null],
                'to' => ['id' => $p['to'], 'name' => $names[$p['to']] ?? null],
                'amount_centavos' => $p['amount_centavos'],
                'involves_me' => $p['from'] === $me->id || $p['to'] === $me->id,
            ])->values(),
        ]);
    }

    /**
     * Repayments
     *
     * Newest first; `status` pending / confirmed / rejected. A member sees
     * their own; the leader and moderators see all.
     */
    public function index(Request $request, ContributionGroup $group): JsonResponse
    {
        $me = $this->member($request, $group);
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'confirmed', 'rejected'])]]);
        $seesAll = $me->isCurrent() && $me->role->canManage();

        $list = $group->repayments()
            ->when(! $seesAll, fn ($q) => $q->where(fn ($q) => $q->where('from_member_id', $me->id)->orWhere('to_member_id', $me->id)))
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['from', 'to', 'decider:id,name'])
            ->limit(200)
            ->get();

        return ApiResponse::ok($list->map(fn (GroupRepayment $r) => $this->present($r, $me))->values());
    }

    /**
     * Mark a repayment
     *
     * What I paid `to_member_id` (up to what I owe them): `method` cash or
     * online, optional `reference`, `proof` and `note`. The leader or a
     * moderator may mark one for another member with `from_member_id`. If I
     * am the one paid, it is confirmed at once.
     */
    public function store(Request $request, ContributionGroup $group): JsonResponse
    {
        $me = $this->member($request, $group);
        $data = $request->validate([
            'to_member_id' => ['required', 'integer'],
            'from_member_id' => ['nullable', 'integer'],
            'amount_centavos' => ['required', 'integer', 'min:1', 'max:100000000'],
            'method' => ['required', Rule::enum(RepaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'proof' => ['nullable', ...array_slice($this->files->rules(FilePurpose::GroupProof), 1)],
        ]);

        $repayment = $this->repayments->mark($group, $me, $data, $request->file('proof'), $request->user());

        return ApiResponse::created($this->present($repayment, $me), $repayment->status->value === 'confirmed'
            ? 'Repayment recorded.'
            : "Marked as paid. {$repayment->to->name} confirms it.");
    }

    /**
     * Confirm a repayment
     *
     * By the one paid, or the leader or a moderator (not the payer).
     */
    public function confirm(Request $request, ContributionGroup $group, GroupRepayment $repayment): JsonResponse
    {
        $me = $this->member($request, $group);
        abort_unless($this->repayments->canDecide($repayment, $me), 403, 'Only the one paid, the leader or a moderator can confirm this.');

        return ApiResponse::ok($this->present($this->repayments->confirm($repayment, $request->user()), $me), 'Repayment confirmed.');
    }

    /**
     * Reject a repayment
     *
     * `reason` is required and shown to the payer.
     */
    public function reject(Request $request, ContributionGroup $group, GroupRepayment $repayment): JsonResponse
    {
        $me = $this->member($request, $group);
        abort_unless($this->repayments->canDecide($repayment, $me), 403, 'Only the one paid, the leader or a moderator can reject this.');
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return ApiResponse::ok($this->present($this->repayments->reject($repayment, $data['reason'], $request->user()), $me), 'Repayment rejected.');
    }

    /** Members only (current or former): staff get 403, others 404. */
    private function member(Request $request, ContributionGroup $group): GroupMember
    {
        $me = GroupAccess::member($request->user(), $group);
        abort_unless($me, GroupAccess::isStaff($request->user(), $group) ? 403 : 404, 'Repayments are private to the room.');

        return $me;
    }

    /** @return array<string, mixed> */
    private function present(GroupRepayment $r, GroupMember $me): array
    {
        $r->loadMissing(['from', 'to', 'decider:id,name']);

        return [
            'id' => $r->id,
            'from' => ['id' => $r->from_member_id, 'name' => $r->from->name],
            'to' => ['id' => $r->to_member_id, 'name' => $r->to->name],
            'amount_centavos' => $r->amount_centavos,
            'method' => $r->method->value,
            'method_label' => $r->method->label(),
            'reference' => $r->reference,
            'proof_url' => $this->files->url($r->proof_path, FilePurpose::GroupProof),
            'note' => $r->note,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'decided_by' => $r->decider?->name,
            'reject_reason' => $r->reject_reason,
            'can_decide' => $r->status->value === 'pending' && $this->repayments->canDecide($r, $me),
            'created_at' => $r->created_at?->toIso8601String(),
        ];
    }
}
