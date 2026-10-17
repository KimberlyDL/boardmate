<?php

namespace App\Services\Documents\Contracts;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Settlement;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documents module (A9): PDFs made on demand from stored data, never stored
 * themselves. Links to them are signed and expire (like private files), so
 * the app can open them in a browser without a token.
 */
interface DocumentService
{
    /** Official receipt for a confirmed (or since reversed) payment. */
    public function receipt(Payment $payment): Response;

    /** Statement of account for one payer at one property, between two dates (Y-m-d; null = from the start / to today). */
    public function statement(Property $property, User $payer, ?string $from, ?string $to): Response;

    /** The first notice for a bill marked late (NTC-…). */
    public function lateNotice(Bill $bill): Response;

    /** How a deposit was settled after move-out (C8). */
    public function settlementSummary(Settlement $settlement): Response;

    /**
     * A signed, expiring link to one of these documents.
     *
     * @param  'receipt'|'notice'|'statement'|'settlement'  $type
     * @param  array<string, mixed>  $params  route parameters (payment / bill / settlement, or property + payer + from + to)
     */
    public function link(string $type, array $params): string;
}
