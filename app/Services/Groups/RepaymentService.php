<?php

namespace App\Services\Groups;

use App\Enums\FilePurpose;
use App\Enums\NotificationEvent;
use App\Enums\RepaymentMethod;
use App\Enums\RepaymentStatus;
use App\Models\ContributionGroup;
use App\Models\GroupMember;
use App\Models\GroupRepayment;
use App\Models\User;
use App\Services\Files\Contracts\FileService;
use App\Services\Notifications\Contracts\NotificationService;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Members paying each other back (D5), in two steps.
 *
 * - A member marks what they paid someone (cash or online, with an optional
 *   reference or proof), up to what they owe that person. The leader or a
 *   moderator may mark one for another member (e.g. one without an account).
 * - The one paid (or the leader or a moderator) confirms or rejects it. When
 *   the one paid is the one recording it, it is confirmed at once.
 * - Only confirmed repayments change balances. Former members can still
 *   settle what they owe. The owner never sees any of this.
 */
class RepaymentService
{
    public function __construct(
        private readonly GroupBalances $balances,
        private readonly FileService $files,
        private readonly NotificationService $notifications,
    ) {}

    /** @param  array{to_member_id: int, from_member_id?: ?int, amount_centavos: int, method: string, reference?: ?string, note?: ?string}  $data */
    public function mark(ContributionGroup $group, GroupMember $me, array $data, ?UploadedFile $proof, User $by): GroupRepayment
    {
        $from = $this->member($group, $data['from_member_id'] ?? $me->id, 'from_member_id');
        $to = $this->member($group, $data['to_member_id'], 'to_member_id');
        if ($from->id !== $me->id && ! ($me->isCurrent() && $me->role->canManage())) {
            throw ValidationException::withMessages(['from_member_id' => 'You can only mark what you paid yourself.']);
        }
        if ($from->id === $to->id) {
            throw ValidationException::withMessages(['to_member_id' => 'Choose someone else.']);
        }
        $owed = $this->balances->owed($group, $from, $to);
        if ($owed <= 0) {
            throw ValidationException::withMessages(['amount_centavos' => "{$from->name} owes {$to->name} nothing."]);
        }
        if ($data['amount_centavos'] > $owed) {
            throw ValidationException::withMessages(['amount_centavos' => "{$from->name} owes {$to->name} ".Money::format($owed).'.']);
        }

        // The one paid saying "I got it" needs no second step.
        $receivedByMe = $to->id === $me->id;
        $repayment = DB::transaction(fn () => GroupRepayment::create([
            'group_id' => $group->id,
            'from_member_id' => $from->id,
            'to_member_id' => $to->id,
            'amount_centavos' => $data['amount_centavos'],
            'method' => RepaymentMethod::from($data['method']),
            'reference' => $data['reference'] ?? null,
            'proof_path' => $proof ? $this->files->store($proof, FilePurpose::GroupProof) : null,
            'note' => $data['note'] ?? null,
            'status' => $receivedByMe ? RepaymentStatus::Confirmed : RepaymentStatus::Pending,
            'marked_by' => $by->id,
            'decided_by' => $receivedByMe ? $by->id : null,
            'decided_at' => $receivedByMe ? now() : null,
        ]));

        $repayment->load('from.user', 'to.user', 'group.room');
        $receivedByMe
            ? $this->tell($repayment->from, $repayment, 'confirmed')
            : $this->tell($repayment->to, $repayment, 'marked');

        return $repayment;
    }

    public function confirm(GroupRepayment $repayment, User $by): GroupRepayment
    {
        $repayment = $this->decide($repayment, $by, RepaymentStatus::Confirmed, null);
        $this->tell($repayment->from, $repayment, 'confirmed');

        return $repayment;
    }

    public function reject(GroupRepayment $repayment, string $reason, User $by): GroupRepayment
    {
        $repayment = $this->decide($repayment, $by, RepaymentStatus::Rejected, $reason);
        $this->tell($repayment->from, $repayment, 'rejected');

        return $repayment;
    }

    /** The one paid, or the leader or a moderator, never the payer alone. */
    public function canDecide(GroupRepayment $repayment, ?GroupMember $me): bool
    {
        if (! $me) {
            return false;
        }

        return $repayment->to_member_id === $me->id
            || ($me->isCurrent() && $me->role->canManage() && $repayment->from_member_id !== $me->id);
    }

    private function decide(GroupRepayment $repayment, User $by, RepaymentStatus $status, ?string $reason): GroupRepayment
    {
        return DB::transaction(function () use ($repayment, $by, $status, $reason) {
            $repayment = GroupRepayment::whereKey($repayment->id)->lockForUpdate()->firstOrFail();
            if ($repayment->status !== RepaymentStatus::Pending) {
                throw ValidationException::withMessages(['repayment' => 'This was already '.mb_strtolower($repayment->status->label()).'.']);
            }
            // Two marked payments may together be more than was owed: check again now.
            if ($status === RepaymentStatus::Confirmed) {
                $repayment->load('group', 'from', 'to');
                $owed = $this->balances->owed($repayment->group, $repayment->from, $repayment->to);
                if ($repayment->amount_centavos > $owed) {
                    throw ValidationException::withMessages(['repayment' => "{$repayment->from->name} now owes {$repayment->to->name} only ".Money::format($owed).'. Reject this one and mark the right amount.']);
                }
            }
            $repayment->update(['status' => $status, 'decided_by' => $by->id, 'decided_at' => now(), 'reject_reason' => $reason]);

            return $repayment->load('from.user', 'to.user', 'group.room');
        });
    }

    private function member(ContributionGroup $group, int $id, string $field): GroupMember
    {
        return $group->members()->whereKey($id)->first()
            ?? throw ValidationException::withMessages([$field => 'Choose a member of this room.']);
    }

    /** In-app only (D5). */
    private function tell(GroupMember $member, GroupRepayment $r, string $what): void
    {
        if (! $member->user) {
            return;
        }
        $this->notifications->send($member->user, NotificationEvent::GroupRepayment, [
            'group_id' => $r->group_id,
            'room_label' => $r->group->room->label(),
            'what' => $what,
            'from' => $r->from->name,
            'to' => $r->to->name,
            'amount' => Money::format($r->amount_centavos),
            'reason' => $r->reject_reason,
        ]);
    }
}
