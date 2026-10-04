<?php

namespace App\Enums;

/** Numbered documents, each with its own per-owner, per-year sequence. */
enum DocumentType: string
{
    case Receipt = 'receipt';
    case Bill = 'bill';
    case Notice = 'notice';

    /** OR-2026-000123 (Features Guide F5: receipt and bill numbering per owner). */
    public function prefix(): string
    {
        return match ($this) {
            self::Receipt => 'OR',
            self::Bill => 'BILL',
            self::Notice => 'NTC',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Official receipt',
            self::Bill => 'Bill',
            self::Notice => 'Formal notice',
        };
    }
}
