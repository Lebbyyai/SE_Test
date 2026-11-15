<?php

namespace App\Services;

use App\Jobs\DeliverComprehensiveNotice;
use App\Models\ComprehensiveWorkflow;
use App\Models\ExamApplication;
use App\Models\Notification;
use App\Models\PaymentSubmission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ComprehensiveWorkflowService
{
    public const ROLES = ['Student', 'Administrative Assistant', 'Coordinator', 'Adviser', 'Registrar', 'Dean', 'Super Admin', 'SuperAdmin'];

    public function student(ExamApplication $a): User
    {
        $u = User::where('role', 'Student')->where('school_id', (string) $a->student_id)->first();
        if (! $u && ctype_digit((string) $a->student_id)) {
            $u = User::where('role', 'Student')->find((int) $a->student_id);
        }
        abort_unless($u, 404, 'Student account not found.');

        return $u;
    }

    public function access(User $u, ComprehensiveWorkflow $w): void
    {
        abort_unless(in_array($u->role, self::ROLES, true), 403);
        if ($u->role === 'Student') {
            abort_unless($u->id === $w->student_user_id, 403);
        }
        if ($u->role === 'Coordinator') {
            abort_unless(in_array($w->application->program, $u->allowedProgramNames(), true), 403);
        }
        if ($u->role === 'Adviser') {
            abort_unless($w->sessions->contains(fn ($s) => collect($s->faculty)->contains(fn ($f) => (int) $f['user_id'] === $u->id)), 403);
        }
    }

    public function lock(): void
    {
        if (Schema::hasTable('defense_schedule_mutex')) {
            DB::table('defense_schedule_mutex')->where('id', 1)->lockForUpdate()->first();
        }DB::table('comprehensive_workflow_locks')->where('id', 1)->lockForUpdate()->first();
    }

    public function version(ComprehensiveWorkflow $w, int $version): void
    {
        if ($w->version !== $version) {
            throw ValidationException::withMessages(['version' => 'This application changed. Refresh before saving.']);
        }
    }

    public function record(ComprehensiveWorkflow $w, User $actor, string $action, ?string $note = null): void
    {
        $h = $w->history ?? [];
        $h[] = ['action' => $action, 'actor' => $actor->name, 'at' => now()->toIso8601String(), 'note' => $note];
        $w->history = $h;
        $w->version++;
        $w->save();
    }

    public function emailConfigured(): bool
    {
        return (bool) config('comprehensive.email_enabled') && ! in_array(config('mail.default'), ['log', 'array'], true);
    }

    public function notify(ComprehensiveWorkflow $w, User $user, string $message): void
    {
        Notification::create(['user_id' => $user->id, 'type' => 'comprehensive-workflow', 'title' => 'Comprehensive exam update', 'message' => $message, 'link' => '/comprehensive-workflow?application='.$w->application_id, 'read' => false]);
        $id = DB::table('comprehensive_deliveries')->insertGetId(['workflow_id' => $w->id, 'user_id' => $user->id, 'channel' => 'email', 'status' => $this->emailConfigured() ? 'queued' : 'disabled', 'detail' => $this->emailConfigured() ? null : 'Email delivery is not configured.', 'message' => $message, 'created_at' => now(), 'updated_at' => now()]);
        DeliverComprehensiveNotice::dispatch($id)->afterCommit();
        if ($user->id === $w->student_user_id && $w->sms_opt_in) {
            $id = DB::table('comprehensive_deliveries')->insertGetId(['workflow_id' => $w->id, 'user_id' => $user->id, 'channel' => 'sms', 'status' => config('comprehensive.sms_enabled') ? 'queued' : 'disabled', 'detail' => config('comprehensive.sms_enabled') ? null : 'SMS delivery is not configured.', 'message' => $message, 'created_at' => now(), 'updated_at' => now()]);
            DeliverComprehensiveNotice::dispatch($id)->afterCommit();
        }
    }

    public function notifyReviewers(ComprehensiveWorkflow $w, string $role, string $message): void
    {
        $users = User::where('role', $role)->get();
        foreach ($users as $u) {
            if ($role !== 'Coordinator' || in_array($w->application->program, $u->allowedProgramNames(), true)) {
                $this->notify($w, $u, $message);
            }
        }
    }

    public function missing(ComprehensiveWorkflow $w): array
    {
        $missing = [];
        if ($w->aa_status !== 'evaluated') {
            $missing[] = 'AA evaluation';
        }
        foreach ($w->application->subjects as $subject) {
            $s = $w->sessions->firstWhere('subject_id', $subject->id);
            if (! $s) {
                $missing[] = 'Room and schedule for '.$subject->subject_name;

                continue;
            }
            if (! collect($s->faculty)->contains(fn ($f) => $f['role'] === 'proctor' && $f['status'] === 'accepted')) {
                $missing[] = 'Accepted proctor for '.$subject->subject_name;
            }
            if (collect($s->faculty)->contains(fn ($f) => $f['status'] !== 'accepted')) {
                $missing[] = 'Faculty responses for '.$subject->subject_name;
            }
        }
        if ($w->application->subjects->isEmpty()) {
            $missing[] = 'Exam subjects';
        }
        foreach (app(ComprehensiveDocumentChecklist::class)->pending($w->student) as $doc) {
            $missing[] = $doc;
        }

        return array_values(array_unique($missing));
    }

    public function receipt(ComprehensiveWorkflow $w): ?PaymentSubmission
    {
        return PaymentSubmission::where('student_id', $w->student_user_id)->where('payment_type', 'exam')->where('exam_application_id', $w->application_id)->latest('payment_id')->first();
    }

    public function stage(ComprehensiveWorkflow $w): string
    {
        if ($w->aa_status === 'lacking') {
            return 'Requirements lacking';
        }
        if ($w->registrar_status === 'returned') {
            return 'Returned by Registrar';
        }
        if ($w->registrar_status === 'approved') {
            return $this->receipt($w)?->status === 'approved' ? 'Ready for exam' : 'Approved — ready for payment';
        }
        if ($w->aa_status !== 'evaluated') {
            return 'Awaiting AA evaluation';
        }

        return $this->missing($w) ? 'Scheduling / faculty confirmation' : 'Awaiting Registrar approval';
    }
}
