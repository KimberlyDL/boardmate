<?php

namespace App\Console\Commands;

use App\Support\EnumExporter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('boardmate:export-enums {--path= : Output file (default: ../boardmate-app/src/types/enums.ts)}')]
#[Description('Export app/Enums as TypeScript for the Ionic app')]
class ExportEnums extends Command
{
    public function handle(): int
    {
        $path = $this->option('path') ?: base_path('../boardmate-app/src/types/enums.ts');

        $enums = EnumExporter::discover(app_path('Enums'));

        File::ensureDirectoryExists(dirname($path));
        File::put($path, EnumExporter::toTypeScript($enums));

        $this->components->info(count($enums).' enum(s) written to '.$path);

        return self::SUCCESS;
    }
}
