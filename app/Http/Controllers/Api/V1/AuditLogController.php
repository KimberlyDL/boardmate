<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditEntryResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Models\Activity;

/**
 * @group Audit log
 */
class AuditLogController extends Controller
{
    /**
     * Owner activity log
     *
     * Everything that happened in the owner's business: their own actions,
     * their caretakers' actions, and BoardMate's decisions about their
     * account. Owner only (not caretakers). Newest first, 25 per page.
     */
    public function owner(Request $request): JsonResponse
    {
        $query = Activity::query()->where('owner_id', $request->user()->id);

        return $this->respond($request, $query, viewerIsAdmin: false);
    }

    /**
     * Admin activity log
     *
     * Platform-admin actions only (owner reviews, account suspensions).
     */
    public function admin(Request $request): JsonResponse
    {
        $query = Activity::query()->where('log_name', 'admin');

        return $this->respond($request, $query, viewerIsAdmin: true);
    }

    private function respond(Request $request, Builder $query, bool $viewerIsAdmin): JsonResponse
    {
        $request->validate([
            'event' => ['nullable', Rule::enum(AuditEvent::class)],
            'actor_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $page = $query
            ->when($request->filled('event'), fn ($q) => $q->where('event', $request->input('event')))
            ->when($request->filled('actor_id'), fn ($q) => $q->where('causer_id', $request->integer('actor_id')))
            ->when($request->filled('from'), fn ($q) => $q->where('created_at', '>=', $request->date('from', null, 'Asia/Manila')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('created_at', '<=', $request->date('to', null, 'Asia/Manila')->endOfDay()))
            ->latest('id')
            ->paginate(25);

        return ApiResponse::ok(
            collect($page->items())->map(fn (Activity $a) => new AuditEntryResource($a, $viewerIsAdmin)),
            meta: ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        );
    }
}
