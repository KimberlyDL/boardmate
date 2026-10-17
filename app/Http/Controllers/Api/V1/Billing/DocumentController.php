<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Enums\PropertyAbility as A;
use App\Http\Controllers\Api\V1\Properties\Concerns\AuthorizesProperty;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Bill;
use App\Models\Payment;
use App\Models\Property;
use App\Models\Settlement;
use App\Models\Tenancy;
use App\Models\User;
use App\Services\Documents\Contracts\DocumentService;
use App\Support\ManilaDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * PDF receipts, statements of account and late notices (A9). The signed-in
 * endpoints check who may see a document and return a short-lived link; the
 * link itself needs no token, so the app can open it in a browser.
 *
 * @group Documents
 */
class DocumentController extends Controller
{
    use AuthorizesProperty;

    public function __construct(private readonly DocumentService $documents) {}

    /**
     * Link to a receipt
     *
     * For the payer, or the owner and caretakers. Only a payment with a
     * receipt number (confirmed, or since reversed) has one.
     */
    public function receiptLink(Request $request, Payment $payment): JsonResponse
    {
        abort_unless($payment->receipt_number && $payment->reverses_id === null, 404);
        $this->allowPayerOrStaff($request, $payment->payer_id, $payment->property, A::RecordPayments);

        return $this->linkTo('receipt', ['payment' => $payment->id]);
    }

    /**
     * Link to a late notice
     *
     * For the payer, or the owner and caretakers. Only a bill marked late has one.
     */
    public function noticeLink(Request $request, Bill $bill): JsonResponse
    {
        abort_unless($bill->isLate(), 404);
        $this->allowPayerOrStaff($request, $bill->payer_id, $bill->property, A::ViewArrears);

        return $this->linkTo('notice', ['bill' => $bill->id]);
    }

    /**
     * Link to a payer's statement of account
     *
     * Owner and caretakers. `from` and `to` (Y-m-d) are optional: from the
     * first bill, to today.
     */
    public function statementLink(Request $request, Property $property, User $payer): JsonResponse
    {
        $this->authorizeProperty($request, $property, A::RecordPayments);
        abort_unless(Tenancy::where('property_id', $property->id)->where('tenant_id', $payer->id)->exists(), 404);

        return $this->linkTo('statement', ['property' => $property->id, 'payer' => $payer->id] + $this->period($request));
    }

    /**
     * Link to my statement of account
     *
     * For a place where I stay or stayed.
     */
    public function myStatementLink(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'property_id' => ['required', 'integer', Rule::exists('tenancies', 'property_id')->where('tenant_id', $user->id)],
        ], ['property_id.exists' => 'You only have statements for places where you stay or stayed.']);

        return $this->linkTo('statement', ['property' => $data['property_id'], 'payer' => $user->id] + $this->period($request));
    }

    /**
     * Download a receipt
     *
     * Only through a signed, expiring link from the endpoints above.
     *
     * @unauthenticated
     */
    public function receipt(Payment $payment): Response
    {
        return $this->documents->receipt($payment);
    }

    /**
     * Download a late notice
     *
     * @unauthenticated
     */
    public function notice(Bill $bill): Response
    {
        return $this->documents->lateNotice($bill);
    }

    /**
     * Download a statement of account
     *
     * @unauthenticated
     */
    public function statement(Request $request, int $property, User $payer): Response
    {
        return $this->documents->statement(Property::withTrashed()->findOrFail($property), $payer, $request->query('from'), $request->query('to'));
    }

    /**
     * Download a settlement summary
     *
     * @unauthenticated
     */
    public function settlement(Settlement $settlement): Response
    {
        return $this->documents->settlementSummary($settlement);
    }

    private function allowPayerOrStaff(Request $request, int $payerId, Property $property, A $ability): void
    {
        $user = $request->user();
        if ($user->id === $payerId) {
            return;
        }
        $this->authorizeProperty($request, $property, $ability);
    }

    /** @return array{from?: string, to?: string} */
    private function period(Request $request): array
    {
        $today = ManilaDate::today()->toDateString();
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$today, 'after_or_equal:from'],
        ]);

        return array_filter(['from' => $data['from'] ?? null, 'to' => $data['to'] ?? null]);
    }

    private function linkTo(string $type, array $params): JsonResponse
    {
        return ApiResponse::ok([
            'url' => $this->documents->link($type, $params),
            'expires_in_minutes' => (int) config('dormlink.private_file_url_minutes'),
        ]);
    }
}
