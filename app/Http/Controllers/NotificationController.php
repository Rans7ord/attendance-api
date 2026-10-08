<?php
namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        return response()->json([
            'unread_count' => UserNotification::where('user_id', $userId)->whereNull('read_at')->count(),
            'notifications' => UserNotification::where('user_id', $userId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(),
        ]);
    }

    /** Cheap call for the app to poll and drive a badge or pop-up. */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'unread_count' => UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->count(),
        ]);
    }

    public function markRead(Request $request, UserNotification $notification)
    {
        if ($notification->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (!$notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json($notification);
    }

    public function markAllRead(Request $request)
    {
        UserNotification::where('user_id', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['message' => 'All notifications marked as read.']);
    }
}