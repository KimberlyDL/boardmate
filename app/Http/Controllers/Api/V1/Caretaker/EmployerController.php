<?php

namespace App\Http\Controllers\Api\V1\Caretaker;

use App\Http\Controllers\Controller;
use App\Http\Resources\CaretakerLinkResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @group Caretaker
 */
class EmployerController extends Controller
{
    /**
     * Owners I work for
     *
     * With the access level each owner gave you. Assigned properties are added
     * in a later phase.
     */
    public function index(Request $request): JsonResponse
    {
        $links = $request->user()->employerLinks()->active()->with('owner.ownerProfile')->latest()->get();

        return ApiResponse::ok($links->map(fn ($link) => new CaretakerLinkResource($link, 'owner')));
    }
}
