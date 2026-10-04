<?php

namespace App\Services\Numbering\Contracts;

use App\Enums\DocumentType;
use App\Models\User;
use DateTimeInterface;

/**
 * Numbering module: sequential document numbers per owner and year, e.g.
 * OR-2026-000123. Numbers are never reused (a voided receipt keeps its number).
 */
interface NumberingService
{
    /**
     * Reserve the next number. Call it inside the same database transaction
     * that saves the document, so a rolled-back save does not burn a number.
     */
    public function next(User $owner, DocumentType $type, ?DateTimeInterface $issuedAt = null): string;
}
