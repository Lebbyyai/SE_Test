<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\AdviserAssignmentNotifications;
use App\Services\DefenseReminderService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NotificationController extends Controller
{
    public function index(Request $request)
    {
        app(DefenseReminderService::class)->send($request->user());
        app(AdviserAssignmentNotifications::class)->syncPending($request->user());
        $notifications = Notification::where('user_id', $request->user()->id)
            ->latest()->get();

        return Inertia::render('notification/Index', [
            'notifications' => $notifications,
        ]);
    }

    public function markAsRead(Request $request, Notification $notification)
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);
        $notification->update(['read' => true]);
        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return back();
    }

    public function markAllAsRead(Request $request)
    {
        Notification::where('user_id', $request->user()->id)
            ->where('read', false)
            ->update(['read' => true]);
        if ($request->expectsJson()) {
            return response()->json(['success' => true]);
        }

        return redirect()->back()->with('success', 'All notifications marked as read!');
    }

    public function feed(Request $request)
    {
        app(DefenseReminderService::class)->send($request->user());
        app(AdviserAssignmentNotifications::class)->syncPending($request->user());
        $query = Notification::where('user_id', $request->user()->id);

        return response()->json([
            'notifications' => (clone $query)->latest('id')->limit(50)->get(),
            'unreadCount' => (clone $query)->where('read', false)->count(),
        ]);
    }
}
