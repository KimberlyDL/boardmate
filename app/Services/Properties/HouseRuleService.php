<?php

namespace App\Services\Properties;

use App\Enums\AuditEvent;
use App\Enums\HouseRuleCategory;
use App\Enums\NotificationEvent;
use App\Models\HouseRule;
use App\Models\HouseRuleVersion;
use App\Models\Property;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Notifications\Contracts\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * House rules (D3). The owner or a Manager edits one draft per property and
 * publishes it as the next numbered version; published versions never
 * change, so the version in force on a date can always be shown.
 *
 * Each version also carries the lines written from the curfew and visitor
 * settings (`is_auto`, D2/D4). Changing those settings publishes a new
 * version with the owner's rules as they are and the new lines.
 */
class HouseRuleService
{
    public function __construct(private readonly AuditService $audit, private readonly NotificationService $notifications) {}

    public function draft(Property $property): ?HouseRuleVersion
    {
        return $property->houseRuleVersions()->whereNull('version')->first();
    }

    /** @return list<array{category: HouseRuleCategory, body: string}> the lines the settings give now */
    public function autoLines(Property $property): array
    {
        return HouseRuleAutoLines::for($property->settings);
    }

    /**
     * Replace the draft's rules (created if needed), in the order given.
     * Only the owner's own rules: the automatic lines are added on publish.
     *
     * @param  list<array{category: string, body: string}>  $rules
     */
    public function saveDraft(Property $property, array $rules, ?string $note, User $by): HouseRuleVersion
    {
        return DB::transaction(function () use ($property, $rules, $note, $by) {
            Property::whereKey($property->id)->lockForUpdate()->first();
            $draft = $this->draft($property) ?? $property->houseRuleVersions()->create(['version' => null]);
            $draft->update(['note' => $note, 'updated_by' => $by->id]);

            $draft->rules()->delete();
            foreach (array_values($rules) as $i => $rule) {
                $draft->rules()->create([
                    'category' => HouseRuleCategory::from($rule['category']),
                    'body' => trim($rule['body']),
                    'position' => $i + 1,
                ]);
            }

            return $draft->fresh('rules');
        });
    }

    public function discardDraft(Property $property): void
    {
        $this->draft($property)?->delete();
    }

    /** The draft, with the automatic lines added, becomes the next version and the rules in force. */
    public function publish(Property $property, User $by): HouseRuleVersion
    {
        $version = DB::transaction(function () use ($property, $by) {
            Property::whereKey($property->id)->lockForUpdate()->first();
            $draft = $this->draft($property);
            if (! $draft) {
                throw ValidationException::withMessages(['draft' => 'There is no draft to publish. Edit the rules first.']);
            }
            if (! $draft->rules()->exists()) {
                throw ValidationException::withMessages(['draft' => 'Add at least one rule before publishing.']);
            }

            $this->addAutoLines($draft, $property);

            return $this->number($draft, $property, $by);
        });

        return $this->announce($property, $version);
    }

    /**
     * After the curfew or visitor settings change: publish a new version with
     * the owner's rules in force and the new automatic lines. Nothing happens
     * when the lines come out the same. Returns the new version, if any.
     */
    public function syncAutoLines(Property $property, User $by): ?HouseRuleVersion
    {
        $version = DB::transaction(function () use ($property, $by) {
            Property::whereKey($property->id)->lockForUpdate()->first();
            $property->load('settings');
            $current = $property->houseRuleVersions()->published()->orderByDesc('version')->with('rules')->first();
            $was = $current?->rules->where('is_auto', true)->map(fn (HouseRule $r) => [$r->category->value, $r->body])->values()->all() ?? [];
            $now = array_map(fn ($l) => [$l['category']->value, $l['body']], $this->autoLines($property));
            if ($was === $now) {
                return null;
            }

            // Numbered at once (never a draft row), so an unpublished draft the owner is editing stays as it is.
            $next = $property->houseRuleVersions()->create([
                'version' => (int) $property->houseRuleVersions()->max('version') + 1,
                'note' => 'Curfew or visitor settings changed',
                'updated_by' => $by->id,
                'published_at' => now(),
                'published_by' => $by->id,
            ]);
            foreach ($current?->rules->where('is_auto', false)->values() ?? [] as $i => $rule) {
                $next->rules()->create(['category' => $rule->category, 'body' => $rule->body, 'position' => $i + 1]);
            }
            $this->addAutoLines($next, $property);

            return $next;
        });

        return $version ? $this->announce($property, $version) : null;
    }

    private function addAutoLines(HouseRuleVersion $version, Property $property): void
    {
        $position = (int) $version->rules()->max('position');
        foreach ($this->autoLines($property) as $line) {
            $version->rules()->create(['category' => $line['category'], 'body' => $line['body'], 'position' => ++$position, 'is_auto' => true]);
        }
    }

    private function number(HouseRuleVersion $draft, Property $property, User $by): HouseRuleVersion
    {
        $next = (int) $property->houseRuleVersions()->max('version') + 1;
        $draft->update(['version' => $next, 'published_at' => now(), 'published_by' => $by->id]);

        return $draft;
    }

    /** Audit it and ask every current tenant to read and accept it (one message; the app keeps a banner up). */
    private function announce(Property $property, HouseRuleVersion $version): HouseRuleVersion
    {
        $this->audit->record(AuditEvent::HouseRulesPublished, $version, ['version' => [null, $version->version]], owner: $property->owner,
            note: "{$property->name} · version {$version->version}".($version->note ? " · {$version->note}" : ''));

        $tenants = User::whereIn('id', Tenancy::current()->where('property_id', $property->id)->select('tenant_id'))->get();
        $this->notifications->send($tenants, NotificationEvent::HouseRulesUpdated, [
            'property_name' => $property->name,
            'version' => $version->version,
            'note' => $version->note,
        ]);

        return $version->fresh(['rules', 'publisher']);
    }
}
