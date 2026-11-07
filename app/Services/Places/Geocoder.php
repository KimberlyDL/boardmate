<?php

namespace App\Services\Places;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Finds a place or address in the Philippines ("Naujan", "J.P. Rizal St,
 * Calapan") so the Finder can measure distance from it (address search).
 *
 * Uses OpenStreetMap's Nominatim (config services.geocoder; LocationIQ works
 * the same with a key). Its fair-use rules are kept: answers are cached for
 * 30 days, the whole app asks at most once a second, and requests name the app.
 */
class Geocoder
{
    public const MAX_RESULTS = 6;

    private const CACHE_DAYS = 30;

    /** Address parts shown after the name, smallest first; "state" is the province in the Philippines. */
    private const AREA_PARTS = ['neighbourhood', 'quarter', 'village', 'suburb', 'hamlet', 'town', 'city', 'municipality', 'county', 'state'];

    /**
     * @return list<array{name: string, area: string, label: string, kind: string, latitude: float, longitude: float}>
     *
     * @throws ValidationException when the address service is busy or cannot be reached
     */
    public function search(string $text): array
    {
        $text = Str::squish($text);
        $key = 'geocode:'.md5(mb_strtolower($text));

        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $results = $this->ask($text);
        // "Nothing found" is kept only a day: the map data keeps growing.
        Cache::put($key, $results, now()->addDays($results === [] ? 1 : self::CACHE_DAYS));

        return $results;
    }

    /** @return list<array{name: string, area: string, label: string, kind: string, latitude: float, longitude: float}> */
    private function ask(string $text): array
    {
        // One lookup a second for the whole app; a person waits up to a second for their turn.
        $allowed = false;
        for ($try = 0; $try < 3 && ! $allowed; $try++) {
            $allowed = RateLimiter::attempt('geocoder', 1, fn () => true, 1);
            if (! $allowed) {
                usleep(400_000);
            }
        }
        if (! $allowed) {
            throw ValidationException::withMessages(['q' => 'Address search is busy. Try again in a moment.']);
        }

        $config = config('services.geocoder');
        try {
            $rows = Http::timeout(8)
                ->withUserAgent($config['user_agent'])
                ->acceptJson()
                ->get(rtrim($config['url'], '/').'/search', array_filter([
                    'q' => $text,
                    'format' => 'jsonv2',
                    'countrycodes' => 'ph',
                    'addressdetails' => 1,
                    'limit' => self::MAX_RESULTS,
                    'accept-language' => 'en',
                    'key' => $config['key'] ?: null,
                ]))
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException) {
            throw ValidationException::withMessages(['q' => 'Address search is not available right now. Try again later.']);
        }

        return collect(is_array($rows) ? $rows : [])
            ->map(fn (array $row) => $this->place($row))
            ->filter()
            ->unique('label')
            ->values()
            ->all();
    }

    /** @return array{name: string, area: string, label: string, kind: string, latitude: float, longitude: float}|null */
    private function place(array $row): ?array
    {
        if (! isset($row['lat'], $row['lon'])) {
            return null;
        }
        $address = $row['address'] ?? [];
        $name = trim((string) ($row['name'] ?? '')) ?: Str::before((string) ($row['display_name'] ?? ''), ',');
        if ($name === '') {
            return null;
        }

        $area = collect(self::AREA_PARTS)
            ->map(fn (string $part) => trim((string) ($address[$part] ?? '')))
            ->filter(fn (string $part) => $part !== '' && $part !== $name)
            ->unique()
            ->join(', ');

        return [
            'name' => $name,
            'area' => $area,
            'label' => $area === '' ? $name : "{$name}, {$area}",
            'kind' => (string) ($row['addresstype'] ?? $row['type'] ?? 'place'),
            'latitude' => round((float) $row['lat'], 7),
            'longitude' => round((float) $row['lon'], 7),
        ];
    }
}
