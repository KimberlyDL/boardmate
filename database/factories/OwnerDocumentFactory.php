<?php

namespace Database\Factories;

use App\Enums\OwnerDocumentKind;
use App\Models\OwnerDocument;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnerDocument>
 */
class OwnerDocumentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'owner_profile_id' => fn () => User::factory()->owner()->create()->ownerProfile->id,
            'kind' => OwnerDocumentKind::GovernmentId,
            'path' => 'owner-documents/'.now()->format('Y/m').'/'.fake()->uuid().'.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 120_000,
        ];
    }

    public function kind(OwnerDocumentKind $kind): static
    {
        return $this->state(fn () => ['kind' => $kind]);
    }
}
