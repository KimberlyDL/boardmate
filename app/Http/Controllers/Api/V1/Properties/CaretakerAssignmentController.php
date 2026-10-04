<?php

namespace App\Http\Controllers\Api\V1\Properties;

use App\Enums\AuditEvent;
use App\Enums\CaretakerAccessLevel;
use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Property;
use App\Models\PropertyCaretaker;
use App\Models\User;
use App\Services\Audit\Contracts\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Which of the owner's caretakers run this property, at which level.
 *
 * @group Properties
 */
class CaretakerAssignmentController extends Controller
{
    use AuthorizesProperty;

    /**
     * Set the property's caretakers
     *
     * Owner only. Send the full list; caretakers left out are unassigned.
     * Only the owner's active caretakers can be assigned.
     */
    public function update(Request $request, Property $property, AuditService $audit): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ManageCaretakers);

        $activeIds = $property->owner->caretakerLinks()->active()->pluck('caretaker_id')->all();
        $data = $request->validate([
            'assignments' => ['present', 'array'],
            'assignments.*.caretaker_id' => ['required', 'integer', 'distinct', Rule::in($activeIds)],
            'assignments.*.access_level' => ['required', Rule::enum(CaretakerAccessLevel::class)],
        ], ['assignments.*.caretaker_id.in' => 'That person is not one of your caretakers.']);

        $wanted = collect($data['assignments'])->keyBy('caretaker_id');
        $current = $property->caretakerAssignments()->get()->keyBy('caretaker_id');
        $names = User::whereIn('id', $wanted->keys()->merge($current->keys()))->pluck('name', 'id');

        DB::transaction(function () use ($property, $wanted, $current, $names, $audit) {
            foreach ($current as $id => $assignment) {
                if (! $wanted->has($id)) {
                    $assignment->delete();
                    $audit->record(AuditEvent::CaretakerUnassigned, $property, owner: $property->owner,
                        note: "{$names[$id]} · {$property->name}");
                }
            }

            foreach ($wanted as $id => $row) {
                $level = CaretakerAccessLevel::from($row['access_level']);
                $existing = $current->get($id);
                if ($existing && $existing->access_level === $level) {
                    continue;
                }

                PropertyCaretaker::updateOrCreate(
                    ['property_id' => $property->id, 'caretaker_id' => $id],
                    ['access_level' => $level],
                );
                $audit->record(AuditEvent::CaretakerAssigned, $property,
                    $existing ? ['access_level' => [$existing->access_level, $level]] : [],
                    owner: $property->owner, note: "{$names[$id]} as {$level->label()} · {$property->name}");
            }
        });

        return ApiResponse::ok(
            $property->caretakerAssignments()->with('caretaker:id,name,email')->get()->map(fn ($a) => [
                'caretaker_id' => $a->caretaker_id,
                'name' => $a->caretaker->name,
                'email' => $a->caretaker->email,
                'access_level' => $a->access_level->value,
                'access_level_label' => $a->access_level->label(),
            ]),
            'Caretakers updated.',
        );
    }
}
