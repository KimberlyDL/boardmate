<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Resources\Billing\BillResource;
use App\Http\Responses\ApiResponse;
use App\Models\Bill;
use App\Models\CollectionNote;
use App\Models\Property;
use App\Models\User;
use App\Services\Billing\CollectionService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The overdue list and follow-up (C7): owner and caretakers (Collectors
 * included) see it, add remarks, remind and mark late; the owner or a
 * Manager puts a bill under review.
 *
 * @group Billing
 */
class CollectionController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly CollectionService $collections) {}

    /**
     * Overdue list
     *
     * Everyone with a bill past its due date, most days overdue first: what
     * is overdue and owed in all, the oldest due date, whether a bill is
     * marked late, bills under review, the last reminder and the latest
     * remark. A room leader is listed for the room's shared bills.
     */
    public function index(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ViewArrears);
        $rows = $this->collections->overdue($property);

        return ApiResponse::ok($rows, meta: [
            'payers' => count($rows),
            'overdue_total_centavos' => array_sum(array_column($rows, 'overdue_centavos')),
        ]);
    }

    /**
     * Remarks about a payer
     *
     * Newest first.
     */
    public function notes(Request $request, Property $property, User $payer): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ViewArrears);
        $notes = CollectionNote::where('property_id', $property->id)->where('payer_id', $payer->id)
            ->with('author:id,name')->latest('id')->limit(100)->get();

        return ApiResponse::ok($notes->map(fn (CollectionNote $n) => $this->collections->presentNote($n))->values());
    }

    /**
     * Add a remark
     *
     * After a visit or a call (SC-32). `promised_on` is the date they said
     * they will pay; it is shown on the list and triggers nothing.
     */
    public function addNote(Request $request, Property $property, User $payer): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::ViewArrears);
        $data = $request->validate([
            'note' => ['required', 'string', 'max:500'],
            'promised_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.ManilaDate::today()->toDateString()],
        ]);
        abort_unless($property->bills()->where('payer_id', $payer->id)->exists(), 404);

        $note = $this->collections->addNote($property, $payer, $data['note'], $data['promised_on'] ?? null, $request->user());

        return ApiResponse::created($this->collections->presentNote($note), 'Remark saved.');
    }

    /**
     * Remind now
     *
     * Sends the payer one reminder for their bills due today or earlier (not
     * under review). Once a day per payer.
     */
    public function remind(Request $request, Property $property, User $payer): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::SendReminders);
        $count = $this->collections->remindNow($property, $payer, $request->user());

        return ApiResponse::message("Reminder sent to {$payer->name} ({$count} bill".($count === 1 ? '' : 's').').');
    }

    /**
     * Mark a bill late
     *
     * Before the automatic day, for an overdue bill. Gives it a numbered first
     * notice and tells the payer. No fee is added.
     */
    public function markLate(Request $request, Bill $bill): JsonResponse
    {
        $this->authorizeProperty($request, $bill->property, A::MarkLate);
        $this->collections->markLate($bill, $request->user());

        return ApiResponse::ok($this->present($bill), 'Marked late.');
    }

    /**
     * Put a bill under review
     *
     * While the owner checks a dispute or a payment the boarder says they
     * made: no reminders and no late marking. Owner or Manager; `reason`
     * required.
     */
    public function startReview(Request $request, Bill $bill): JsonResponse
    {
        $this->authorizeProperty($request, $bill->property, A::EditIssuedBills);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return ApiResponse::ok($this->present($this->collections->startReview($bill, $data['reason'], $request->user())), 'Bill is under review.');
    }

    /**
     * End a bill's review
     *
     * Reminders and late marking resume from the next daily run.
     */
    public function endReview(Request $request, Bill $bill): JsonResponse
    {
        $this->authorizeProperty($request, $bill->property, A::EditIssuedBills);

        return ApiResponse::ok($this->present($this->collections->endReview($bill)), 'Review ended.');
    }

    private function present(Bill $bill): BillResource
    {
        return new BillResource(Bill::whereKey($bill->id)->withBalance()->with(['lines', 'payer:id,name', 'room', 'reviewer:id,name'])->firstOrFail());
    }
}
