<?php

namespace App\Http\Controllers\Api\V1\Account;

use App\Enums\NotificationEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @group Notifications
 */
class NotificationController extends Controller
{
    /**
     * List notifications
     *
     * Newest first, 20 per page.
     */
    public function index(Request $request): JsonResponse
    {
        $page = $request->user()->notifications()->paginate(20);

        return ApiResponse::ok(NotificationResource::collection($page->items()), meta: [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'total' => $page->total(),
            'unread_count' => $request->user()->unreadNotifications()->count(),
        ]);
    }

    /**
     * Unread count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::ok(['unread_count' => $request->user()->unreadNotifications()->count()]);
    }

    /**
     * Mark one as read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        return ApiResponse::ok(new NotificationResource($notification));
    }

    /**
     * Mark all as read
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::message('All notifications marked as read.');
    }

    /**
     * Notification preferences
     *
     * Lists the notifications a user may turn off, and which are off.
     */
    public function preferences(Request $request): JsonResponse
    {
        return ApiResponse::ok($this->preferenceList($request));
    }

    /**
     * Update notification preferences
     *
     * @bodyParam muted string[] Events to turn off. Only mutable events are accepted.
     */
    public function updatePreferences(Request $request): JsonResponse
    {
        $mutable = array_map(fn (NotificationEvent $e) => $e->value, NotificationEvent::mutable());

        $data = $request->validate([
            'muted' => ['present', 'array'],
            'muted.*' => ['string', Rule::in($mutable)],
        ]);

        $request->user()->update(['muted_notifications' => array_values(array_unique($data['muted']))]);

        return ApiResponse::ok($this->preferenceList($request), 'Preferences saved.');
    }

    private function preferenceList(Request $request): array
    {
        return array_map(fn (NotificationEvent $e) => [
            'event' => $e->value,
            'label' => $e->label(),
            'muted' => $request->user()->hasMuted($e),
        ], NotificationEvent::mutable());
    }
}
