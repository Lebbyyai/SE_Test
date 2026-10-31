<?php

namespace App\Http\Controllers;

use App\Models\StudentProgressRecord;
use App\Models\User;
use App\Services\StudentProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AdviserStudentProgressController extends Controller
{
    private function authorizeStudent(Request $request, User $student): void
    {
        abort_unless($request->user()?->role === 'Adviser' && $student->role === 'Student', 403);
        abort_unless($request->user()->advisedStudents()->whereKey($student->id)->wherePivot('status', 'accepted')->exists(), 403);
    }

    public function show(Request $request, User $student, StudentProgressService $service)
    {
        $this->authorizeStudent($request, $student);

        return Inertia::render('adviser/students-list/progress', ['progress' => $service->detail($student, $request->user())]);
    }

    public function update(Request $request, User $student)
    {
        $this->authorizeStudent($request, $student);
        $year = now('Asia/Manila')->year;
        $data = $request->validate([
            'version' => 'required|integer|min:0', 'enrollment_year' => 'nullable|integer|min:1900|max:'.$year,
            'completion_year' => 'nullable|integer|min:1900|max:'.$year,
            'semester' => 'nullable|string|max:150', 'subjects' => 'present|array|max:50',
            'subjects.*.code' => 'nullable|string|max:40', 'subjects.*.title' => 'required|string|max:200',
            'record_reference' => 'required|string|min:5|max:1500', 'verified_against_school_records' => 'required|accepted',
        ]);
        $data += ['enrollment_year' => null, 'completion_year' => null, 'semester' => null];
        if ($data['completion_year'] !== null && ($data['enrollment_year'] === null || $data['completion_year'] < $data['enrollment_year'])) {
            throw ValidationException::withMessages(['completion_year' => 'Enter an enrollment year, and choose a completion year on or after it.']);
        }
        if ($data['subjects'] && ! filled($data['semester'])) {
            throw ValidationException::withMessages(['semester' => 'Enter the semester or school year for these subjects.']);
        }
        DB::transaction(function () use ($student, $data, $request) {
            User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            $this->authorizeStudent($request, $student);
            $record = StudentProgressRecord::where('student_id', $student->id)->lockForUpdate()->first();
            if (($record?->version ?? 0) !== (int) $data['version']) {
                throw ValidationException::withMessages(['version' => 'This record changed while you were editing. Reload the page before saving again.']);
            }
            $history = $record?->history ?? [];
            if ($record) {
                $history[] = [
                    'at' => $record->recorded_at->toIso8601String(), 'actor_name' => $record->recorder?->name ?? 'Former staff member',
                    'actor_id' => $record->recorded_by, 'enrollment_year' => $record->enrollment_year,
                    'completion_year' => $record->completion_year, 'semester' => $record->semester,
                    'subjects' => $record->subjects, 'record_reference' => $record->record_reference,
                ];
            }
            $record ??= new StudentProgressRecord(['student_id' => $student->id]);
            $record->fill([
                'enrollment_year' => $data['enrollment_year'], 'completion_year' => $data['completion_year'],
                'semester' => $data['semester'], 'subjects' => collect($data['subjects'])->map(fn ($subject) => ['code' => trim($subject['code'] ?? ''), 'title' => trim($subject['title'])])->values()->all(),
                'record_reference' => trim($data['record_reference']), 'recorded_by' => $request->user()->id,
                'recorded_at' => now(), 'version' => $record->exists ? $record->version + 1 : 1, 'history' => $history,
            ])->save();
        });

        return back()->with('success', 'Student progress information saved.');
    }
}
