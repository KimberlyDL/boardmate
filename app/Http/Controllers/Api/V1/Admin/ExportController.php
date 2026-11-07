<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Reports\AdminExports;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV exports for the platform admin (improvements Phase 5).
 *
 * @group Admin
 */
class ExportController extends Controller
{
    public function __construct(private readonly AdminExports $exports) {}

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
     * The link works for a few minutes.
     */
    public function link(string $export): JsonResponse
    {
        abort_unless($this->exports->exists($export), 404);
        $minutes = (int) config('dormlink.private_file_url_minutes');

        return ApiResponse::ok([
            'url' => URL::temporarySignedRoute('api.v1.admin.exports.download', now()->addMinutes($minutes), ['export' => $export]),
            'filename' => $this->filename($export),
            'expires_in_minutes' => $minutes,
        ]);
    }

    /**
     * Download an export (signed link)
     *
     * UTF-8 CSV with a byte-order mark so Excel shows ñ correctly.
     */
    public function download(string $export): StreamedResponse
    {
        abort_unless($this->exports->exists($export), 404);

        return response()->streamDownload(function () use ($export) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $this->exports->headers($export));
            foreach ($this->exports->rows($export) as $row) {
                fputcsv($out, array_map(fn ($v) => $v ?? '', $row));
            }
            fclose($out);
        }, $this->filename($export), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filename(string $export): string
    {
        return "dormlink-admin-{$export}-".ManilaDate::today()->toDateString().'.csv';
    }
}
