<?php

namespace App\Providers;

use App\Models\DefenseRequest;
use App\Models\Notification;
use App\Observers\DefenseRequestObserver;
use App\Services\DefenseReminderService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DefenseRequest::observe(DefenseRequestObserver::class);
        Inertia::share([
            'notifications' => function () {
                if (Auth::check()) {
                    app(DefenseReminderService::class)->send(Auth::user());

                    return Notification::where('user_id', Auth::id())
                        ->latest()->take(20)->get();
                }

                return [];
            },
            'unreadCount' => function () {
                if (Auth::check()) {
                    return Notification::where('user_id', Auth::id())
                        ->where('read', false)->count();
                }

                return 0;
            },
            'auth' => function () {
                $user = Auth::user();

                return [
                    'user' => $user,
                    'is_adviser' => $user ? $user->isActiveAdviser() : false,
                ];
            },
        ]);
    }
}
