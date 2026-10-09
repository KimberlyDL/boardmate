<?php

namespace App\Http\Resources\Tenancies;

use App\Enums\FilePurpose;
use App\Enums\TenancyPaymentKind;
use App\Models\RoomLeader;
use App\Models\Tenancy;
use App\Services\Files\Contracts\FileService;
use App\Services\Pricing\PriceBook;
use App\Support\ManilaDate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tenancy seen by staff (`staff`: adds the move-in payments and how it was
 * activated) or by the tenant (`tenant`). The caller loads `tenant`, `room`,
 * `unit`, `property`, `discounts` and, for staff, `payments`.
 *
 * @mixin Tenancy
 */
class TenancyResource extends JsonResource
{
    public function __construct($resource, private readonly string $view = 'staff')
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $files = app(FileService::class);
        $today = ManilaDate::today();
        $rent = app(PriceBook::class)->amountOn($this->unit);
        $discount = $this->discounts->first(fn ($d) => $d->effective_from->lte($today) && ($d->effective_to === null || $d->effective_to->gte($today)));
        $off = $rent !== null && $discount ? $discount->amountOff($rent) : 0;

        $data = [
            'id' => $this->id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'property' => ['id' => $this->property_id, 'name' => $this->property->name],
            'room' => ['id' => $this->room_id, 'code' => $this->room->code(), 'rental_mode' => $this->room->rental_mode->value],
            'unit' => ['id' => $this->unit_id, 'label' => $this->unit->label, 'kind' => $this->unit->kind->value],
            'tenant' => [
                'id' => $this->tenant->id,
                'name' => $this->tenant->name,
                'phone' => $this->tenant->phone,
                'photo_url' => $files->url($this->tenant->photo_path, FilePurpose::ProfilePhoto),
            ],
            'moved_in_on' => $this->moved_in_on->toDateString(),
            // The first day of stay is the day after move-in; rent is due on the anchor day.
            'first_day_on' => $this->moved_in_on->addDay()->toDateString(),
            'anchor_day' => $this->anchor_day,
            'next_rent_due_on' => $this->nextRentDueOn($today)->toDateString(),
            'rent_centavos' => $rent,
            'discount' => $discount ? [
                'label' => $discount::LABEL,
                'kind' => $discount->kind->value,
                'value' => $discount->value,
                'amount_centavos' => $off,
                'effective_from' => $discount->effective_from->toDateString(),
            ] : null,
            'rent_after_discount_centavos' => $rent === null ? null : $rent - $off,
            // A change already agreed that has not started yet.
            'scheduled_discount' => ($next = $this->discounts->where('effective_from', '>', $today)->sortBy('effective_from')->first()) ? [
                'kind' => $next->kind->value,
                'value' => $next->value,
                'effective_from' => $next->effective_from->toDateString(),
            ] : null,
            'emergency_contact' => [
                'name' => $this->emergency_contact_name,
                'relationship' => $this->emergency_contact_relationship,
                'phone' => $this->emergency_contact_phone,
            ],
            'house_rules_accepted_at' => $this->house_rules_accepted_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];

        if ($this->view === 'tenant') {
            // Leaders manage who stays; the app shows them that option.
            $data['is_leader'] = RoomLeader::current()->where('room_id', $this->room_id)->where('user_id', $this->tenant_id)->exists();
        }

        if ($this->view === 'staff') {
            $data['tenant']['email'] = $this->tenant->email;
            $data['payments'] = $this->payments->map(fn ($p) => [
                'id' => $p->id,
                'kind' => $p->kind->value,
                'kind_label' => $p->kind->label(),
                'amount_centavos' => $p->amount_centavos,
                'method' => $p->method->value,
                'method_label' => $p->method->label(),
                'received_on' => $p->received_on->toDateString(),
                'reference' => $p->reference,
            ])->values();
            $data['deposit_paid_centavos'] = (int) $this->payments->where('kind', TenancyPaymentKind::Deposit)->sum('amount_centavos');
            $data['first_rent_paid_centavos'] = (int) $this->payments->where('kind', TenancyPaymentKind::FirstRent)->sum('amount_centavos');
            $data['activation_override'] = $this->activation_override_reason ? ['reason' => $this->activation_override_reason] : null;
        }

        return $data;
    }
}
