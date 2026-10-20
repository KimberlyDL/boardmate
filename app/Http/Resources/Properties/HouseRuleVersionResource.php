<?php

namespace App\Http\Resources\Properties;

use App\Models\HouseRule;
use App\Models\HouseRuleVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A version of a property's house rules (D3), or the draft (`version` null).
 * Rules are in the owner's order; the app groups them by category.
 *
 * @mixin HouseRuleVersion
 */
class HouseRuleVersionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'version' => $this->version,
            'is_draft' => $this->isDraft(),
            'note' => $this->note,
            'published_at' => $this->published_at?->toIso8601String(),
            'published_by' => $this->whenLoaded('publisher', fn () => $this->publisher?->name),
            'rules' => $this->whenLoaded('rules', fn () => $this->rules->map(fn (HouseRule $r) => [
                'category' => $r->category->value,
                'category_label' => $r->category->label(),
                'body' => $r->body,
                'is_auto' => $r->is_auto, // written from the curfew and visitor settings
            ])->values()),
        ];
    }
}
