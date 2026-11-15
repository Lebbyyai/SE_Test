<?php

namespace App\Http\Controllers;

use App\Models\ComprehensiveSession;
use App\Models\ComprehensiveWorkflow;
use App\Models\DefenseRequest;
use App\Models\ExamApplication;
use App\Models\User;
use App\Services\ComprehensiveDocumentChecklist;
use App\Services\ComprehensiveWorkflowService;
use App\Services\DefenseConflictService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ComprehensiveWorkflowController extends Controller
{
    public function __construct(private ComprehensiveWorkflowService $flow) {}

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['workflow' => $message]);
    }

    private function editable(ComprehensiveWorkflow $w): void
    {
        if ($w->registrar_status === 'approved') {
            $this->fail('Registrar already approved this application. Request a Registrar return before changing it.');
        }
    }

    public function index(Request $r)
    {
        $u = $r->user();
        abort_unless(in_array($u->role, ComprehensiveWorkflowService::ROLES, true), 403);
        $query = ComprehensiveWorkflow::with(['application.subjects', 'student', 'sessions']);
        if ($u->role === 'Student') {
            $query->where('student_user_id', $u->id);
        }
        if ($u->role === 'Coordinator') {
            $query->whereHas('application', fn ($q) => $q->whereIn('program', $u->allowedProgramNames()));
        }
        $rows = $query->latest('id')->get();
        if ($u->role === 'Adviser') {
            $rows = $rows->filter(fn ($w) => $w->sessions->contains(fn ($s) => collect($s->faculty)->contains(fn ($f) => (int) $f['user_id'] === $u->id)));
        }
        $faculty = User::where('role', 'Adviser')->orderBy('last_name')->get(['id', 'first_name', 'middle_name', 'last_name']);

        return Inertia::render('comprehensive-workflow/Index', [
            'role' => $u->role, 'userId' => $u->id, 'program' => $u->program,
            'faculty' => $faculty->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->values(),
            'applications' => $rows->map(function ($w) use ($faculty, $u) {
                $payment = $this->flow->receipt($w);

                return ['id' => $w->id, 'application_id' => $w->application_id, 'student_id' => $w->student_user_id, 'student' => $w->student->name,
                    'program' => $w->application->program, 'school_year' => $w->application->school_year, 'semester' => $w->semester,
                    'status' => $this->flow->stage($w), 'aa_status' => $w->aa_status, 'aa_note' => $w->aa_note,
                    'registrar_status' => $w->registrar_status, 'registrar_note' => $w->registrar_note, 'version' => $w->version,
                    'history' => $w->history ?? [], 'missing' => $this->flow->missing($w), 'receipt_status' => $payment?->status,
                    'subjects' => $w->application->subjects->map(function ($sub) use ($w, $faculty) {
                        $s = $w->sessions->firstWhere('subject_id', $sub->id);

                        return [
                            'id' => $sub->id, 'name' => $sub->subject_name, 'room' => $s?->room, 'date' => $s?->exam_date,
                            'start' => $s ? substr($s->start_time, 0, 5) : null, 'end' => $s ? substr($s->end_time, 0, 5) : null,
                            'faculty' => collect($s?->faculty ?? [])->map(fn ($f) => $f + ['name' => $faculty->firstWhere('id', (int) $f['user_id'])?->name ?? 'Former faculty account'])->values(),
                        ];
                    })->values(),
                    'deliveries' => DB::table('comprehensive_deliveries')->where('workflow_id', $w->id)->when($u->role === 'Student' || $u->role === 'Adviser', fn ($q) => $q->where('user_id', $u->id))->latest('id')->limit(10)->get(['channel', 'status', 'detail', 'created_at']),
                ];
            })->values(),
        ]);
    }

    public function apply(Request $r)
    {
        $u = $r->user();
        abort_unless($u->role === 'Student', 403);
        $d = $r->validate(['school_year' => ['required', 'regex:/^\d{4}-\d{4}$/'], 'semester' => 'required|in:1st,2nd,Summer', 'contact' => 'required|string|max:50', 'subjects' => 'required|array|min:1|max:12', 'subjects.*' => 'required|string|max:255|distinct', 'sms_opt_in' => 'required|boolean']);
        $years = explode('-', $d['school_year']);
        if ((int) $years[1] !== (int) $years[0] + 1) {
            $this->fail('School year must use consecutive years.');
        }
        if (! filled($u->program)) {
            $this->fail('Ask the Coordinator to record your program first.');
        }
        DB::transaction(function () use ($u, $d) {
            $this->flow->lock();
            if (ComprehensiveWorkflow::where('student_user_id', $u->id)->where('semester', $d['semester'])->whereHas('application', fn ($q) => $q->where('school_year', $d['school_year']))->exists()) {
                $this->fail('An application already exists for this school year and semester.');
            }
            $a = ExamApplication::create(['student_id' => (string) ($u->school_id ?: $u->id), 'school_year' => $d['school_year'], 'program' => $u->program, 'contact_number' => $d['contact'], 'permit_status' => 'pending', 'final_approval_status' => 'pending', 'created_at' => now()]);
            foreach ($d['subjects'] as $name) {
                $a->subjects()->create(['subject_name' => trim($name)]);
            }
            $w = ComprehensiveWorkflow::create(['application_id' => $a->application_id, 'student_user_id' => $u->id, 'semester' => $d['semester'], 'sms_opt_in' => $d['sms_opt_in'], 'history' => []]);
            $this->flow->record($w, $u, 'Application submitted', 'Awaiting school-record evaluation. Submission is not clearance or approval.');
            $this->flow->notifyReviewers($w, 'Administrative Assistant', $u->name.' submitted a comprehensive exam application.');
        });

        return back()->with('success', 'Application submitted for evaluation.');
    }

    public function review(Request $r, ComprehensiveWorkflow $workflow)
    {
        $d = $r->validate(['version' => 'required|integer|min:0', 'action' => 'required|in:evaluated,lacking,approve,return', 'note' => 'nullable|string|max:2000', 'record_reference' => 'nullable|string|max:1500', 'records_checked' => 'nullable|boolean']);
        DB::transaction(function () use ($r, $workflow, $d) {
            $this->flow->lock();
            $w = ComprehensiveWorkflow::with(['application.subjects', 'student', 'sessions'])->lockForUpdate()->findOrFail($workflow->id);
            $this->flow->access($r->user(), $w);
            $this->flow->version($w, (int) $d['version']);
            if (in_array($d['action'], ['evaluated', 'lacking'], true)) {
                abort_unless($r->user()->role === 'Administrative Assistant', 403);
                $this->editable($w);
                if ($d['action'] === 'evaluated' && app(ComprehensiveDocumentChecklist::class)->pending($w->student)) {
                    $this->fail('Verify all requested documents first.');
                }
                if ($d['action'] === 'lacking' && mb_strlen(trim($d['note'] ?? '')) < 3) {
                    $this->fail('Describe the lacking requirements.');
                }
                if ($d['action'] === 'evaluated' && ! ($d['records_checked'] ?? false)) {
                    $this->fail('Confirm the academic requirements and grades were checked against school records.');
                }
                $w->aa_status = $d['action'];
                $w->aa_note = $d['note'] ?? null;
                $w->registrar_status = 'pending';
                $w->application->update(['final_approval_status' => 'pending', 'approved_by' => null]);
                $this->flow->record($w, $r->user(), $d['action'] === 'evaluated' ? 'AA evaluated requirements' : 'AA marked requirements lacking', $d['note'] ?? null);
                if ($d['action'] === 'evaluated') {
                    $this->flow->notifyReviewers($w, 'Coordinator', 'AA evaluated '.$w->student->name.'. Check subjects and assign rooms and faculty.');
                }
            } else {
                abort_unless($r->user()->role === 'Registrar', 403);
                if ($d['action'] === 'approve') {
                    $missing = $this->flow->missing($w);
                    if ($missing) {
                        $this->fail('Complete first: '.implode('; ', $missing));
                    }
                    if (! ($d['records_checked'] ?? false) || mb_strlen(trim($d['record_reference'] ?? '')) < 5) {
                        $this->fail('Confirm grades, academic documents, subjects/credits and tuition clearance against school records and give a record reference.');
                    }
                    if ($w->registrar_status === 'approved') {
                        $this->fail('This application is already approved.');
                    }
                    $w->registrar_status = 'approved';
                    $w->record_reference = $d['record_reference'];
                    $w->application->update(['final_approval_status' => 'approved', 'final_approval_DATE' => now(), 'approved_by' => $r->user()->id]);
                    $this->flow->record($w, $r->user(), 'Registrar approved — ready for payment', $d['record_reference']);
                } else {
                    if (mb_strlen(trim($d['note'] ?? '')) < 3) {
                        $this->fail('Enter a return reason.');
                    }
                    if ($this->flow->receipt($w)) {
                        $this->fail('A receipt has already been submitted. Resolve the payment before returning this application.');
                    }
                    $w->registrar_status = 'returned';
                    $w->aa_status = 'pending';
                    $w->application->update(['final_approval_status' => 'pending', 'approved_by' => null, 'final_approval_DATE' => null]);
                    $this->flow->record($w, $r->user(), 'Registrar returned application', $d['note']);
                    $this->flow->notifyReviewers($w, 'Administrative Assistant', 'Registrar returned '.$w->student->name.': '.$d['note']);
                }
                $w->registrar_note = $d['note'] ?? null;
                $w->save();
            }
            $this->flow->notify($w, $w->student, $this->flow->stage($w).'. '.($d['note'] ?? ''));
        });

        return back()->with('success', 'Application progress updated.');
    }

    public function schedule(Request $r, ComprehensiveWorkflow $workflow)
    {
        abort_unless($r->user()->role === 'Coordinator', 403);
        $d = $r->validate(['version' => 'required|integer|min:0', 'subject_id' => 'required|integer', 'room' => 'required|string|max:150', 'date' => 'required|date_format:Y-m-d|after_or_equal:today', 'start' => 'required|date_format:H:i', 'end' => 'required|date_format:H:i|after:start', 'faculty' => 'required|array|min:1|max:8', 'faculty.*.user_id' => 'required|integer|distinct', 'faculty.*.role' => 'required|in:proctor,examiner', 'records_checked' => 'required|accepted']);
        DB::transaction(function () use ($r, $workflow, $d) {
            $this->flow->lock();
            $w = ComprehensiveWorkflow::with(['application.subjects', 'sessions'])->lockForUpdate()->findOrFail($workflow->id);
            $this->flow->access($r->user(), $w);
            $this->flow->version($w, (int) $d['version']);
            $this->editable($w);
            if ($w->aa_status !== 'evaluated') {
                $this->fail('AA must evaluate the application first.');
            }
            $subject = $w->application->subjects->firstWhere('id', (int) $d['subject_id']);
            abort_unless($subject, 422, 'Choose a subject from this application.');
            if (! collect($d['faculty'])->contains('role', 'proctor')) {
                $this->fail('Assign at least one proctor.');
            }
            $ids = array_map('intval', array_column($d['faculty'], 'user_id'));
            if (User::whereIn('id', $ids)->where('role', 'Adviser')->count() !== count($ids)) {
                $this->fail('Choose current faculty accounts only.');
            }
            $overlap = ComprehensiveSession::where('exam_date', $d['date'])->where('start_time', '<', $d['end'])->where('end_time', '>', $d['start'])->where(fn ($q) => $q->where('workflow_id', '!=', $w->id)->orWhere('subject_id', '!=', $subject->id))->lockForUpdate()->get();
            foreach ($overlap as $s) {
                if (mb_strtolower(trim($s->room)) === mb_strtolower(trim($d['room']))) {
                    $this->fail('This room already has an exam during that time.');
                }
                foreach ($s->faculty as $f) {
                    if (in_array((int) $f['user_id'], $ids, true) && $f['status'] !== 'declined') {
                        $this->fail('A selected faculty member already has an overlapping exam assignment.');
                    }
                }
            }

            $conflicts = app(DefenseConflictService::class);
            foreach ($conflicts->overlapping(new DefenseRequest, $d['date'], $d['start'], $d['end']) as $defense) {
                $people = $conflicts->people($defense);
                foreach ($ids as $id) {
                    if (isset($people[$conflicts->userIdentity($id)])) {
                        $this->fail('A selected faculty member has an overlapping defense.');
                    }
                }
            }
            $faculty = array_map(fn ($f) => ['user_id' => (int) $f['user_id'], 'role' => $f['role'], 'status' => 'pending', 'note' => null], $d['faculty']);
            ComprehensiveSession::updateOrCreate(['workflow_id' => $w->id, 'subject_id' => $subject->id], ['room' => trim($d['room']), 'exam_date' => $d['date'], 'start_time' => $d['start'], 'end_time' => $d['end'], 'faculty' => $faculty]);
            $subject->update(['exam_date' => $d['date'], 'start_time' => $d['start'], 'end_time' => $d['end']]);
            $w->registrar_status = 'pending';
            $this->flow->record($w, $r->user(), 'Exam room and faculty assigned', $subject->subject_name.' · '.$d['room'].' · '.$d['date'].' '.$d['start'].'–'.$d['end']);
            foreach (User::whereIn('id', $ids)->get() as $f) {
                $this->flow->notify($w, $f, 'You were assigned to '.$subject->subject_name.' on '.$d['date'].' '.$d['start'].'–'.$d['end'].'. Please accept or decline.');
            }
            $this->flow->notify($w, $w->student, 'Exam scheduling updated. Faculty confirmation and Registrar approval are still required.');
        });

        return back()->with('success', 'Room and faculty saved. Faculty must confirm the assignment.');
    }

    public function resubmit(Request $r, ComprehensiveWorkflow $workflow)
    {
        abort_unless($r->user()->role === 'Student', 403);
        $d = $r->validate(['version' => 'required|integer|min:0', 'note' => 'required|string|min:3|max:1500']);
        DB::transaction(function () use ($r, $workflow, $d) {
            $this->flow->lock();
            $w = ComprehensiveWorkflow::with('application')->lockForUpdate()->findOrFail($workflow->id);
            $this->flow->access($r->user(), $w);
            $this->flow->version($w, (int) $d['version']);
            $this->editable($w);
            if ($w->aa_status !== 'lacking' && $w->registrar_status !== 'returned') {
                $this->fail('Only returned applications can be resubmitted.');
            }
            $w->aa_status = 'pending';
            $w->registrar_status = 'pending';
            $this->flow->record($w, $r->user(), 'Student resubmitted corrections', $d['note']);
            $this->flow->notifyReviewers($w, 'Administrative Assistant', $w->student->name.' resubmitted exam corrections: '.$d['note']);
        });

        return back()->with('success', 'Corrections resubmitted for AA evaluation.');
    }

    public function respond(Request $r, ComprehensiveWorkflow $workflow)
    {
        abort_unless($r->user()->role === 'Adviser', 403);
        $d = $r->validate(['version' => 'required|integer|min:0', 'subject_id' => 'required|integer', 'response' => 'required|in:accepted,declined', 'note' => 'nullable|required_if:response,declined|string|min:3|max:1000']);
        DB::transaction(function () use ($r, $workflow, $d) {
            $this->flow->lock();
            $w = ComprehensiveWorkflow::with(['application', 'sessions'])->lockForUpdate()->findOrFail($workflow->id);
            $this->flow->access($r->user(), $w);
            $this->flow->version($w, (int) $d['version']);
            $this->editable($w);
            $s = $w->sessions->firstWhere('subject_id', (int) $d['subject_id']);
            abort_unless($s, 404);
            $faculty = $s->faculty;
            $found = false;
            foreach ($faculty as &$f) {
                if ((int) $f['user_id'] === $r->user()->id) {
                    $f['status'] = $d['response'];
                    $f['note'] = $d['note'] ?? null;
                    $found = true;
                }
            }unset($f);
            abort_unless($found, 403);
            $s->update(['faculty' => $faculty]);
            $this->flow->record($w, $r->user(), 'Faculty '.$d['response'].' assignment', $d['note'] ?? null);
            $this->flow->notifyReviewers($w, 'Coordinator', $r->user()->name.' '.$d['response'].' an exam assignment for '.$w->student->name.'.');
            $w->load('sessions');
            if (! $this->flow->missing($w)) {
                $this->flow->notifyReviewers($w, 'Registrar', $w->student->name.' is ready for final verification.');
            }
        });

        return back()->with('success', 'Your response was saved.');
    }

    public function export(Request $r)
    {
        abort_unless(in_array($r->user()->role, ['Coordinator', 'Administrative Assistant', 'Dean', 'Finance', 'Registrar', 'Super Admin', 'SuperAdmin'], true), 403);
        $query = ComprehensiveWorkflow::with(['application.subjects', 'student', 'sessions']);
        if ($r->user()->role === 'Coordinator') {
            $query->whereHas('application', fn ($q) => $q->whereIn('program', $r->user()->allowedProgramNames()));
        }
        $filters = $r->validate(['school_year' => 'nullable|string|max:20', 'semester' => 'nullable|in:1st,2nd,Summer', 'program' => 'nullable|string|max:255']);
        if (! empty($filters['school_year'])) {
            $query->whereHas('application', fn ($q) => $q->where('school_year', $filters['school_year']));
        }
        if (! empty($filters['semester'])) {
            $query->where('semester', $filters['semester']);
        }
        if (! empty($filters['program'])) {
            $query->whereHas('application', fn ($q) => $q->where('program', $filters['program']));
        }
        $rows = $query->get();
        $users = User::all()->keyBy('id');

        return response()->streamDownload(function () use ($rows, $users) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Application', 'Student', 'Program', 'School year', 'Semester', 'Subject', 'Room', 'Date', 'Start (PH)', 'End (PH)', 'Faculty', 'Assignment', 'Response', 'Application progress', 'Payment', 'Honorarium note']);
            foreach ($rows as $w) {
                foreach ($w->sessions as $s) {
                    foreach ($s->faculty as $f) {
                        $row = [$w->application_id, $w->student->name, $w->application->program, $w->application->school_year, $w->semester, $w->application->subjects->firstWhere('id', $s->subject_id)?->subject_name, $s->room, $s->exam_date, substr($s->start_time, 0, 5), substr($s->end_time, 0, 5), $users->get($f['user_id'])?->name, $f['role'], $f['status'], $this->flow->stage($w), $this->flow->receipt($w)?->status ?? 'Not submitted', 'Assignment is not proof of rendered service or payment.'];
                        fputcsv($out, array_map(fn ($v) => preg_match('/^[=+@\-\t\r]/', (string) $v) ? "'".$v : $v, $row));
                    }
                }
            }fclose($out);
        }, 'comprehensive-faculty-assignments.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
