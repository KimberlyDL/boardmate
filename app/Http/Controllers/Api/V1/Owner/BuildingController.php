<?php

namespace App\Http\Controllers\Api\V1\Owner;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Building;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Optional labels grouping properties (D1). Deleting one only removes the label.
 *
 * @group Owner
 */
class BuildingController extends Controller
{
    /**
     * List buildings
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::ok(Building::where('owner_id', $request->user()->id)->withCount('properties')->orderBy('name')->get()
            ->map(fn (Building $b) => ['id' => $b->id, 'name' => $b->name, 'number' => $b->number, 'properties_count' => $b->properties_count]));
    }

    /**
     * Add a building
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120',
            Rule::unique('buildings')->where('owner_id', $request->user()->id)]]);

        $building = new Building($data);
        $building->owner_id = $request->user()->id;
        $building->number = (int) Building::where('owner_id', $request->user()->id)->max('number') + 1;
        $building->save();

        return ApiResponse::created(['id' => $building->id, 'name' => $building->name, 'number' => $building->number, 'properties_count' => 0]);
    }

    /**
     * Rename a building
     */
    public function update(Request $request, Building $building): JsonResponse
    {
        abort_unless($building->owner_id === $request->user()->id, 404);
        $data = $request->validate(['name' => ['required', 'string', 'max:120',
            Rule::unique('buildings')->where('owner_id', $request->user()->id)->ignore($building->id)]]);
        $building->update($data);

        return ApiResponse::ok(['id' => $building->id, 'name' => $building->name, 'number' => $building->number]);
    }

    /**
     * Delete a building
     *
     * Its properties stay; they just lose the label.
     */
    public function destroy(Request $request, Building $building): JsonResponse
    {
        abort_unless($building->owner_id === $request->user()->id, 404);
        $building->delete();

        return ApiResponse::message('Building removed.');
    }
}
