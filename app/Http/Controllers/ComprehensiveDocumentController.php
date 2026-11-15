<?php

namespace App\Http\Controllers;

use App\Models\ComprehensiveDocument;
use App\Models\ComprehensiveWorkflow;
use App\Models\Notification;
use App\Models\User;
use App\Services\ComprehensiveDocumentChecklist;
use App\Services\ComprehensiveWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ComprehensiveDocumentController extends Controller
{
    private function students(User $user)
    {
        $query = User::where('role', 'Student');
        if ($user->role === 'Student') {
            return $query->whereKey($user->id);
        }
        if ($user->role === 'Coordinator') {
            return $query->whereIn('program', $user->allowedProgramNames());
        }
        abort_unless(in_array($user->role, ['Administrative Assistant', 'Dean', 'Registrar', 'Super Admin', 'SuperAdmin'], true), 403);

        return $query;
    }

    private function authorizeStudent(Request $request, User $student, bool $edit = false): void
    {
        abort_unless($this->students($request->user())->whereKey($student->id)->exists(), 403);
        if ($edit) {
            abort_unless(in_array($request->user()->role, ['Coordinator', 'Administrative Assistant', 'Super Admin', 'SuperAdmin'], true), 403);
        }
    }

    public function index(Request $request)
    {
        $query = $this->students($request->user());
        $data = $request->validate(['search' => 'nullable|string|max:100', 'student' => 'nullable|integer']);
        if (! empty($data['search'])) {
            $search = '%'.$data['search'].'%';
            $query->where(fn ($q) => $q->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)->orWhere('school_id', 'like', $search));
        }
        $students = $query->orderBy('last_name')->get(['id', 'first_name', 'middle_name', 'last_name', 'school_id', 'program']);
        $selected = isset($data['student']) ? $students->firstWhere('id', (int) $data['student']) : $students->first();
        if (isset($data['student'])) {
            abort_unless($selected, 404);
        }
        $records = $selected ? ComprehensiveDocument::where('student_id', $selected->id)->get()->keyBy('type') : collect();
        $documents = collect(ComprehensiveDocumentChecklist::TYPES)->map(function ($label, $type) use ($records) {
            $record = $records->get($type);

            return [
                'type' => $type, 'label' => $label, 'required' => $record?->required ?? false,
                'instructions' => $record?->instructions, 'status' => $record?->status ?? 'missing',
                'original_name' => $record?->original_name, 'remarks' => $record?->remarks,
                'uploaded_at' => $record?->uploaded_at?->toIso8601String(),
                'reviewed_at' => $record?->reviewed_at?->toIso8601String(),
                'version' => $record?->version ?? 0, 'has_file' => (bool) $record?->path,
                'history' => $record?->history ?? [],
            ];
        })->values();

        return Inertia::render('comprehensive-documents/Index', [
            'students' => $students->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'program' => $s->program, 'school_id' => $s->school_id]),
            'selectedStudent' => $selected?->id, 'documents' => $documents,
            'isStudent' => $request->user()->role === 'Student',
            'canReview' => in_array($request->user()->role, ['Coordinator', 'Administrative Assistant', 'Super Admin', 'SuperAdmin'], true),
            'search' => $data['search'] ?? '',
        ]);
    }

    private function record(User $student, string $type, int $version): ComprehensiveDocument
    {
        abort_unless(array_key_exists($type, ComprehensiveDocumentChecklist::TYPES), 404);
        if (Schema::hasTable('comprehensive_workflow_locks')) {
            app(ComprehensiveWorkflowService::class)->lock();
        }
        User::whereKey($student->id)->lockForUpdate()->firstOrFail();
        $record = ComprehensiveDocument::where('student_id', $student->id)->where('type', $type)->lockForUpdate()->first()
            ?? new ComprehensiveDocument(['student_id' => $student->id, 'type' => $type, 'version' => 0]);
        if ($record->version !== $version) {
            throw ValidationException::withMessages(['version' => 'This document changed. Refresh before saving again.']);
        }

        return $record;
    }

    private function history(ComprehensiveDocument $record, Request $request, string $action, ?string $note): void
    {

        if (Schema::hasTable('comprehensive_workflows') && in_array($action, ['Document uploaded', 'Document requested', 'Document not required', 'Returned for correction'], true)) {
            foreach (ComprehensiveWorkflow::with('application')->where('student_user_id', $record->student_id)->where('aa_status', 'evaluated')->lockForUpdate()->get() as $workflow) {
                $flow = app(ComprehensiveWorkflowService::class);
                if ($flow->receipt($workflow)) {
                    throw ValidationException::withMessages(['document' => 'This student has an approved application with a submitted receipt. Resolve that application before changing its document requirements.']);
                }
                $workflow->aa_status = 'pending';
                $workflow->registrar_status = 'returned';
                $workflow->registrar_note = 'Documents changed. AA and Registrar must review again.';
                $workflow->application->update(['final_approval_status' => 'pending', 'approved_by' => null, 'final_approval_DATE' => null]);
                $flow->record($workflow, $request->user(), 'Document change requires renewed review', $note);
                $flow->notifyReviewers($workflow, 'Administrative Assistant', 'Documents changed for '.$workflow->student->name.'. Review the application again.');
                $flow->notify($workflow, $workflow->student, 'Documents changed. Your application requires renewed AA and Registrar review.');
            }
        }
        $history = $record->history ?? [];
        $history[] = ['action' => $action, 'actor' => $request->user()->name, 'at' => now()->toIso8601String(), 'note' => $note];
        $record->history = $history;
        $record->version++;
        $record->save();
    }

    private function notify(User $user, string $message): void
    {
        Notification::create(['user_id' => $user->id, 'type' => 'comprehensive-document', 'title' => 'Comprehensive exam documents', 'message' => $message, 'link' => '/comprehensive-documents', 'read' => false]);
    }

    public function requirement(Request $request, User $student, string $type)
    {
        $this->authorizeStudent($request, $student, true);
        $data = $request->validate(['version' => 'required|integer|min:0', 'required' => 'required|boolean', 'instructions' => 'required|string|min:3|max:1500']);
        DB::transaction(function () use ($request, $student, $type, $data) {
            $record = $this->record($student, $type, $data['version']);
            $record->fill(['required' => $data['required'], 'instructions' => $data['instructions']]);
            $this->history($record, $request, $data['required'] ? 'Document requested' : 'Document not required', $data['instructions']);
            $this->notify($student, ComprehensiveDocumentChecklist::TYPES[$type].': '.$data['instructions']);
        });

        return back()->with('success', 'Document requirement saved.');
    }

    public function upload(Request $request, User $student, string $type)
    {
        $this->authorizeStudent($request, $student);
        abort_unless($request->user()->role === 'Student', 403);
        abort_unless(array_key_exists($type, ComprehensiveDocumentChecklist::TYPES), 404);
        $data = $request->validate(['version' => 'required|integer|min:0', 'file' => 'required|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240']);
        $path = $request->file('file')->store('comprehensive-documents', 'local');
        abort_unless($path, 500, 'The document could not be stored. Please try again.');
        try {
            $oldPath = DB::transaction(function () use ($request, $student, $type, $data, $path) {
                $record = $this->record($student, $type, $data['version']);
                $oldPath = $record->path;
                $record->fill(['path' => $path, 'original_name' => mb_substr(basename(str_replace('\\', '/', $request->file('file')->getClientOriginalName())), 0, 240), 'status' => 'pending', 'uploaded_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null, 'remarks' => null]);
                $this->history($record, $request, 'Document uploaded', $record->original_name);
                $reviewers = User::where('role', 'Administrative Assistant')->orWhere(function ($q) use ($student) {
                    $q->where('role', 'Coordinator')->whereHas('coordinatorPrograms', fn ($programs) => $programs->where('program', $student->program));
                })->get();
                foreach ($reviewers as $reviewer) {
                    $this->notify($reviewer, $student->name.' uploaded '.ComprehensiveDocumentChecklist::TYPES[$type].' for review.');
                }

                return $oldPath;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
        if ($oldPath) {
            Storage::disk('local')->delete($oldPath);
        }

        return back()->with('success', 'Document uploaded. Awaiting review.');
    }

    public function review(Request $request, User $student, string $type)
    {
        $this->authorizeStudent($request, $student, true);
        $data = $request->validate(['version' => 'required|integer|min:0', 'status' => ['required', Rule::in(['verified', 'returned'])], 'remarks' => 'nullable|required_if:status,returned|string|min:3|max:1500']);
        DB::transaction(function () use ($request, $student, $type, $data) {
            $record = $this->record($student, $type, $data['version']);
            abort_unless($record->path && Storage::disk('local')->exists($record->path), 422, 'Upload a document before reviewing it.');
            $record->fill(['status' => $data['status'], 'remarks' => $data['remarks'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $this->history($record, $request, $data['status'] === 'verified' ? 'Document verified' : 'Returned for correction', $data['remarks'] ?? null);
            $this->notify($student, ComprehensiveDocumentChecklist::TYPES[$type].' was '.$data['status'].'. '.($data['remarks'] ?? ''));
        });

        return back()->with('success', 'Document review saved.');
    }

    public function download(Request $request, User $student, string $type)
    {
        $this->authorizeStudent($request, $student);
        $record = ComprehensiveDocument::where('student_id', $student->id)->where('type', $type)->firstOrFail();
        abort_unless($record->path && Storage::disk('local')->exists($record->path), 404, 'Document not found. Ask the student to upload it again.');

        return Storage::disk('local')->download($record->path, $record->original_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
