@php use App\Support\Money; use App\Services\Billing\StatementBuilder as S; $stay = $page['stay']; $t = $page['totals']; $s = $page['settlement']; @endphp
@extends('documents.layout')

@section('title', 'Stay statement · '.($stay['tenant']['name'] ?? ''))

@section('content')
    <h1>Stay statement</h1>
    <table class="meta">
        <tr><td style="width: 32%">Tenant</td><td><strong>{{ $stay['tenant']['name'] }}</strong></td></tr>
        <tr><td>Room</td><td>{{ $stay['room'] ? 'Room '.$stay['room']['label'] : '—' }}@if ($stay['unit']) · {{ $stay['unit']['label'] }}@endif</td></tr>
        <tr><td>Stay</td><td>{{ S::day($stay['moved_in_on']) }} – {{ $stay['moved_out_on'] ? S::day($stay['moved_out_on']) : 'now' }}</td></tr>
    </table>

    <div class="box">
        Billed since move-in: {{ Money::format($t['billed']) }} ·
        Paid: {{ Money::format($t['paid']) }}
        @if ($t['paid_from_deposit'] > 0) · Paid from deposit: {{ Money::format($t['paid_from_deposit']) }}@endif<br>
        Today: <strong>{{ Money::format($t['balance']) }} unpaid</strong>
        @if ($t['overdue'] > 0) ({{ Money::format($t['overdue']) }} overdue)@endif
        · Deposit held: {{ Money::format($t['deposit_held']) }}
    </div>

    <h3>Paid at move-in</h3>
    <table class="lines">
        <thead><tr><th style="width: 18%">Date</th><th>What</th><th>How</th><th class="right">Amount</th></tr></thead>
        <tbody>
            @forelse ($page['move_in'] as $m)
                <tr><td>{{ S::day($m['received_on']) }}</td><td>{{ $m['kind_label'] }}</td><td>{{ $m['method_label'] }}</td><td class="right">{{ Money::format($m['amount_centavos']) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">Nothing recorded.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Bills</h3>
    <table class="lines">
        <thead><tr><th style="width: 16%">Issued</th><th style="width: 38%">Bill</th><th style="width: 16%">Due</th><th class="right">Amount</th><th class="right">Unpaid</th></tr></thead>
        <tbody>
            @forelse ($page['bills'] as $b)
                <tr>
                    <td>{{ S::day($b['issued_on']) }}</td>
                    <td>{{ $b['number'] }} · {{ $b['kind_label'] }}@if ($b['under_review']) <span class="muted">(under review)</span>@endif</td>
                    <td>{{ S::day($b['due_on']) }}</td>
                    <td class="right">{{ Money::format($b['total_centavos']) }}</td>
                    <td class="right">{{ $b['balance_centavos'] > 0 ? Money::format($b['balance_centavos']) : 'Paid' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No bills yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <h3>Payments applied to these bills</h3>
    <table class="lines">
        <thead><tr><th style="width: 18%">Paid</th><th>Receipt</th><th>How</th><th class="right">Applied</th></tr></thead>
        <tbody>
            @forelse ($page['payments'] as $p)
                <tr><td>{{ S::day($p['paid_on']) }}</td><td>{{ $p['receipt_number'] ?? '—' }}</td><td>{{ $p['method_label'] }}</td><td class="right">{{ Money::format($p['applied_centavos']) }}</td></tr>
            @empty
                <tr><td colspan="4" class="muted">No payments yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($s)
        <h3>Deposit settlement</h3>
        <table class="lines">
            <tbody>
                <tr><td>Deposit held</td><td class="right">{{ Money::format($s['deposit_held_centavos']) }}</td></tr>
                <tr><td>Deductions</td><td class="right">{{ Money::format($s['deductions_centavos']) }}</td></tr>
                <tr><td>Applied to unpaid bills</td><td class="right">{{ Money::format($s['applied_centavos']) }}</td></tr>
                <tr><td>Refunded @if ($s['refunded_on']) on {{ S::day($s['refunded_on']) }}@endif</td><td class="right">{{ Money::format($s['refund_centavos']) }}</td></tr>
                <tr><td>Kept @if ($s['kept_reason']) ({{ $s['kept_reason'] }})@endif</td><td class="right">{{ Money::format($s['kept_centavos']) }}</td></tr>
                @if ($s['still_owed_centavos'] > 0)
                    <tr><td>Still owed after settlement</td><td class="right">{{ Money::format($s['still_owed_centavos']) }}</td></tr>
                @endif
            </tbody>
        </table>
    @endif
@endsection

@section('footer')Billed and paid include the first rent paid at move-in.@endsection
