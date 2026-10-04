<?php

namespace Database\Factories;

use App\Enums\CaretakerAccessLevel;
use App\Enums\OwnerVerificationStatus;
use App\Enums\UserRole;
use App\Models\OwnerCaretaker;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** A signed-up boarder: role, consent, and an empty boarder profile. */
    public function boarder(): static
    {
        return $this->state(fn (array $attributes) => [
            'active_role' => UserRole::Boarder->value,
            'consented_at' => now(),
            'privacy_policy_version' => config('boardmate.privacy_policy_version'),
        ])->afterCreating(function (User $user) {
            $user->assignRole(UserRole::Boarder->value);
            $user->boarderProfile()->create();
        });
    }

    /** An owner (who signed up as a boarder, then applied), with the given review status. */
    public function owner(OwnerVerificationStatus $status = OwnerVerificationStatus::Verified): static
    {
        return $this->boarder()->state(fn () => [
            'active_role' => UserRole::Owner->value,
            'phone' => '09171234567',
        ])->afterCreating(function (User $user) use ($status) {
            $user->assignRole(UserRole::Owner->value);
            $user->ownerProfile()->create(['application_notes' => 'Six-bed boarding house near the university.'])
                ->forceFill(['verification_status' => $status, 'submitted_at' => now()])->save();
        });
    }

    public function admin(): static
    {
        return $this->state(fn () => ['active_role' => UserRole::PlatformAdmin->value])
            ->afterCreating(fn (User $user) => $user->assignRole(UserRole::PlatformAdmin->value));
    }

    /** A boarder account that also works as a caretaker for $owner. */
    public function caretakerFor(User $owner, CaretakerAccessLevel $level = CaretakerAccessLevel::Collector): static
    {
        return $this->boarder()->state(fn () => ['active_role' => UserRole::Caretaker->value])
            ->afterCreating(function (User $user) use ($owner, $level) {
                $user->assignRole(UserRole::Caretaker->value);
                OwnerCaretaker::create(['owner_id' => $owner->id, 'caretaker_id' => $user->id, 'access_level' => $level]);
            });
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'suspended_at' => now(),
        ]);
    }
}
