<?php

namespace App\Services\Scheduler\Jobs;

use App\Services\Owners\OwnerDocuments;
use App\Services\Scheduler\Contracts\DailyJob;
use Carbon\CarbonImmutable;

/** Minimum personal data: owner proof files are deleted 90 days after the admin's decision. */
class PurgeOwnerDocumentsJob implements DailyJob
{
    public function __construct(private readonly OwnerDocuments $documents) {}

    public function key(): string
    {
        return 'owners.purge_decided_documents';
    }

    public function run(CarbonImmutable $day): string
    {
        return 'Deleted '.$this->documents->purgeDecided($day).' owner document file(s).';
    }
}
