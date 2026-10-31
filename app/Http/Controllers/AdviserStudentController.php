<?php

namespace App\Http\Controllers;

use App\Mail\StudentAcceptedByAdviser;
use App\Mail\StudentRejectedByAdviser;
use App\Models\User;
use App\Services\AdviserAssignmentNotifications;
use App\Services\StudentProgressService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class AdviserStudentController extends Controller
{
    // Return accepted students only
    public function index(Request $request)
    {
        abort_unless($request->user()?->role === 'Adviser', 403);
        $adviser = $request->user();
        $assignedStudents = $adviser->advisedStudents()
            ->wherePivot('status', 'accepted')
            ->get();
        $summaries = app(StudentProgressService::class)->summaries($assignedStudents);
        $students = $assignedStudents->map(function ($s) use ($summaries) {
            // Get coordinator name from pivot
            $coordinatorName = null;
            if ($s->pivot && $s->pivot->requested_by) {
                $coordinator = User::find($s->pivot->requested_by);
                if ($coordinator) {
                    $coordinatorName = trim(
                        $coordinator->first_name.' '.
                        ($coordinator->middle_name ? strtoupper($coordinator->middle_name[0]).'. ' : '').
                        $coordinator->last_name
                    );
                }
            }

            return [
                'id' => $s->id,
                'student_number' => $s->student_number ?: $s->school_id,
                'progress' => $summaries[$s->id] ?? null,
                'first_name' => $s->first_name ?? null,
                'middle_name' => $s->middle_name ?? null,
                'last_name' => $s->last_name ?? null,
                'email' => $s->email ?? null,
                'program' => $s->program ?? null,
                'coordinator_name' => $coordinatorName,
            ];
        })->values();

        return response()->json($students);
    }

    // Return pending assignments (adviser view)
    public function pending(Request $request)
    {
        abort_unless($request->user()?->role === 'Adviser', 403);
        $adviser = $request->user();
        $students = $adviser->advisedStudents()
            ->wherePivot('status', 'pending')
            ->get()
            ->map(function ($s) {
                // Get coordinator name from pivot
                $coordinatorName = null;
                if ($s->pivot && $s->pivot->requested_by) {
                    $coordinator = User::find($s->pivot->requested_by);
                    if ($coordinator) {
                        $coordinatorName = trim(
                            $coordinator->first_name.' '.
                            ($coordinator->middle_name ? strtoupper($coordinator->middle_name[0]).'. ' : '').
                            $coordinator->last_name
                        );
                    }
                }

                return [
                    'id' => $s->id,
                    'student_number' => $s->student_number ?? null,
                    'first_name' => $s->first_name ?? null,
                    'middle_name' => $s->middle_name ?? null,
                    'last_name' => $s->last_name ?? null,
                    'email' => $s->email ?? null,
                    'program' => $s->program ?? null,
                    'coordinator_name' => $coordinatorName,
                    'requested_by' => $s->pivot->requested_by ?? null,
                    'requested_at' => $s->pivot->created_at ?? null,
                ];
            })->values();

        return response()->json($students);
    }

    // Adviser accepts a pending student
    public function acceptPending(Request $request, $studentId)
    {
        abort_unless($request->user()?->role === 'Adviser', 403);
        $request->validate(['send_email' => 'sometimes|boolean']);

        $adviser = $request->user();

        if (! $adviser->advisedStudents()->wherePivot('student_id', $studentId)->wherePivot('status', 'pending')->exists()) {
            return response()->json(['error' => 'Pending assignment not found.'], 404);
        }

        $pendingStudent = $adviser->advisedStudents()->whereKey($studentId)->firstOrFail();
        $adviser->advisedStudents()->updateExistingPivot($studentId, ['status' => 'accepted']);
        app(AdviserAssignmentNotifications::class)->decided($adviser, $pendingStudent, 'accepted', $pendingStudent->pivot->requested_by);

        // Send welcome email to student
        try {
            $student = User::find($studentId);
            if ($request->boolean('send_email') && $student && $student->email) {
                Mail::to($student->email)
                    ->send(new StudentAcceptedByAdviser($student, $adviser));

                Log::info('Student Acceptance: Welcome email sent to student', [
                    'student_id' => $student->id,
                    'student_email' => $student->email,
                    'adviser_id' => $adviser->id,
                    'adviser_name' => trim(($adviser->first_name ?? '').' '.($adviser->last_name ?? '')),
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Student Acceptance: Failed to send welcome email to student', [
                'student_id' => $studentId,
                'adviser_id' => $adviser->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the acceptance if email fails
        }

        return response()->json(['success' => true]);
    }

    // Adviser rejects a pending student (marks rejected and sends email)
    public function rejectPending(Request $request, $studentId)
    {
        abort_unless($request->user()?->role === 'Adviser', 403);
        $request->validate(['send_email' => 'sometimes|boolean']);

        $adviser = $request->user();

        if (! $adviser->advisedStudents()->wherePivot('student_id', $studentId)->wherePivot('status', 'pending')->exists()) {
            return response()->json(['error' => 'Pending assignment not found.'], 404);
        }

        // Mark as rejected (do NOT delete)
        $pendingStudent = $adviser->advisedStudents()->whereKey($studentId)->firstOrFail();
        $adviser->advisedStudents()->updateExistingPivot($studentId, ['status' => 'rejected']);
        app(AdviserAssignmentNotifications::class)->decided($adviser, $pendingStudent, 'rejected', $pendingStudent->pivot->requested_by);

        // Send rejection email to student
        try {
            $student = User::find($studentId);
            if ($request->boolean('send_email') && $student && $student->email) {
                // Get the pivot data to find who assigned this student
                $pivotStudent = $adviser->advisedStudents()->wherePivot('student_id', $studentId)->first();
                $coordinatorId = $pivotStudent->pivot->requested_by ?? null;

                if ($coordinatorId) {
                    $coordinator = User::find($coordinatorId);
                    if ($coordinator) {
                        Mail::to($student->email)
                            ->send(new StudentRejectedByAdviser($student, $adviser, $coordinator));

                        Log::info('Student Rejection: Email sent to student', [
                            'student_id' => $student->id,
                            'student_email' => $student->email,
                            'adviser_id' => $adviser->id,
                            'adviser_name' => trim(($adviser->first_name ?? '').' '.($adviser->last_name ?? '')),
                            'coordinator_id' => $coordinator->id,
                        ]);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error('Student Rejection: Failed to send email to student', [
                'student_id' => $studentId,
                'adviser_id' => $adviser->id,
                'error' => $e->getMessage(),
            ]);
            // Don't fail the rejection if email fails
        }

        return response()->json(['success' => true]);
    }

    // Direct attach by adviser (manual register)
    public function store(Request $request)
    {
        abort_unless($request->user()?->role === 'Adviser', 403);
        $request->validate([
            'student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'Student')],
        ]);

        $adviser = $request->user();
        $studentId = $request->input('student_id');

        return DB::transaction(function () use ($adviser, $studentId) {
            $student = User::whereKey($studentId)->lockForUpdate()->firstOrFail();
            if ($student->advisers()->wherePivotIn('status', ['accepted', 'pending'])->exists()) {
                return response()->json(['error' => 'This student already has an accepted or pending adviser assignment.'], 409);
            }
            $adviser->advisedStudents()->syncWithoutDetaching([$studentId => ['status' => 'accepted']]);

            return response()->json(['success' => true]);
        });
    }

    public function destroy(Request $request, $studentId)
    {
        abort_unless($request->user()?->role === 'Adviser', 403);
        $adviser = $request->user();
        $adviser->advisedStudents()->detach($studentId);

        return response()->json(['success' => true]);
    }

    public function getAdviserCode(Request $request)
    {
        abort_unless($request->user()?->role === 'Adviser', 403);
        $adviser = $request->user();
        if (! $adviser->adviser_code) {
            $adviser->generateAdviserCode();
        }

        return response()->json(['adviser_code' => $adviser->adviser_code]);
    }

    public function registerWithCode(Request $request)
    {
        $student = $request->user();
        abort_unless($student?->role === 'Student', 403);
        $data = $request->validate(['adviser_code' => 'required|string|max:255']);
        $adviser = User::where('role', 'Adviser')->where('adviser_code', $data['adviser_code'])->first();
        if (! $adviser) {
            return response()->json(['error' => 'Invalid code'], 404);
        }
        $status = DB::table('adviser_settings')->where('adviser_id', $adviser->id)->value('auto_accept_students') ? 'accepted' : 'pending';
        $registered = DB::transaction(function () use ($student, $adviser, $status) {
            User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            if ($student->advisers()->wherePivotIn('status', ['accepted', 'pending'])->exists()) {
                return false;
            }
            $adviser->advisedStudents()->syncWithoutDetaching([$student->id => ['status' => $status, 'requested_by' => null]]);
            if ($status === 'pending') {
                $assigned = $adviser->advisedStudents()->whereKey($student->id)->firstOrFail();
                app(AdviserAssignmentNotifications::class)->pending($adviser, $student, (string) $assigned->pivot->updated_at);
            }

            return true;
        });
        if (! $registered) {
            return response()->json(['error' => 'You already have an accepted or pending adviser assignment.'], 409);
        }

        return response()->json(['success' => true, 'status' => $status]);
    }

    public function hasStudents(Request $request)
    {
        $user = $request->user();
        // Count all students where this user is the adviser, regardless of role
        $count = $user->advisedStudents()->count();

        return response()->json(['hasStudents' => $count > 0]);
    }
}
