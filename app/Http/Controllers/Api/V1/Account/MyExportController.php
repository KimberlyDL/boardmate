<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Reports\TableFile;
use App\Services\Reports\TenantExports;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * A boarder's own data as files: spending by month, bills and payments
 * (CSV, Excel or PDF), with a preview first.
 *
 * @group Account
 */
class MyExportController extends Controller
{
    public function __construct(private readonly TenantExports $exports, private readonly TableFile $files) {}

    /**
     * My exports
     *
     * What I can download about myself.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::ok(collect(TenantExports::EXPORTS)->map(fn (array $e, string $key) => ['key' => $key] + $e)->values());
    }

    /**
     * Preview one of my exports
     *
     * Its title, the first rows as text, and how many rows there are in all.
     */
    public function preview(Request $request, string $export): JsonResponse
    {
        abort_unless(isset(TenantExports::EXPORTS[$export]), 404);

        return ApiResponse::ok($this->info($export, $request->user())
            + $this->files->preview($this->exports->headers($export), $this->exports->rows($export, $request->user())));
    }

    /**
     * Get a download link
     *
     * `format`: csv (default), xlsx (Excel) or pdf. The link works for a few minutes.
     */
    public function link(Request $request, string $export): JsonResponse
    {
        abort_unless(isset(TenantExports::EXPORTS[$export]), 404);
        $data = $request->validate(['format' => ['nullable', 'in:'.implode(',', TableFile::FORMATS)]]);
        $format = $data['format'] ?? 'csv';
        $minutes = (int) config('dormlink.private_file_url_minutes');

        return ApiResponse::ok([
            'url' => URL::temporarySignedRoute('api.v1.me.exports.download', now()->addMinutes($minutes),
                ['export' => $export, 'user' => $request->user()->id, 'format' => $format]),
            'filename' => TableFile::filename($this->basename($export), $format),
            'expires_in_minutes' => $minutes,
        ]);
    }

    /**
     * Download one of my exports (signed link)
     */
    public function download(Request $request, string $export): Response
    {
        abort_unless(isset(TenantExports::EXPORTS[$export]), 404);
        $user = User::findOrFail((int) $request->query('user'));
        $format = in_array($request->query('format'), TableFile::FORMATS, true) ? (string) $request->query('format') : 'csv';

        return $this->files->download(
            $format,
            TableFile::filename($this->basename($export), $format),
            $this->exports->headers($export),
            $this->exports->rows($export, $user),
            $this->info($export, $user),
        );
    }

    /** @return array{title: string, subtitle: string, totals: bool} */
    private function info(string $export, User $user): array
    {
        $when = $export === 'spending' ? 'Last 12 months' : 'All time';

        return [
            'title' => TenantExports::EXPORTS[$export]['label'],
            'subtitle' => "{$user->name} · {$when} · as of ".ManilaDate::today()->format('M j, Y'),
            'totals' => true, // every money column of these adds up
        ];
    }

    private function basename(string $export): string
    {
        return "dormlink-my-{$export}-".ManilaDate::today()->toDateString();
    }
}
