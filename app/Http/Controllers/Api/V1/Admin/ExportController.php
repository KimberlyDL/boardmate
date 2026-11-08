<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Reports\AdminExports;
use App\Services\Reports\TableFile;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exports for the platform admin (improvements Phase 5) as CSV, Excel or
 * PDF, with a preview.
 *
 * @group Admin
 */
class ExportController extends Controller
{
    public function __construct(
        private readonly AdminExports $exports,
        private readonly TableFile $files,
    ) {}

    /**
     * List exports
     */
    public function index(): JsonResponse
    {
        return ApiResponse::ok(collect($this->exports->all())
            ->map(fn (array $e, string $key) => ['key' => $key] + $e)
            ->values());
    }

    /**
     * Get a download link
     *
     * `format`: csv (default), xlsx (Excel) or pdf. The link works for a few minutes.
     */
    public function link(Request $request, string $export): JsonResponse
    {
        abort_unless($this->exports->exists($export), 404);
        $format = $request->validate(['format' => ['nullable', 'in:'.implode(',', TableFile::FORMATS)]])['format'] ?? 'csv';
        $minutes = (int) config('dormlink.private_file_url_minutes');

        return ApiResponse::ok([
            'url' => URL::temporarySignedRoute('api.v1.admin.exports.download', now()->addMinutes($minutes), ['export' => $export, 'format' => $format]),
            'filename' => TableFile::filename($this->basename($export), $format),
            'expires_in_minutes' => $minutes,
        ]);
    }

    /**
     * Preview an export
     *
     * Its title, the first rows as text, and how many rows there are in all.
     */
    public function preview(string $export): JsonResponse
    {
        abort_unless($this->exports->exists($export), 404);

        return ApiResponse::ok($this->info($export) + $this->files->preview($this->exports->headers($export), $this->exports->rows($export)));
    }

    /**
     * Download an export (signed link)
     *
     * CSV (UTF-8 with a byte-order mark so Excel shows ñ), Excel or PDF.
     */
    public function download(Request $request, string $export): Response
    {
        abort_unless($this->exports->exists($export), 404);
        $format = in_array($request->query('format'), TableFile::FORMATS, true) ? (string) $request->query('format') : 'csv';

        return $this->files->download(
            $format,
            TableFile::filename($this->basename($export), $format),
            $this->exports->headers($export),
            $this->exports->rows($export),
            $this->info($export),
        );
    }

    /** @return array{title: string, subtitle: string} */
    private function info(string $export): array
    {
        return ['title' => $this->exports->all()[$export]['label'], 'subtitle' => 'Platform · As of '.ManilaDate::today()->format('M j, Y')];
    }

    private function basename(string $export): string
    {
        return "dormlink-admin-{$export}-".ManilaDate::today()->toDateString();
    }
}
