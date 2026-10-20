<?php

namespace App\Http\Controllers\Api\V1\Properties;

use App\Enums\HouseRuleCategory;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Properties\HouseRuleVersionResource;
use App\Http\Responses\ApiResponse;
use App\Models\HouseRuleVersion;
use App\Models\Property;
use App\Services\Properties\HouseRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A property's house rules (D3): the rules in force, the draft being edited
 * and every past version. The owner or a Manager edits and publishes.
 *
 * @group House rules
 */
class HouseRuleController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly HouseRuleService $rules) {}

    /**
     * Get house rules
     *
     * `current`: the version in force (null if none yet). `draft`: the
     * unpublished edit, if any. `versions`: every published version, newest
     * first, without their rules. `automatic`: the lines the curfew and
     * visitor settings give now, added to every version on publish.
     */
    public function show(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);

        $versions = $property->houseRuleVersions()->published()->with('publisher:id,name')->withCount('rules')->orderByDesc('version')->get();
        $current = $versions->first()?->load('rules');
        $draft = $this->rules->draft($property)?->load('rules');

        return ApiResponse::ok([
            'current' => $current ? new HouseRuleVersionResource($current) : null,
            'draft' => $draft ? new HouseRuleVersionResource($draft) : null,
            'automatic' => array_map(fn ($l) => ['category' => $l['category']->value, 'category_label' => $l['category']->label(), 'body' => $l['body']], $this->rules->autoLines($property)),
            'versions' => $versions->map(fn (HouseRuleVersion $v) => [
                'version' => $v->version,
                'published_at' => $v->published_at?->toIso8601String(),
                'published_by' => $v->publisher?->name,
                'note' => $v->note,
                'rules_count' => $v->rules_count,
            ])->values(),
        ]);
    }

    /**
     * Get a past version
     */
    public function version(Request $request, Property $property, int $version): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::View);
        $found = $property->houseRuleVersions()->where('version', $version)->with(['rules', 'publisher:id,name'])->firstOrFail();

        return ApiResponse::ok(new HouseRuleVersionResource($found));
    }

    /**
     * Save the draft
     *
     * The whole list of rules, in order (each a `category` and a `body`),
     * plus an optional `note` on what changed. Replaces the draft's rules.
     */
    public function saveDraft(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageRules);
        $data = $request->validate([
            'rules' => ['present', 'array', 'max:60'],
            'rules.*.category' => ['required', Rule::enum(HouseRuleCategory::class)],
            'rules.*.body' => ['required', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $draft = $this->rules->saveDraft($property, $data['rules'], $data['note'] ?? null, $request->user());

        return ApiResponse::ok(new HouseRuleVersionResource($draft), 'Draft saved. Publish it when it is ready.');
    }

    /**
     * Discard the draft
     */
    public function discardDraft(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageRules);
        $this->rules->discardDraft($property);

        return ApiResponse::ok(null, 'Draft discarded.');
    }

    /**
     * Publish the draft
     *
     * It becomes the next version and the rules in force; earlier versions
     * stay readable.
     */
    public function publish(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageRules);
        $version = $this->rules->publish($property, $request->user());

        return ApiResponse::ok(new HouseRuleVersionResource($version), "House rules version {$version->version} published.");
    }
}
