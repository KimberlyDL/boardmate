<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * @group System
 */
class TickController extends Controller
{
    /**
     * Run scheduled work
     *
     * Called every 5 minutes by an outside cron service on hosts without cron.
     * Needs the CRON_SECRET in the X-Cron-Secret header; returns 404 while
     * CRON_SECRET is not set. The work runs after the response is sent, so
     * the caller does not wait for it.
     *
     * @unauthenticated
     */
    public function __invoke(Request $request): JsonResponse
    {
        $secret = (string) config('dormlink.cron_secret');
        abort_if($secret === '', 404);
        abort_unless(hash_equals($secret, (string) $request->header('X-Cron-Secret')), 403);

        defer(fn () => Artisan::call('dormlink:tick'));

        return ApiResponse::ok(['queued' => true], status: 202);
    }
}
