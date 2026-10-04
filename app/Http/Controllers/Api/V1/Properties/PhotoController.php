<?php

namespace App\Http\Controllers\Api\V1\Properties;

use App\Enums\AuditEvent;
use App\Enums\FilePurpose;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Properties\PropertyResource;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Services\Audit\Contracts\AuditService;
use App\Services\Files\Contracts\FileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Listing photos: public files (Dorm Finder), up to 15, one cover.
 *
 * @group Properties
 */
class PhotoController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly FileService $files, private readonly AuditService $audit) {}

    /**
     * Upload photos
     *
     * One or more `photos[]` (jpg, png or webp, up to 5 MB each). The first
     * photo of a property becomes its cover.
     */
    public function store(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::EditDetails);

        $request->validate([
            'photos' => ['required', 'array', 'min:1', 'max:'.Property::MAX_PHOTOS],
            'photos.*' => $this->files->rules(FilePurpose::ListingPhoto),
        ]);

        $existing = $property->photos()->count();
        $incoming = count($request->file('photos'));
        if ($existing + $incoming > Property::MAX_PHOTOS) {
            throw ValidationException::withMessages([
                'photos' => 'A listing can have up to '.Property::MAX_PHOTOS.' photos. Remove some first.',
            ]);
        }

        $order = (int) $property->photos()->max('sort_order');
        foreach ($request->file('photos') as $i => $file) {
            $property->photos()->create([
                'path' => $this->files->store($file, FilePurpose::ListingPhoto),
                'sort_order' => $order + $i + 1,
                'is_cover' => $existing === 0 && $i === 0,
            ]);
        }

        $this->audit->record(AuditEvent::PhotosChanged, $property, owner: $property->owner,
            note: "{$property->name} · {$incoming} photo(s) added");

        return ApiResponse::created($this->detail($property), 'Photos added.');
    }

    /**
     * Reorder photos
     *
     * `ids` lists every photo of the property in the new order.
     */
    public function order(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::EditDetails);

        $ids = $property->photos()->pluck('id')->all();
        $data = $request->validate([
            'ids' => ['required', 'array', 'size:'.count($ids)],
            'ids.*' => ['integer', 'distinct', Rule::in($ids)],
        ]);

        DB::transaction(function () use ($data) {
            foreach ($data['ids'] as $position => $id) {
                PropertyPhoto::whereKey($id)->update(['sort_order' => $position + 1]);
            }
        });

        return ApiResponse::ok($this->detail($property));
    }

    /**
     * Set the cover photo
     */
    public function cover(Request $request, Property $property, PropertyPhoto $photo): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::EditDetails);
        abort_unless($photo->property_id === $property->id, 404);

        DB::transaction(function () use ($property, $photo) {
            $property->photos()->update(['is_cover' => false]);
            $photo->update(['is_cover' => true]);
        });

        return ApiResponse::ok($this->detail($property));
    }

    /**
     * Delete a photo
     */
    public function destroy(Request $request, Property $property, PropertyPhoto $photo): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::EditDetails);
        abort_unless($photo->property_id === $property->id, 404);

        $wasCover = $photo->is_cover;
        $this->files->delete($photo->path, FilePurpose::ListingPhoto);
        $photo->delete();

        if ($wasCover) {
            $property->photos()->first()?->update(['is_cover' => true]);
        }
        if ($property->is_published && ! $property->photos()->exists()) {
            $property->forceFill(['is_published' => false])->save();
            $this->audit->record(AuditEvent::PropertyUnpublished, $property, owner: $property->owner,
                note: "{$property->name} · last photo removed");
        }

        $this->audit->record(AuditEvent::PhotosChanged, $property, owner: $property->owner, note: "{$property->name} · photo removed");

        return ApiResponse::ok($this->detail($property));
    }

    private function detail(Property $property): PropertyResource
    {
        return new PropertyResource($property->load(['units', 'utilityAccounts', 'photos', 'building', 'owner.ownerProfile']), detail: true);
    }
}
