<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private const DEFAULT_PER_PAGE = 20;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request)
    {
        $query = Notification::where('user_id', auth()->id())->with('subject')->latest();

        if ($request->query('status') === 'unread') {
            $query->whereNull('read_at');
        } elseif ($request->query('status') === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($request->filled('category')) {
            $query->whereIn('type', NotificationResource::typesForCategory($request->query('category')));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->query('date_to'));
        }

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query('per_page', self::DEFAULT_PER_PAGE)));

        // Same flat current_page/data/last_page/... envelope as every other
        // paginated endpoint in this app (see ActivityLogController) —
        // through() transforms each item without switching to
        // Resource::collection()'s nested data/links/meta shape. The old
        // (unpaginated) response was just {data: [...]}; this stays a
        // superset any caller reading response.data.data still works with.
        $paginator = $query->paginate($perPage);
        $paginator->through(fn (Notification $notification) => (new NotificationResource($notification))->resolve());

        // Authoritative unread count, independent of whatever page/filter is
        // being viewed — the bell badge needs the true total, not "how many
        // unread happen to be on the current page" (which undercounts the
        // moment there are more than one page of unread notifications).
        $unreadCount = Notification::where('user_id', auth()->id())->whereNull('read_at')->count();

        return response()->json(['unread_count' => $unreadCount] + $paginator->toArray());
    }

    public function markRead(Notification $notification)
    {
        abort_unless($notification->user_id === auth()->id(), 403);

        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return new NotificationResource($notification);
    }

    public function markAllRead()
    {
        Notification::where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => __('messages.notifications.all_marked_read'),
        ]);
    }

    public function destroy(Notification $notification)
    {
        abort_unless($notification->user_id === auth()->id(), 403);

        $notification->delete();

        return response()->json([
            'message' => __('messages.notifications.deleted'),
        ]);
    }
}
