<?php

namespace App\Services\SocialAuth;

use App\Services\SocialAuth\Contracts\GoogleTokenVerifier;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Verifies Google ID tokens locally against Google's published signing keys:
 * signature (RS256), issuer, audience (our client ID), expiry, and that Google
 * has verified the email.
 */
class GoogleIdTokenVerifier implements GoogleTokenVerifier
{
    public const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    private const CACHE_KEY = 'google.oauth.certs';

    public function __construct(private readonly ?string $clientId) {}

    public function verify(string $idToken): GoogleIdentity
    {
        if (blank($this->clientId)) {
            throw new InvalidGoogleToken('Google sign-in is not set up on the server (GOOGLE_CLIENT_ID).');
        }

        try {
            $claims = (array) JWT::decode($idToken, JWK::parseKeySet($this->keys(), 'RS256'));
        } catch (Throwable $e) {
            throw new InvalidGoogleToken('Google sign-in token is invalid or expired.', previous: $e);
        }

        if (! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw new InvalidGoogleToken('Google sign-in token has the wrong issuer.');
        }

        if (($claims['aud'] ?? null) !== $this->clientId) {
            throw new InvalidGoogleToken('Google sign-in token was not issued for BoardMate.');
        }

        if (empty($claims['sub']) || empty($claims['email']) || ($claims['email_verified'] ?? false) !== true) {
            throw new InvalidGoogleToken('Your Google account email is not verified.');
        }

        return new GoogleIdentity(
            googleId: (string) $claims['sub'],
            email: strtolower((string) $claims['email']),
            name: (string) ($claims['name'] ?? strstr((string) $claims['email'], '@', true)),
            pictureUrl: $claims['picture'] ?? null,
        );
    }

    /** Google's JWKS, cached for as long as Google's Cache-Control allows. */
    private function keys(): array
    {
        if ($cached = Cache::get(self::CACHE_KEY)) {
            return $cached;
        }

        $response = Http::timeout(10)->get(self::CERTS_URL)->throw();
        $keys = $response->json();

        preg_match('/max-age=(\d+)/', (string) $response->header('Cache-Control'), $m);
        Cache::put(self::CACHE_KEY, $keys, (int) ($m[1] ?? 3600));

        return $keys;
    }
}
