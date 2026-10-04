<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Files
 */
class FileController extends Controller
{
    /**
     * Download a private file
     *
     * Only reachable through a signed, expiring link produced by the Files
     * module (e.g. a profile photo URL). The `signed` middleware rejects
     * tampered or expired links.
     *
     * @unauthenticated
     */
    public function show(string $path): StreamedResponse
    {
        abort_if(str_contains($path, '..'), 404);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, headers: ['Cache-Control' => 'private, max-age=600']);
    }
}
