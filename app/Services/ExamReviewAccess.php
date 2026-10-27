<?php

namespace App\Services;

use App\Models\User;

class ExamReviewAccess
{
    public function authorize(User $user): void
    {
        abort_unless($user->role === 'Administrative Assistant' || $user->isCoordinator(), 403);
    }

    public function programs(User $user): array
    {
        $this->authorize($user);
        if ($user->role === 'Administrative Assistant') {
            return User::where('role', 'Student')->whereNotNull('program')->distinct()->pluck('program')->filter()->values()->all();
        }

        return $user->allowedProgramNames();
    }
}
