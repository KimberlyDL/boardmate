<?php

namespace App\Services\Groups;

use App\Enums\SplitMethod;
use App\Models\GroupMember;
use App\Services\Split\SplitEngine;
use App\Services\Split\SplitResult;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Divides a group line among members (D5) with the Split module, so shares
 * always add up to the line exactly (C5: centavos round down, leftovers to
 * the largest remainders).
 *
 * - Equal / by days stayed: among the members present at least one day of
 *   the line's dates (by days: weighted by those days, SC-06).
 * - By weight / fixed amounts: the numbers given per member; fixed amounts
 *   must add up to the line.
 * - If nobody was present, the member who paid it carries it.
 */
class GroupSplitter
{
    public function __construct(private readonly SplitEngine $engine) {}

    /**
     * @param  Collection<int, GroupMember>  $members  the group's members (current and former)
     * @param  array<int|string, int|string>  $params  member id => weight or centavos, for weights / custom
     * @return array<int, int> member id => centavos
     */
    public function split(int $total, SplitMethod $method, CarbonImmutable $from, CarbonImmutable $to, Collection $members, int $payerId, array $params = []): array
    {
        $byId = $members->keyBy('id');
        $present = $members->filter(fn (GroupMember $m) => SplitEngine::daysPresent($from, $to, $m->joined_on, $m->left_on) > 0);

        $result = match ($method) {
            SplitMethod::Equal => $present->isEmpty() ? null : $this->engine->equal($total, $present->pluck('id')->all()),
            SplitMethod::OccupantDays => $present->isEmpty() ? null : $this->engine->occupantDays($total, $from, $to,
                $present->mapWithKeys(fn (GroupMember $m) => [$m->id => [$m->joined_on, $m->left_on]])->all()),
            SplitMethod::Weights => $this->engine->weights($total, $this->known($params, $byId, 'weights')),
            SplitMethod::Custom => $this->custom($total, $this->known($params, $byId, 'amounts')),
            default => throw ValidationException::withMessages(['method' => 'Choose equal, by days stayed, by weight or fixed amounts.']),
        };

        if ($result === null) {
            return [$payerId => $total]; // nobody else was there: the one who paid carries it
        }

        $shares = [];
        foreach ($result->shares as $id => $amount) {
            if ($amount !== 0) {
                $shares[(int) $id] = $amount;
            }
        }

        return $shares ?: [$payerId => $total];
    }

    /**
     * A member's own expense among the members chosen to share it: equal,
     * by weight or fixed amounts (no dates involved).
     *
     * @param  list<int>  $participantIds
     * @param  array<int|string, int|string>  $params
     * @return array<int, int> member id => centavos
     */
    public function splitAmong(int $total, SplitMethod $method, array $participantIds, array $params = []): array
    {
        if ($participantIds === []) {
            throw ValidationException::withMessages(['participants' => 'Choose who shares this.']);
        }
        $chosen = collect($participantIds)->flip();
        $only = fn (array $p, string $field) => array_diff(array_keys($p), $participantIds) === [] && $p !== []
            ? $p
            : throw ValidationException::withMessages([$field => 'Give a value for each person who shares this, and only them.']);

        $result = match ($method) {
            SplitMethod::Equal => $this->engine->equal($total, $participantIds),
            SplitMethod::Weights => $this->engine->weights($total, $only($params, 'weights')),
            SplitMethod::Custom => $this->custom($total, $only($params, 'amounts')),
            default => throw ValidationException::withMessages(['method' => 'Choose equal, by weight or fixed amounts.']),
        };

        return collect($result->shares)
            ->filter(fn (int $amount, $id) => $amount !== 0 && $chosen->has((int) $id))
            ->mapWithKeys(fn (int $amount, $id) => [(int) $id => $amount])
            ->all();
    }

    private function custom(int $total, array $amounts): SplitResult
    {
        $result = $this->engine->custom($total, array_map('intval', $amounts));
        if (! $result->isBalanced()) {
            throw ValidationException::withMessages(['amounts' => 'The amounts add up to '.Money::format($result->allocated()).'; the line is '.Money::format($total).'.']);
        }

        return $result;
    }

    /** Only members of this group, and at least one. */
    private function known(array $params, Collection $byId, string $field): array
    {
        $unknown = array_diff(array_keys($params), $byId->keys()->all());
        if ($params === [] || $unknown !== []) {
            throw ValidationException::withMessages([$field => 'Give a value for each member who shares this, and only members of this room.']);
        }

        return $params;
    }
}
