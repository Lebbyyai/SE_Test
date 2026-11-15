<?php

namespace App\Services;

use App\Models\DefenseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class AcademicProgressAccess
{
    public function students(User $actor): Builder
    {
        $query = User::where('role', 'Student');
        if (in_array($actor->role, ['Dean', 'Administrative Assistant'], true)) {
            return $query;
        }
        if ($actor->role === 'Adviser') {
            return $query->whereIn('id', $actor->advisedStudents()->wherePivot('status', 'accepted')->pluck('users.id'));
        }
        abort_unless($actor->role === 'Coordinator', 403);

        return $query->where(function ($q) use ($actor) {
            $q->whereIn('program', $actor->allowedProgramNames())->orWhereIn('id', DefenseRequest::where('coordinator_user_id', $actor->id)->select('submitted_by'))
                ->orWhereIn('id', DB::table('adviser_student')->where('requested_by', $actor->id)->select('student_id'));
        });
    }

    public function read(User $actor, User $student): void
    {
        abort_unless($this->students($actor)->whereKey($student->id)->exists(), 403);
    }

    public function edit(User $actor, User $student): void
    {
        abort_unless($actor->role === 'Coordinator', 403);
        $this->read($actor, $student);
    }
}
