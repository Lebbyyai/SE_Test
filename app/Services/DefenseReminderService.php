<?php

namespace App\Services;

use App\Jobs\SendDefenseCountdownEmail;
use App\Models\DefenseRequest;
use App\Models\User;
use Carbon\CarbonImmutable;

class DefenseReminderService
{
    public function send(?User $student = null): int
    {
        if ($student && $student->role !== 'Student') {
            return 0;
        }
        $timezone = config('defense-notifications.timezone');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $end = $today->addDays(config('defense-notifications.reminder_window_days'));
        $query = DefenseRequest::whereNotNull('scheduled_date')
            ->whereDate('scheduled_date', '>=', $today->toDateString())
            ->whereDate('scheduled_date', '<=', $end->toDateString())
            ->whereIn('workflow_state', ['scheduled', 'coordinator-approved', 'panels-assigned'])
            ->where(function ($query) {
                $query->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'Completed', 'Rejected']);
            });
        if ($student) {
            $query->where('submitted_by', $student->id);
        }

        $sent = 0;
        $query->with('user')->chunkById(100, function ($requests) use ($today, $timezone, &$sent) {
            foreach ($requests as $request) {
                if ($request->user?->role !== 'Student') {
                    continue;
                }
                $date = CarbonImmutable::parse($request->getRawOriginal('scheduled_date'), $timezone)->startOfDay();
                $time = $request->scheduled_time ?: '23:59:59';
                if (CarbonImmutable::parse($date->toDateString().' '.$time, $timezone)->lessThanOrEqualTo(CarbonImmutable::now($timezone))) {
                    continue;
                }
                $days = (int) $today->diffInDays($date);
                $countdown = $days === 0 ? 'Your defense is today' : ($days === 1 ? '1 day remaining before your defense' : "{$days} days remaining before your defense");
                $event = 'reminder|'.$date->toDateString().'|'.$time.'|'.$today->toDateString();
                $notification = app(DefenseActivityNotifications::class)->sendOnce($request, $request->user, $event,
                    'Defense Reminder', "{$countdown}: “{$request->thesis_title}”, {$date->format('M j, Y')} at {$time}. Venue: {$request->defense_venue}.",
                    '/defense-requirements', 'defense-reminder');
                if ($notification->wasRecentlyCreated) {
                    $sent++;
                    if (config('defense-notifications.email_enabled') && $request->user->email) {
                        SendDefenseCountdownEmail::dispatch($notification->id, $today->toDateString(), $date->toDateString(), $request->scheduled_time)->afterCommit();
                    }
                }
            }
        });

        return $sent;
    }
}
