<?php

namespace App\Services\Documents\Contracts;

use App\Models\Bill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Room;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Models\User;
use Illuminate\Support\Collection;
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
     * Financial statement for the owner and Managers: the finance figures of
     * the properties (by property, or by room for one property) with charts.
     *
     * @param  Collection<int, Property>  $properties
     */
    public function financeStatement(Collection $properties, string $from, string $to): Response;

    /** Financial statement of one room, by stay. */
    public function roomFinanceStatement(Room $room, string $from, string $to): Response;

    /** One stay's money from move-in: bills, payments and the deposit. */
    public function stayFinanceStatement(Tenancy $tenancy): Response;

    /**
     * A signed, expiring link to one of these documents.
     *
     * @param  'receipt'|'notice'|'statement'|'settlement'|'finance'|'finance-room'|'finance-stay'  $type
     * @param  array<string, mixed>  $params  route parameters (payment / bill / settlement / room / tenancy, or property + payer + from + to, or properties + from + to)
     */
    public function link(string $type, array $params): string;
}
