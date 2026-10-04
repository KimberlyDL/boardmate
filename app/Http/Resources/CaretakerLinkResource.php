<?php

namespace App\Http\Resources;

use App\Enums\FilePurpose;
use App\Models\OwnerCaretaker;
use App\Models\PropertyCaretaker;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An owner–caretaker link. `person` is the caretaker for the owner's list, or
 * the owner for the caretaker's "who I work for" list.
 *
 * @mixin OwnerCaretaker
 */
class CaretakerLinkResource extends JsonResource
{
    public function __construct($resource, private readonly string $side = 'caretaker')
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $person = $this->side === 'caretaker' ? $this->caretaker : $this->owner;

        return [
            'id' => $this->id,
            'access_level' => $this->access_level->value,
            'access_level_label' => $this->access_level->label(),
            'person' => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'phone' => $person->phone,
                'photo_url' => app(FileService::class)->url($person->photo_path, FilePurpose::ProfilePhoto),
                'business_name' => $this->side === 'owner' ? $person->ownerProfile?->business_name : null,
            ],
            // The owner's properties this caretaker is assigned to, with the level for each.
            'properties' => PropertyCaretaker::query()
                ->where('caretaker_id', $this->caretaker_id)
                ->whereHas('property', fn ($q) => $q->where('owner_id', $this->owner_id))
                ->with('property:id,name')
                ->get()
                ->map(fn (PropertyCaretaker $a) => [
                    'id' => $a->property_id,
                    'name' => $a->property->name,
                    'access_level' => $a->access_level->value,
                    'access_level_label' => $a->access_level->label(),
                ])->values(),
            'since' => $this->created_at?->toIso8601String(),
        ];
    }
}
