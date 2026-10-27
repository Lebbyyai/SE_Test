<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class AdviserAssignmentNotifications
{
    public function pending(User $adviser, User $student, string $requestedAt): void
    {
        foreach ([[$adviser, 'Student assignment awaiting your confirmation', $student->display_name.' has been assigned to you. Open My Students and accept or decline the pending assignment.', '/adviser/students-list'], [$student, 'Adviser assignment awaiting confirmation', 'Your coordinator assigned '.$adviser->display_name.' ('.$adviser->email.') as your adviser. Awaiting their confirmation.', '/dashboard']] as [$recipient, $title, $message, $link]) {
            Notification::firstOrCreate(['event_key' => hash('sha256', 'adviser-assignment|'.$adviser->id.'|'.$student->id.'|'.$requestedAt.'|'.$recipient->id)], [
                'user_id' => $recipient->id, 'type' => 'adviser-assignment', 'title' => $title, 'message' => $message, 'link' => $link, 'read' => false,
            ]);
        }
    }

    // Also recover notices for pending assignments created before this feature.
    public function syncPending(User $user): void
    {
        if (! Schema::hasTable('adviser_student')) {
            return;
        }
        foreach ($user->advisers()->wherePivot('status', 'pending')->get() as $adviser) {
            $this->pending($adviser, $user, (string) $adviser->pivot->updated_at);
        }
        foreach ($user->advisedStudents()->wherePivot('status', 'pending')->get() as $student) {
            $this->pending($user, $student, (string) $student->pivot->updated_at);
        }
    }

    public function decided(User $adviser, User $student, string $status, ?int $coordinatorId): void
    {
        $accepted = $status === 'accepted';
        foreach (array_unique(array_filter([$student->id, $coordinatorId])) as $recipientId) {
            Notification::create([
                'user_id' => $recipientId, 'type' => 'adviser-assignment', 'read' => false,
                'title' => $accepted ? 'Adviser assignment accepted' : 'Adviser assignment declined',
                'message' => $adviser->display_name.' '.($accepted ? 'accepted' : 'declined').' the adviser assignment for '.$student->display_name.'.'.($accepted ? ' The adviser connection is now active.' : ' Please contact the coordinator for another assignment.'),
                'link' => (int) $recipientId === (int) $student->id ? '/dashboard' : '/coordinator/adviser-list',
            ]);
        }
    }
}
