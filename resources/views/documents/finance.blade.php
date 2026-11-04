@php use App\Support\Money; use App\Services\Billing\StatementBuilder as S; $t = $page['totals']; @endphp
@extends('documents.layout')

@section('title', "Financial statement · {$title}")

@section('content')
    <h1>Financial statement</h1>
    <table class="meta">
        <tr><td style="width: 32%">For</td><td><strong>{{ $title }}</strong>@isset($page['room']) <span class="muted">· {{ $page['property']['name'] }}</span>@endisset</td></tr>
        <tr><td>Period</td><td>{{ S::day($page['period']['from']) }} – {{ S::day($page['period']['to']) }}</td></tr>
        <tr><td>Balances and deposits held</td><td>as of {{ S::day(now('Asia/Manila')->toDateString()) }}</td></tr>
    </table>

    <table class="lines">
        <thead><tr><th>In the period</th><th class="right">Amount</th></tr></thead>
        <tbody>
            <tr><td>Billed (rent incl. first rent, utilities, charges, corrections)</td><td class="right">{{ Money::format($t['billed']['total']) }}</td></tr>
            <tr><td>Collected (payments {{ Money::format($t['collected']['payments']) }} + first rent {{ Money::format($t['collected']['first_rent']) }})</td><td class="right">{{ Money::format($t['collected']['total']) }}</td></tr>
            <tr><td>Paid from deposits at move-out</td><td class="right">{{ Money::format($t['paid_from_deposit']) }}</td></tr>
            <tr><td>Bills due: {{ Money::format($t['collection']['due']) }}, paid so far {{ Money::format($t['collection']['paid']) }}</td>
                <td class="right">{{ $t['collection']['rate'] === null ? '—' : round($t['collection']['rate'] * 100, 1).'% collected' }}</td></tr>
            <tr><td>Deposits received / refunded / kept</td>
                <td class="right">{{ Money::format($t['deposits']['received']) }} / {{ Money::format($t['deposits']['refunded']) }} / {{ Money::format($t['deposits']['kept']) }}</td></tr>
            <tr><td>Utilities billed to tenants</td><td class="right">{{ Money::format($t['utilities']['billed']) }}</td></tr>
            @if ($t['utilities']['provider_cost'] !== null)
                <tr><td>Provider bills paid (shares waived {{ Money::format($t['utilities']['waived']) }})</td><td class="right">{{ Money::format($t['utilities']['provider_cost']) }}</td></tr>
            @endif
            <tr><td>Repair costs</td><td class="right">{{ Money::format($t['repair_costs']) }}</td></tr>
        </tbody>
    </table>

    <table class="lines">
        <thead><tr><th>Today</th><th class="right">Amount</th></tr></thead>
        <tbody>
            <tr><td>Unpaid</td><td class="right">{{ Money::format($t['outstanding']['total']) }}</td></tr>
            <tr><td>Overdue (not counting bills under review)</td><td class="right">{{ Money::format($t['outstanding']['overdue']) }}</td></tr>
            @if ($t['credit_held'] !== null)
                <tr><td>Credit held (paid ahead)</td><td class="right">{{ Money::format($t['credit_held']) }}</td></tr>
            @endif
            <tr><td>Deposits held</td><td class="right">{{ Money::format($t['deposits']['held']) }}</td></tr>
            @if (isset($t['occupancy']) && $t['occupancy']['places'] > 0)
                <tr><td>Places taken</td><td class="right">{{ $t['occupancy']['occupied'] }} of {{ $t['occupancy']['places'] }}</td></tr>
            @endif
        </tbody>
    </table>

    <div style="page-break-inside: avoid">
        <h3>Billed and collected, last 12 months</h3>
        <img src="{{ $charts['trend'] }}" style="width: 100%">
    </div>

    <table style="width: 100%; margin-top: 4mm">
        <tr>
            <td style="width: 50%; vertical-align: top; padding-right: 3mm">
                <h3>Billed in the period</h3>
                <img src="{{ $charts['billed'] }}" style="width: 100%">
            </td>
            <td style="width: 50%; vertical-align: top; padding-left: 3mm">
                <h3>Unpaid today, by age</h3>
                <img src="{{ $charts['ageing'] }}" style="width: 100%">
            </td>
        </tr>
    </table>

    @if ($rows->isNotEmpty())
        <h3 style="page-break-before: auto">{{ $rowsLabel }}</h3>
        <table class="lines">
            <thead>
                @isset($rows->first()['stay'])
                    <tr><th>Tenant</th><th>Stay</th><th class="right">Billed</th><th class="right">Paid</th><th class="right">Unpaid</th><th class="right">Deposit held</th></tr>
                @else
                    <tr><th></th><th class="right">Billed</th><th class="right">Collected</th><th class="right">Collected of due</th><th class="right">Unpaid today</th><th class="right">Deposits held</th></tr>
                @endisset
            </thead>
            <tbody>
                @foreach ($rows as $r)
                    @isset($r['stay'])
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td>{{ S::day($r['stay']['moved_in_on']) }} – {{ $r['stay']['moved_out_on'] ? S::day($r['stay']['moved_out_on']) : 'now' }}</td>
                            <td class="right">{{ Money::format($r['billed']) }}</td>
                            <td class="right">{{ Money::format($r['paid']) }}</td>
                            <td class="right">{{ Money::format($r['balance']) }}</td>
                            <td class="right">{{ Money::format($r['deposit_held']) }}</td>
                        </tr>
                    @else
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td class="right">{{ Money::format($r['billed']['total']) }}</td>
                            <td class="right">{{ Money::format($r['collected']['total']) }}</td>
                            <td class="right">{{ $r['collection']['rate'] === null ? '—' : round($r['collection']['rate'] * 100, 1).'%' }}</td>
                            <td class="right">{{ Money::format($r['outstanding']['total']) }}</td>
                            <td class="right">{{ Money::format($r['deposits']['held']) }}</td>
                        </tr>
                    @endisset
                @endforeach
            </tbody>
        </table>
    @endif
@endsection

@section('footer')Collected counts money received, not deposits applied at move-out. Billed counts bills by issue date.@endsection
