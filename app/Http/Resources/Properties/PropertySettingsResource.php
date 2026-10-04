<?php

namespace App\Http\Resources\Properties;

use App\Models\PropertySettings;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Current values, the guide defaults, and the sections they belong to, so the
 * app can show "default" hints and per-section reset.
 *
 * @mixin PropertySettings
 */
class PropertySettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $values = [];
        foreach (array_keys(PropertySettings::DEFAULTS) as $field) {
            $value = $this->{$field};
            $values[$field] = match (true) {
                $value instanceof BackedEnum => $value->value,
                in_array($field, ['curfew_time', 'visitor_hours_start', 'visitor_hours_end'], true) && $value !== null => substr((string) $value, 0, 5),
                default => $value,
            };
        }

        return [
            'values' => $values,
            'defaults' => PropertySettings::DEFAULTS,
            'sections' => PropertySettings::SECTIONS,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
