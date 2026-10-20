<?php

namespace App\Http\Controllers\Api\V1;

use App\Authorization\PropertyAccess;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Services\Reports\Report;
use App\Services\Reports\ReportRegistry;
use App\Support\ManilaDate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV reports (A10). The app asks for a short-lived signed link (like the
 * PDFs, so it also works in the Android app) and opens it; the download
 * streams the file. Each report needs its own ability on every property it
 * covers: the owner and Managers get all of them, Collectors only
 * collections and outstanding balances.
 *
 * @group Reports
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportRegistry $reports) {}

    /**
     * Reports I can download
     *
     * Each with the properties it may cover for me. Reports with no such
     * property are left out.
     */
    public function index(Request $request): JsonResponse
    {
        $properties = Property::query()->visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'owner_id']);

        $list = collect($this->reports->all())
            ->map(fn (Report $r) => [
                'key' => $r->key(),
                'label' => $r->label(),
                'description' => $r->description(),
                'uses_dates' => $r->usesDates(),
                'properties' => $this->allowed($request, $r, $properties)->map(fn (Property $p) => ['id' => $p->id, 'name' => $p->name])->values(),
            ])
            ->filter(fn ($r) => $r['properties']->isNotEmpty())
            ->values();

        return ApiResponse::ok($list);
    }

    /**
     * Get a download link
     *
     * `property_id` (omit for every property the report may cover for me),
     * and for reports with dates `from` and `to` (YYYY-MM-DD; default this
     * month). The link works for a few minutes.
     */
    public function link(Request $request, string $report): JsonResponse
    {
        $found = $this->reports->find($report);
        abort_unless($found, 404);
        $data = $request->validate([
            'property_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $allowed = $this->allowed($request, $found, Property::query()->visibleTo($request->user())->orderBy('name')->get(['id', 'name', 'owner_id']));
        $properties = isset($data['property_id']) ? $allowed->where('id', (int) $data['property_id']) : $allowed;
        if ($properties->isEmpty()) {
            throw ValidationException::withMessages(['property_id' => 'You cannot download this report for that property.']);
        }

        [$from, $to] = $this->period($found, $data);
        $params = ['report' => $found->key(), 'properties' => $properties->pluck('id')->implode(',')]
            + ($from ? ['from' => $from->toDateString(), 'to' => $to->toDateString()] : []);
        $minutes = (int) config('dormlink.private_file_url_minutes');

        return ApiResponse::ok([
            'url' => URL::temporarySignedRoute('api.v1.reports.download', now()->addMinutes($minutes), $params),
            'filename' => $this->filename($found, $properties, $from, $to),
            'expires_in_minutes' => $minutes,
        ]);
    }

    /**
     * Download a report (signed link)
     *
     * UTF-8 CSV with a byte-order mark so Excel shows ₱ and ñ correctly.
     */
    public function download(Request $request, string $report): StreamedResponse
    {
        $found = $this->reports->find($report);
        abort_unless($found, 404);
        $ids = array_filter(array_map('intval', explode(',', (string) $request->query('properties'))));
        $properties = Property::withTrashed()->whereIn('id', $ids)->orderBy('name')->get();
        $from = $request->query('from') ? CarbonImmutable::parse($request->query('from'), 'Asia/Manila')->startOfDay() : null;
        $to = $request->query('to') ? CarbonImmutable::parse($request->query('to'), 'Asia/Manila')->startOfDay() : null;

        return response()->streamDownload(function () use ($found, $properties, $from, $to) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $found->headers());
            foreach ($found->rows($properties, $from, $to) as $row) {
                fputcsv($out, array_map(fn ($v) => $v ?? '', $row));
            }
            fclose($out);
        }, $this->filename($found, $properties, $from, $to), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return Collection<int, Property> */
    private function allowed(Request $request, Report $report, Collection $properties): Collection
    {
        return $properties->filter(fn (Property $p) => PropertyAccess::can($request->user(), $report->ability(), $p))->values();
    }

    /** @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable} */
    private function period(Report $report, array $data): array
    {
        if (! $report->usesDates()) {
            return [null, null];
        }
        [$defaultFrom, $defaultTo] = $report->defaultPeriod(ManilaDate::today());
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from'], 'Asia/Manila')->startOfDay() : $defaultFrom;
        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'], 'Asia/Manila')->startOfDay() : $defaultTo;

        return [$from, $to];
    }

    private function filename(Report $report, Collection $properties, ?CarbonImmutable $from, ?CarbonImmutable $to): string
    {
        $where = $properties->count() === 1 ? Str::slug($properties->first()->name) : 'all-properties';
        $when = $from ? "{$from->toDateString()}_to_{$to->toDateString()}" : 'as-of-'.ManilaDate::today()->toDateString();

        return "dormlink-{$report->key()}-{$where}-{$when}.csv";
    }
}
