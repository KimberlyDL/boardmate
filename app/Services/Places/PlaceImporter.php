<?php

namespace App\Services\Places;

use App\Models\City;
use App\Models\Province;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads database/data/ph-places.json (provinces and cities/municipalities from
 * the PSA's PSGC, via psgc.cloud) into the `provinces` and `cities` tables.
 *
 * Safe to run again after the file is refreshed: rows are matched by PSGC code,
 * names are updated, and nothing is deleted (a saved property keeps its text).
 * The file itself is built once from the source: "City of Calapan" is shown as
 * "Calapan City", Metro Manila is listed as a province, and independent cities
 * sit under the province people expect (Cebu City under Cebu).
 */
class PlaceImporter
{
    public const FILE = 'data/ph-places.json';

    public const CACHE_KEY = 'places.list';

    /** @return array{provinces: int, cities: int} */
    public function import(): array
    {
        $data = json_decode((string) file_get_contents(database_path(self::FILE)), true);
        if (! is_array($data) || ! isset($data['provinces'], $data['cities'])) {
            throw new RuntimeException('The place list '.self::FILE.' is missing or unreadable.');
        }

        DB::transaction(function () use ($data) {
            $now = now();
            Province::upsert(
                array_map(fn (array $p) => ['psgc_code' => $p['code'], 'name' => $p['name'], 'created_at' => $now, 'updated_at' => $now], $data['provinces']),
                ['psgc_code'],
                ['name', 'updated_at'],
            );
            $provinceIds = Province::pluck('id', 'psgc_code');

            foreach (array_chunk($data['cities'], 500) as $chunk) {
                City::upsert(
                    array_map(fn (array $c) => [
                        'psgc_code' => $c['code'],
                        'province_id' => $provinceIds[$c['province']],
                        'name' => $c['name'],
                        'official_name' => $c['official'],
                        'is_city' => $c['city'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ], $chunk),
                    ['psgc_code'],
                    ['province_id', 'name', 'official_name', 'is_city', 'updated_at'],
                );
            }
        });

        Cache::forget(self::CACHE_KEY);

        return ['provinces' => count($data['provinces']), 'cities' => count($data['cities'])];
    }
}
