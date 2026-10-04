<?php

use App\Support\EnumExporter;
use Tests\Fixtures\SampleStatus;

it('exports a backed enum with its labels as TypeScript', function () {
    $ts = EnumExporter::toTypeScript([SampleStatus::class]);

    expect($ts)
        ->toContain('export const SampleStatus = {')
        ->toContain("  NotReady: 'not_ready',")
        ->toContain('export type SampleStatus = (typeof SampleStatus)[keyof typeof SampleStatus]')
        ->toContain('export const SampleStatusLabels: Record<SampleStatus, string> = {')
        ->toContain("  'not_ready': 'Not ready',");
});

it('discovers only backed enums in a directory', function () {
    $found = EnumExporter::discover(dirname(__DIR__).'/Fixtures', 'Tests\\Fixtures');

    expect($found)->toBe([SampleStatus::class]);
});
