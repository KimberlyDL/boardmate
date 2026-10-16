@php use App\Support\Money; use App\Services\Billing\StatementBuilder as S; @endphp
@extends('documents.layout')

@section('title', "Statement of account · {$payer->name}")

@section('content')
    <h1>Statement of account</h1>
    <table class="meta">
        <tr><td style="width: 32%">For</td><td><strong>{{ $payer->name }}</strong></td></tr>
        <tr><td>Period</td><td>{{ S::day($statement['from']) }} – {{ S::day($statement['to']) }}</td></tr>
        <tr><td>Opening balance</td><td>{{ S::describe($statement['opening_centavos']) }}</td></tr>
    </table>

    <table class="lines">
        <thead>
            <tr><th style="width: 16%">Date</th><th style="width: 20%">Reference</th><th>Details</th><th class="right">Amount</th><th class="right">Balance</th></tr>
        </thead>
        <tbody>
            @forelse ($statement['entries'] as $e)
                <tr>
                    <td>{{ S::day($e['date']) }}</td>
                    <td>{{ $e['reference'] }}</td>
                    <td>{{ $e['description'] }}</td>
                    <td class="right">{{ Money::format($e['amount_centavos']) }}</td>
                    <td class="right">{{ S::describe($e['balance_centavos']) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">Nothing in this period.</td></tr>
            @endforelse
            <tr class="total">
                <td colspan="3">Closing balance</td>
                <td class="right"></td>
                <td class="right">{{ S::describe($statement['closing_centavos']) }}</td>
            </tr>
        </tbody>
    </table>

    <div class="box">
        Charges in this period: {{ Money::format($statement['charges_centavos']) }} ·
        Payments and credits: {{ Money::format($statement['payments_centavos']) }}<br>
        Today: <strong>{{ Money::format($standing['owed_centavos']) }} unpaid</strong>
        @if ($standing['credit_centavos'] > 0)
            and {{ Money::format($standing['credit_centavos']) }} credit
        @endif
    </div>
@endsection

@section('footer')Positive amounts are charges; negative amounts are payments and credits.@endsection
