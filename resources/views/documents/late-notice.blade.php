@php use App\Support\Money; @endphp
@extends('documents.layout')

@section('title', "Notice {$bill->late_notice_number}")

@section('content')
    <h1>Notice of late payment</h1>
    <div class="muted">No. {{ $bill->late_notice_number }} · {{ $bill->late_at->timezone('Asia/Manila')->format('M j, Y') }}</div>

    <table class="meta">
        <tr><td style="width: 32%">To</td><td><strong>{{ $bill->payer->name }}</strong>@if ($bill->room) · Room {{ $bill->room->label() }}@endif</td></tr>
        <tr><td>Bill</td><td>{{ $bill->number }} ({{ $bill->kind->label() }})</td></tr>
        <tr><td>Due on</td><td>{{ $bill->due_on->format('M j, Y') }}</td></tr>
        <tr><td>Days overdue when marked late</td><td>{{ $days_overdue }}</td></tr>
        <tr><td>Still unpaid today</td><td><strong>{{ Money::format($balance_centavos) }}</strong></td></tr>
    </table>

    <p>
        This bill was not paid within the grace days, so it is now marked late. No fee is added.
        Please pay the amount above, or talk to {{ $issuer['name'] }} if you have already paid or think the bill is wrong.
    </p>
@endsection

@section('footer')This notice is a record of the late payment. It is not a notice to vacate.@endsection
