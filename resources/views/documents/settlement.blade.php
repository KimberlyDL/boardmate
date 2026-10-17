@php use App\Support\Money; @endphp
@extends('documents.layout')

@section('title', "Deposit settlement · {$tenancy->tenant->name}")

@section('content')
    <h1>Deposit settlement</h1>
    <div class="muted">{{ $settlement->created_at->timezone('Asia/Manila')->format('M j, Y') }}</div>

    <table class="meta">
        <tr><td style="width: 32%">Boarder</td><td><strong>{{ $tenancy->tenant->name }}</strong></td></tr>
        <tr><td>Room</td><td>{{ $tenancy->room->label() }} · {{ $tenancy->unit->label }}</td></tr>
        <tr><td>Stay</td><td>{{ $tenancy->moved_in_on->format('M j, Y') }} – {{ $tenancy->moved_out_on?->format('M j, Y') }}</td></tr>
    </table>

    @if (! empty($settlement->deductions))
        <table class="lines">
            <thead><tr><th>Deductions charged at move-out</th><th>Bill</th><th class="right">Amount</th></tr></thead>
            <tbody>
                @foreach ($settlement->deductions as $d)
                    <tr><td>{{ $d['description'] }} ({{ $d['reason'] }})</td><td>{{ $d['bill_number'] }}</td><td class="right">{{ Money::format($d['amount_centavos']) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="lines">
        <thead><tr><th>Deposit</th><th class="right">Amount</th></tr></thead>
        <tbody>
            <tr><td>Deposit held</td><td class="right">{{ Money::format($settlement->deposit_held_centavos) }}</td></tr>
            <tr><td>Used to pay unpaid bills and deductions</td><td class="right">-{{ Money::format($settlement->applied_centavos) }}</td></tr>
            @if ($settlement->kept_centavos > 0)
                <tr><td>Kept by the owner: {{ $settlement->kept_reason }}</td><td class="right">-{{ Money::format($settlement->kept_centavos) }}</td></tr>
            @endif
            @if ($settlement->credit_returned_centavos > 0)
                <tr><td>Unused credit returned</td><td class="right">{{ Money::format($settlement->credit_returned_centavos) }}</td></tr>
            @endif
            <tr class="total"><td>Refunded to the boarder</td><td class="right">{{ Money::format($settlement->refund_centavos) }}</td></tr>
        </tbody>
    </table>

    @if ($settlement->refund_centavos > 0)
        <p>
            Refunded by {{ $settlement->refund_method?->label() }} on {{ $settlement->refunded_on?->format('M j, Y') }}
            @if ($settlement->refund_reference)
                (ref {{ $settlement->refund_reference }})
            @endif
        </p>
    @endif

    <div class="box">
        @if ($settlement->still_owed_centavos > 0)
            <strong>Still owed: {{ Money::format($settlement->still_owed_centavos) }}.</strong> The deposit did not cover everything; the rest stays on the bills.
        @else
            Nothing is owed after this settlement.
        @endif
    </div>

    @if ($settlement->note)
        <p class="muted">{{ $settlement->note }}</p>
    @endif
    @if ($settlement->settler)
        <p class="muted">Settled by {{ $settlement->settler->name }}.</p>
    @endif
@endsection

@section('footer')Deductions are charges with reasons, and are on the boarder's statement of account.@endsection
