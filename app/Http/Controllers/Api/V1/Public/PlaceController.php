<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Province;
use App\Services\Places\PlaceImporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

/**
 * The official province and city list (PSGC) for location pickers (Phase 4).
 *
 * @group Places
 */
class PlaceController extends Controller
{
    /**
     * List provinces and their cities
     *
     * Every province (Metro Manila included) by name, each with its cities and
     * municipalities by name. The list changes only when it is re-imported, so
     * the app may keep it for a day.
     *
     * @unauthenticated
     */
    public function index(): JsonResponse
    {
        $provinces = Cache::rememberForever(PlaceImporter::CACHE_KEY, fn () => Province::query()
            ->with('cities:id,province_id,psgc_code,name,is_city')
            ->orderBy('name')
            ->get()
            ->map(fn (Province $p) => [
                'code' => $p->psgc_code,
                'name' => $p->name,
                'cities' => $p->cities->map(fn ($c) => ['code' => $c->psgc_code, 'name' => $c->name, 'is_city' => $c->is_city])->values()->all(),
            ])
            ->all());

        return ApiResponse::ok($provinces)->header('Cache-Control', 'public, max-age=86400');
    }
}
