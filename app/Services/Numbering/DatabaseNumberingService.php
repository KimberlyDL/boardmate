<?php

namespace App\Services\Numbering;

use App\Enums\DocumentType;
use App\Models\User;
use App\Services\Numbering\Contracts\NumberingService;
use App\Support\ManilaDate;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Counters live in `document_sequences`. The row is locked (SELECT … FOR
 * UPDATE) while it is incremented, so two people issuing receipts for the
 * same owner at the same moment always get different numbers.
 */
class DatabaseNumberingService implements NumberingService
{
    public function next(User $owner, DocumentType $type, ?DateTimeInterface $issuedAt = null): string
    {
        $year = ($issuedAt ? ManilaDate::parse($issuedAt) : ManilaDate::now())->year;

        $number = DB::transaction(function () use ($owner, $type, $year) {
            $key = ['owner_id' => $owner->id, 'type' => $type->value, 'year' => $year];

            // Create the year's counter if missing; a concurrent insert is ignored.
            DB::table('document_sequences')->insertOrIgnore($key + [
                'last_number' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('document_sequences')->where($key)->lockForUpdate()->first();
            $next = $row->last_number + 1;

            DB::table('document_sequences')->where('id', $row->id)->update([
                'last_number' => $next,
                'updated_at' => now(),
            ]);

            return $next;
        });

        return sprintf('%s-%d-%06d', $type->prefix(), $year, $number);
    }
}
