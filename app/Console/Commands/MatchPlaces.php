<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Services\Places\PlaceMatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('dormlink:match-places {--apply : Save the changes (without it, only shows them)}')]
#[Description('Match the province and city typed on each property to the official list (shows the changes first)')]
class MatchPlaces extends Command
{
    public function handle(PlaceMatcher $matcher): int
    {
        $changes = [];
        $unmatched = [];
        $alreadyOk = 0;

        foreach (Property::withTrashed()->orderBy('id')->lazyById() as $property) {
            $result = $matcher->match($property->province, $property->city);
            $label = "#{$property->id} {$property->name}".($property->trashed() ? ' (deleted)' : '');
            $before = $this->place($property->city, $property->province);

            if ($result['status'] === 'ok') {
                $alreadyOk++;
            } elseif ($result['status'] === 'unmatched') {
                $unmatched[] = [$label, $before, $result['note']];
            } else {
                $changes[] = [$label, $before, $this->place($result['city'], $result['province'])];
                if ($this->option('apply')) {
                    // A data fix, not an owner edit: the listing's "updated" time stays as it was.
                    Property::withoutTimestamps(fn () => $property->forceFill(['province' => $result['province'], 'city' => $result['city']])->saveQuietly());
                }
            }
        }

        if ($changes !== []) {
            $this->components->info($this->option('apply') ? 'Changed:' : 'Will change (run again with --apply to save):');
            $this->table(['Property', 'Now', 'Becomes'], $changes);
        }
        if ($unmatched !== []) {
            $this->components->warn('Not matched (the owner picks from the list next time they edit the location):');
            $this->table(['Property', 'Now', 'Why'], $unmatched);
        }

        $this->components->info(sprintf(
            '%d already on the list, %d %s, %d not matched.',
            $alreadyOk,
            count($changes),
            $this->option('apply') ? 'changed' : 'to change',
            count($unmatched),
        ));

        return self::SUCCESS;
    }

    private function place(?string $city, ?string $province): string
    {
        return implode(', ', array_filter([$city, $province])) ?: '(blank)';
    }
}
