<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The notification feed, backed by Laravel's notifications table.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $notifications = $user->notifications()->latest();

        if ($request->boolean('unread')) {
            $notifications->whereNull('read_at');
        }

        if ($kind = $request->string('kind')->value()) {
            $notifications->where('data->kind', $kind);
        }

        return response()->json([
            'data' => $notifications->limit(min($request->integer('per_page', 30), 100))
                ->get()
                ->map(fn ($n) => [
                    'id' => $n->id,
                    'kind' => $n->data['kind'] ?? null,
                    'title' => $n->data['title'] ?? null,
                    'detail' => $n->data['body'] ?? null,
                    'read' => $n->read_at !== null,
                    'read_at' => $n->read_at?->toIso8601String(),
                    'subject_type' => $n->subject_type,
                    'subject_id' => $n->subject_id,
                    'created_at' => $n->created_at?->toIso8601String(),
                ]),
            'meta' => ['unread_count' => $user->unreadNotifications()->count()],
        ]);
    }

    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->whereKey($id)->firstOrFail();

        $notification->markAsRead();

        return response()->json(['message' => 'Marked as read.']);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $count = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json(['message' => $count.' notifications marked as read.']);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        // firstOrFail rather than a bare delete, so removing a notification
        // that belongs to someone else reports 404 instead of silently
        // succeeding.
        $request->user()->notifications()->whereKey($id)->firstOrFail()->delete();

        return response()->json(['message' => 'Notification removed.']);
    }
}
