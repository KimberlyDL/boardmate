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

it('writes the TypeScript enum file', function () {
    $path = storage_path('framework/testing/enums.ts');

    $this->artisan('boardmate:export-enums', ['--path' => $path])->assertSuccessful();

    expect(file_get_contents($path))->toContain('AUTO-GENERATED');
});
