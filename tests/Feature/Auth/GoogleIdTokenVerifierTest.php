<?php

use App\Services\SocialAuth\GoogleIdTokenVerifier;
use App\Services\SocialAuth\InvalidGoogleToken;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;

/*
| Real signature checks against a throwaway RSA test key (tests/Fixtures),
| served as a fake Google JWKS endpoint. Keys are files rather than generated
| at runtime because openssl_pkey_new() needs an openssl.cnf on Windows.
*/

beforeEach(function () {
    $this->privatePem = file_get_contents(dirname(__DIR__, 2).'/Fixtures/google-test-key.pem');
    $details = openssl_pkey_get_details(openssl_pkey_get_private($this->privatePem))['rsa'];

    $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');

    Http::fake([GoogleIdTokenVerifier::CERTS_URL => Http::response([
        'keys' => [[
            'kty' => 'RSA', 'alg' => 'RS256', 'use' => 'sig', 'kid' => 'test-key',
            'n' => $b64($details['n']), 'e' => $b64($details['e']),
        ]],
    ], 200, ['Cache-Control' => 'public, max-age=60'])]);

    $this->verifier = new GoogleIdTokenVerifier('client-123.apps.googleusercontent.com');

    $this->sign = fn (array $overrides = [], ?string $pem = null) => JWT::encode(array_merge([
        'iss' => 'https://accounts.google.com',
        'aud' => 'client-123.apps.googleusercontent.com',
        'sub' => '1098765',
        'email' => 'Kim@Gmail.com',
        'email_verified' => true,
        'name' => 'Kim Santos',
        'iat' => time(),
        'exp' => time() + 3600,
    ], $overrides), $pem ?? $this->privatePem, 'RS256', 'test-key');
});

it('accepts a valid Google ID token', function () {
    $identity = $this->verifier->verify(($this->sign)());

    expect($identity->googleId)->toBe('1098765')
        ->and($identity->email)->toBe('kim@gmail.com')
        ->and($identity->name)->toBe('Kim Santos');
});

it('rejects tokens for another app, from another issuer, expired, or with unverified email', function (array $claims) {
    $this->verifier->verify(($this->sign)($claims));
})->throws(InvalidGoogleToken::class)->with([
    'wrong audience' => [['aud' => 'someone-else']],
    'wrong issuer' => [['iss' => 'https://evil.example.com']],
    'expired' => [['exp' => time() - 10, 'iat' => time() - 4000]],
    'email not verified' => [['email_verified' => false]],
]);

it('rejects a token signed with a different key', function () {
    $otherPem = file_get_contents(dirname(__DIR__, 2).'/Fixtures/google-other-key.pem');

    $this->verifier->verify(($this->sign)([], $otherPem));
})->throws(InvalidGoogleToken::class);

it('refuses everything when GOOGLE_CLIENT_ID is not set', function () {
    (new GoogleIdTokenVerifier(null))->verify(($this->sign)());
})->throws(InvalidGoogleToken::class);
