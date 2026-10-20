<?php

namespace App\Services\Reports\Money;

use App\Enums\PropertyAbility;
use App\Enums\TenancyPaymentKind;
use App\Models\Tenancy;
use App\Models\TenancyPayment;
use App\Services\Reports\Report;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Each stay's deposit: received at move-in, and how it was settled (C1, C8). */
class DepositLedgerReport extends Report
{
    public function key(): string
    {
        return 'deposits';
    }

    public function label(): string
    {
        return 'Deposit ledger';
    }

    public function description(): string
    {
        return 'Every stay\'s deposit: what was received, and how it was settled after move-out.';
    }

    public function ability(): PropertyAbility
    {
        return PropertyAbility::ViewCollections;
    }

    public function usesDates(): bool
    {
        return false;
    }

    public function headers(): array
    {
        return ['Property', 'Tenant', 'Room', 'Moved in', 'Moved out', 'Deposit received (PHP)', 'Received on', 'Deductions (PHP)', 'Applied to bills (PHP)',
            'Refunded (PHP)', 'Kept (PHP)', 'Kept because', 'Still owed (PHP)', 'Settled on'];
    }

    public function rows(Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $names = $properties->pluck('name', 'id');
        $tenancies = Tenancy::whereIn('property_id', $properties->pluck('id'))
            ->with(['tenant:id,name', 'room', 'settlement'])
            ->addSelect(['deposit_centavos' => TenancyPayment::selectRaw('coalesce(sum(amount_centavos), 0)')
                ->whereColumn('tenancy_id', 'tenancies.id')->where('kind', TenancyPaymentKind::Deposit->value)])
            ->addSelect(['deposit_on' => TenancyPayment::selectRaw('min(received_on)')
                ->whereColumn('tenancy_id', 'tenancies.id')->where('kind', TenancyPaymentKind::Deposit->value)])
            ->orderBy('property_id')->orderBy('moved_in_on')->orderBy('id');

        foreach ($tenancies->lazy(200) as $t) {
            $s = $t->settlement;
            yield [
                $names[$t->property_id] ?? '',
                $t->tenant?->name,
                $t->room?->label(),
                $this->day($t->moved_in_on),
                $this->day($t->moved_out_on),
                $this->pesos((int) $t->deposit_centavos),
                $t->deposit_on ? substr((string) $t->deposit_on, 0, 10) : '',
                $s ? $this->pesos($s->deductions_centavos) : '',
                $s ? $this->pesos($s->applied_centavos) : '',
                $s ? $this->pesos($s->refund_centavos) : '',
                $s ? $this->pesos($s->kept_centavos) : '',
                $s?->kept_reason,
                $s ? $this->pesos($s->still_owed_centavos) : '',
                $s ? $this->day($s->created_at?->setTimezone('Asia/Manila')) : '',
            ];
        }
    }
}
