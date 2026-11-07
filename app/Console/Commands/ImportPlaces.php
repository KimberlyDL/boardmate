<?php

namespace App\Console\Commands;

use App\Services\Places\PlaceImporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dormlink:import-places')]
#[Description('Load the province and city list (database/data/ph-places.json) again after it is refreshed (safe to run twice)')]
class ImportPlaces extends Command
{
    public function handle(PlaceImporter $importer): int
    {
        $counts = $importer->import();
        $this->components->info("Places loaded: {$counts['provinces']} provinces, {$counts['cities']} cities and municipalities.");

        return self::SUCCESS;
    }
}
