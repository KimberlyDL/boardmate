<?php

it('reports the API and database as healthy in Manila time', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('data.api_version', 'v1')
        ->assertJsonPath('data.database', 'ok')
        ->assertJsonPath('data.timezone', 'Asia/Manila');
});

it('returns JSON errors for unknown API routes', function () {
    $this->get('/api/v1/does-not-exist')
        ->assertNotFound()
        ->assertJsonStructure(['message']);
});

it('allows the Ionic dev origin through CORS', function () {
    $this->withHeaders(['Origin' => 'http://localhost:8100'])
        ->getJson('/api/v1/health')
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:8100');
});

it('allows the Android app origin through CORS', function () {
    $this->withHeaders(['Origin' => 'https://localhost'])
        ->getJson('/api/v1/health')
        ->assertHeader('Access-Control-Allow-Origin', 'https://localhost');
});

it('allows the website and, when set, preview addresses, but not other sites (W1)', function () {
    config([
        'cors.allowed_origins' => ['https://dormlink.vercel.app', 'https://localhost'],
        'cors.allowed_origins_patterns' => ['#^https://dormlink-[a-z0-9-]+\.vercel\.app$#'],
    ]);

    $this->withHeaders(['Origin' => 'https://dormlink.vercel.app'])->getJson('/api/v1/health')
        ->assertHeader('Access-Control-Allow-Origin', 'https://dormlink.vercel.app');
    $this->withHeaders(['Origin' => 'https://dormlink-git-main-leynard.vercel.app'])->getJson('/api/v1/health')
        ->assertHeader('Access-Control-Allow-Origin', 'https://dormlink-git-main-leynard.vercel.app');
    $this->withHeaders(['Origin' => 'https://evil.vercel.app'])->getJson('/api/v1/health')
        ->assertHeaderMissing('Access-Control-Allow-Origin');
});

it('writes the TypeScript enum file', function () {
    $path = storage_path('framework/testing/enums.ts');

    $this->artisan('dormlink:export-enums', ['--path' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toContain('AUTO-GENERATED');
});
