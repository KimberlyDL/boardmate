<?php

namespace App\Services\Places;

use App\Models\City;
use App\Models\Province;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Matches a typed province and city (saved before the official list, Phase 4)
 * to the list: "Calapan" or "calapan city" under "oriental mindoro" becomes
 * "Calapan City", Oriental Mindoro. Case, accents, extra spaces and "City of"
 * or "City" wording do not matter. A blank province is filled in when the city
 * name is found in exactly one province. Anything unclear is left for the owner.
 */
class PlaceMatcher
{
    /** Typed names that mean Metro Manila. */
    private const METRO_MANILA = ['metro manila', 'ncr', 'national capital region', 'manila'];

    /** @var Collection<int, Province>|null */
    private ?Collection $provinces = null;

    /**
     * @return array{province: ?string, city: ?string, status: 'ok'|'changed'|'unmatched', note: ?string}
     */
    public function match(?string $province, ?string $city): array
    {
        $province = $this->blankToNull($province);
        $city = $this->blankToNull($city);
        if ($province === null && $city === null) {
            return ['province' => null, 'city' => null, 'status' => 'ok', 'note' => null];
        }

        $foundProvince = $province === null ? null : $this->findProvince($province);
        if ($province !== null && $foundProvince === null) {
            return $this->unmatched($province, $city, "Province \"{$province}\" is not on the list.");
        }

        if ($city === null) {
            return $this->result($province, $city, $foundProvince->name, null);
        }

        $candidates = $this->findCities($city, $foundProvince);
        if ($candidates->count() !== 1) {
            $where = $foundProvince ? " in {$foundProvince->name}" : '';

            return $this->unmatched($province, $city, $candidates->isEmpty()
                ? "City \"{$city}\" is not on the list{$where}."
                : "City \"{$city}\" matches more than one place: ".$candidates->map(fn (City $c) => "{$c->name} ({$c->province->name})")->join(', ').'.');
        }

        $found = $candidates->first();

        return $this->result($province, $city, $found->province->name, $found->name);
    }

    /** Lower case, no accents, single spaces: what two spellings of one name share. */
    public static function key(string $name): string
    {
        return Str::of(Str::ascii($name))->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();
    }

    /** The key without "city of", "city" or "municipality of" wording. */
    public static function baseKey(string $name): string
    {
        return Str::of(self::key($name))
            ->replaceMatches('/^(science city of|island garden city of|city of|municipality of)\s+/', '')
            ->replaceMatches('/\s+(city|municipality)$/', '')
            ->trim()
            ->toString();
    }

    private function findProvince(string $typed): ?Province
    {
        $key = Str::of(self::key($typed))->replaceMatches('/^province of\s+/', '')->replaceMatches('/\s+province$/', '')->toString();
        if (in_array($key, self::METRO_MANILA, true)) {
            $key = 'metro manila';
        }

        return $this->provinces()->first(fn (Province $p) => self::key($p->name) === $key);
    }

    /** @return Collection<int, City> */
    private function findCities(string $typed, ?Province $province): Collection
    {
        $key = self::baseKey($typed);
        $cities = $province ? $province->cities : $this->provinces()->flatMap->cities;

        return $cities->filter(fn (City $c) => self::baseKey($c->name) === $key || self::baseKey($c->official_name) === $key)->values();
    }

    /** @return Collection<int, Province> */
    private function provinces(): Collection
    {
        return $this->provinces ??= Province::with('cities.province')->get();
    }

    /** @return array{province: ?string, city: ?string, status: 'ok'|'changed', note: null} */
    private function result(?string $province, ?string $city, ?string $newProvince, ?string $newCity): array
    {
        $changed = $province !== $newProvince || $city !== $newCity;

        return ['province' => $newProvince, 'city' => $newCity, 'status' => $changed ? 'changed' : 'ok', 'note' => null];
    }

    /** @return array{province: ?string, city: ?string, status: 'unmatched', note: string} */
    private function unmatched(?string $province, ?string $city, string $note): array
    {
        return ['province' => $province, 'city' => $city, 'status' => 'unmatched', 'note' => $note];
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
