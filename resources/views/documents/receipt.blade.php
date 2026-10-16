@php use App\Support\Money; @endphp
@extends('documents.layout')

@section('title', "Official receipt {$payment->receipt_number}")

@section('content')
    <h1>Official receipt</h1>
    <div class="muted">No. {{ $payment->receipt_number }}</div>

    @if ($payment->status === \App\Enums\PaymentStatus::Reversed)
        <div class="stamp">REVERSED</div>
        <div class="muted">
            Reversed {{ $payment->reversal?->paid_on?->format('M j, Y') }}@if ($payment->reversal?->note): {{ $payment->reversal->note }}@endif.
            This payment no longer counts toward any bill.
        </div>
    @endif

    <table class="meta">
        <tr><td style="width: 32%">Received from</td><td><strong>{{ $payment->payer->name }}</strong></td></tr>
        <tr><td>Amount</td><td><strong>{{ Money::format($payment->amount_centavos) }}</strong></td></tr>
        <tr><td>Paid on</td><td>{{ $payment->paid_on->format('M j, Y') }}</td></tr>
        <tr><td>Method</td><td>{{ $payment->method->label() }}@if ($payment->reference), ref {{ $payment->reference }}@endif</td></tr>
        @if ($payment->decider)<tr><td>Received by</td><td>{{ $payment->decider->name }}</td></tr>@endif
        @if ($payment->note)<tr><td>Note</td><td>{{ $payment->note }}</td></tr>@endif
    </table>

    @if ($applied->isNotEmpty())
        <table class="lines">
            <thead><tr><th>Applied to bill</th><th class="right">Amount</th></tr></thead>
            <tbody>
                @foreach ($applied as $row)
                    <tr><td>{{ $row['bill'] }}</td><td class="right">{{ Money::format($row['amount_centavos']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @if ($credit_centavos > 0)
        <p>{{ Money::format($credit_centavos) }} is kept as credit for the next bills.</p>
    @endif
@endsection

@section('footer')Receipt numbers are never reused.@endsection
