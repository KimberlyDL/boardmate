<?php

namespace App\Http\Resources\Booking;

use App\Enums\ApplicationStatus;
use App\Enums\FilePurpose;
use App\Enums\UnitKind;
use App\Models\BookingApplication;
use App\Services\Files\Contracts\FileService;
use App\Services\Pricing\PriceBook;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An application seen by the boarder (`boarder`) or by the owner/Manager
 * (`reviewer`, adds applicant contact and the ID file). The full street
 * address is shown to the boarder only once the reservation is approved.
 *
 * @mixin BookingApplication
 */
class ApplicationResource extends JsonResource
{
    public function __construct($resource, private readonly string $view = 'boarder')
    {
        parent::__construct($resource);
    }

    /**
     * Eager-load what every row shows, for a whole list at once.
     *
     * @param  iterable<BookingApplication>  $applications
     */
    public static function prepare(iterable $applications, string $view = 'boarder'): void
    {
        $list = new EloquentCollection(collect($applications)->all());
        $list->loadMissing(array_merge(
            ['property.owner.ownerProfile', 'property.photos', 'unit'],
            $view === 'reviewer' ? ['boarder', 'decider'] : [],
        ));
        app(PriceBook::class)->preload($list->pluck('unit')->filter());
    }

    public function toArray(Request $request): array
    {
        $files = app(FileService::class);
        $property = $this->property;
        $cover = $property->relationLoaded('photos')
            ? $property->photos->sortBy([['is_cover', 'desc'], ['sort_order', 'asc']])->first()
            : $property->photos()->orderByDesc('is_cover')->orderBy('sort_order')->first();
        $reserved = $this->status === ApplicationStatus::Approved;

        $data = [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'property' => [
                'id' => $property->id,
                'name' => $property->name,
                'area' => collect([$property->barangay, $property->city])->filter()->implode(', '),
                'address' => $reserved || $this->view === 'reviewer' ? $property->fullAddress() : null,
                'cover_photo_url' => $cover ? $files->url($cover->thumbOrLargePath(), FilePurpose::ListingPhoto) : null,
                'rental_mode' => $this->unit ? ($this->unit->kind === UnitKind::Whole ? 'whole' : 'bedspaces') : null,
                'owner_name' => $property->owner->ownerDisplayName(),
            ],
            'unit' => $this->unit ? [
                'id' => $this->unit->id,
                'label' => $this->unit->label,
                'rent_centavos' => app(PriceBook::class)->amountOn($this->unit),
            ] : null,
            'planned_move_in_on' => $this->planned_move_in_on->toDateString(),
            'message' => $this->message,
            'reserved_until' => $this->reserved_until?->toDateString(),
            'closed_reason' => $this->closed_reason,
            'decided_at' => $this->decided_at?->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'can_cancel' => $this->status->isOpen(),
            'leader_on_move_in' => $this->leader_on_move_in,
        ];

        if ($this->view === 'reviewer') {
            $boarder = $this->boarder;
            $data['applicant'] = [
                'id' => $boarder->id,
                'name' => $boarder->name,
                'email' => $boarder->email,
                'phone' => $this->contact_phone,
                'photo_url' => $files->url($boarder->photo_path, FilePurpose::ProfilePhoto),
            ];
            $data['id_document_url'] = $files->url($this->id_document_path, FilePurpose::IdDocument);
            $data['id_document_purged'] = $this->id_document_purged_at !== null;
            $data['decided_by'] = $this->decider?->name;
            // Emergency contacts are for staff only, so the boarder does not get this list.
            $data['planned_occupants'] = $this->planned_occupants ?? [];
            $data['can_cancel'] = $this->status === ApplicationStatus::Approved;
        }

        return $data;
    }
}
