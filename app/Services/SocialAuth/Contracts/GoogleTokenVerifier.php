<?php

namespace App\Services\SocialAuth\Contracts;

use App\Services\SocialAuth\GoogleIdentity;
use App\Services\SocialAuth\InvalidGoogleToken;

/**
 * SocialAuth module: turns a Google ID token (from the app's Google button,
 * or later the Android Google plugin) into a verified identity.
 */
interface GoogleTokenVerifier
{
    /** @throws InvalidGoogleToken */
    public function verify(string $idToken): GoogleIdentity;
}
