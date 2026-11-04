<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Dashboard\PlatformStats;
use Illuminate\Http\JsonResponse;

/**
 * Platform admin: how the platform is doing, in counts.
 *
 * @group Admin
 */
class PlatformStatsController extends Controller
{
    public function __construct(private readonly PlatformStats $stats) {}

    /**
     * Platform numbers
     *
     * Accounts (all but admins: total, new this month, suspended, by role),
     * owners by review status, listings on the Finder and their places,
     * current stays and applications waiting, campuses, and for the 12
     * months ending this month: sign-ups, applications, approvals and
     * move-ins. Counts only; no money.
     */
    public function index(): JsonResponse
    {
        return ApiResponse::ok($this->stats->get());
    }
}
