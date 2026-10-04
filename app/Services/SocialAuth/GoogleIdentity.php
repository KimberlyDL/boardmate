<?php

namespace App\Services\SocialAuth;

/** The verified facts we take from a Google ID token. */
final readonly class GoogleIdentity
{
    public function __construct(
        public string $googleId,
        public string $email,
        public string $name,
        public ?string $pictureUrl = null,
    ) {}
}
