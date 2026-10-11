<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Campus;
use App\Services\Audit\AuditDiff;
use App\Services\Audit\Contracts\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Platform admin: the campuses the Dorm Finder measures distance from. The
 * first campus becomes the default; removing the default passes it to the
 * oldest remaining campus.
 *
 * @group Admin
 */
class CampusController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * List campuses
     */
    public function index(): JsonResponse
    {
        return ApiResponse::ok(self::all());
    }

    /**
     * Add a campus
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());

        $campus = DB::transaction(function () use ($data) {
            $campus = new Campus($data);
            $campus->is_default = ($data['is_default'] ?? false) || ! Campus::where('is_default', true)->exists();
            if ($campus->is_default) {
                Campus::where('is_default', true)->update(['is_default' => false]);
            }
            $campus->save();

            return $campus;
        });

        $this->audit->record(AuditEvent::CampusAdded, $campus, note: $campus->name);

        return ApiResponse::created(self::all(), "{$campus->name} added.");
    }

    /**
     * Change a campus
     *
     * Rename it, move its pin, or make it the default (`is_default: true`).
     */
    public function update(Request $request, Campus $campus): JsonResponse
    {
        $data = $request->validate($this->rules($campus));

        $fields = ['name', 'latitude', 'longitude', 'is_default'];
        $before = $campus->only($fields);
        DB::transaction(function () use ($campus, $data) {
            if ($data['is_default'] ?? false) {
                Campus::whereKeyNot($campus->id)->where('is_default', true)->update(['is_default' => false]);
                $campus->is_default = true;
            }
            $campus->fill($data)->save();
        });

        $changes = AuditDiff::between($before, $campus->only($fields));
        if ($changes !== []) {
            $this->audit->record(AuditEvent::CampusUpdated, $campus, $changes, note: $campus->name);
        }

        return ApiResponse::ok(self::all(), 'Campus saved.');
    }

    /**
     * Remove a campus
     */
    public function destroy(Campus $campus): JsonResponse
    {
        DB::transaction(function () use ($campus) {
            $campus->delete();
            if ($campus->is_default) {
                Campus::oldest('id')->first()?->forceFill(['is_default' => true])->save();
            }
        });

        $this->audit->record(AuditEvent::CampusRemoved, note: $campus->name);

        return ApiResponse::ok(self::all(), "{$campus->name} removed.");
    }

    /**
     * Every campus, the default first, as the public list shows them.
     *
     * @return list<array{id: int, name: string, latitude: float, longitude: float, is_default: bool}>
     */
    public static function all(): array
    {
        return Campus::orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (Campus $c) => $c->only(['id', 'name', 'latitude', 'longitude', 'is_default']))
            ->all();
    }

    private function rules(?Campus $campus = null): array
    {
        $required = $campus ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120', Rule::unique('campuses')->ignore($campus?->id)],
            'latitude' => [$required, 'numeric', 'between:4,22'],     // Philippines
            'longitude' => [$required, 'numeric', 'between:116,127'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }
}
