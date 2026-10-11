<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Api\V1\Admin\CampusController as AdminCampusController;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Dorm Finder (F1): the campuses distance is measured from.
 *
 * @group Dorm Finder
 */
class CampusController extends Controller
{
    /**
     * List campuses
     *
     * The default campus first.
     *
     * @unauthenticated
     */
    public function index(): JsonResponse
    {
        return ApiResponse::ok(AdminCampusController::all());
    }
}
