<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * @group System
 */
class HealthController extends Controller
{
    /**
     * Health check
     *
     * Confirms the API and database are reachable. Used by the app on start.
     *
     * @unauthenticated
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable) {
            $database = 'unavailable';
        }

        return ApiResponse::ok([
            'app' => config('app.name'),
            'api_version' => 'v1',
            'database' => $database,
            'server_time' => ManilaDate::now()->toIso8601String(),
            'timezone' => ManilaDate::TIMEZONE,
        ], status: $database === 'ok' ? 200 : 503);
    }
}
