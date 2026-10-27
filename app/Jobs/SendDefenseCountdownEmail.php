<?php

namespace App\Jobs;

use App\Mail\DefenseCountdownReminder;
use App\Models\DefenseRequest;
use App\Models\Notification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendDefenseCountdownEmail implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $notificationId, public string $date, public string $scheduledDate, public ?string $scheduledTime) {}

    public function uniqueId(): string
    {
        return 'defense-countdown-'.$this->notificationId;
    }

    public function backoff(): array
    {
        return [60, 120];
    }

    public function handle(): void
    {
        if (! config('defense-notifications.email_enabled') || now('Asia/Manila')->toDateString() !== $this->date) {
            return;
        }
        $notice = Notification::find($this->notificationId);
        if (! $notice) {
            return;
        }
        $dr = DefenseRequest::find($notice->defense_request_id);
        $student = User::find($notice->user_id);
        if (! $dr || ! $student?->email || $student->role !== 'Student' || $dr->scheduled_date?->format('Y-m-d') !== $this->scheduledDate || (string) $dr->scheduled_time !== (string) $this->scheduledTime) {
            return;
        }
        if (! in_array($dr->workflow_state, ['scheduled', 'coordinator-approved', 'panels-assigned'], true) || in_array($dr->status, ['Cancelled', 'Completed', 'Rejected'], true)) {
            return;
        }
        if (CarbonImmutable::parse($this->scheduledDate.' '.($this->scheduledTime ?: '23:59:59'), 'Asia/Manila') <= CarbonImmutable::now('Asia/Manila')) {
            return;
        }
        Mail::to($student->email)->send(new DefenseCountdownReminder($student->first_name, $notice->message));
    }
}
