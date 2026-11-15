<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class UserRoleController extends Controller
{
    public const ASSIGNABLE_ROLES = [
        'Student', 'Adviser', 'Coordinator', 'Administrative Assistant', 'Dean', 'Finance', 'Registrar', 'Proctor',
    ];

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()?->role === 'Super Admin', 403);

        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(self::ASSIGNABLE_ROLES)],
        ]);

        $oldRole = DB::transaction(function () use ($user, $validated) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            // This screen cannot create or demote Super Admins, including itself.
            abort_if(in_array($user->role, ['Super Admin', 'SuperAdmin'])
                || $user->hasRole('Super Admin') || $user->hasRole('SuperAdmin'), 403);

            $oldRole = $user->role;
            $role = Role::firstOrCreate(['name' => $validated['role']]);
            $user->forceFill([
                'role' => $role->name,
                'role_id' => $role->id,
                'role_manually_assigned' => true,
            ])->save();
            // Replace old memberships so they cannot continue granting access.
            $user->roles()->sync([$role->id]);

            return $oldRole;
        });

        Log::notice('Account role assigned by Super Admin.', [
            'actor_id' => $request->user()->id,
            'user_id' => $user->id,
            'old_role' => $oldRole,
            'new_role' => $validated['role'],
        ]);

        return response()->json(['message' => 'Account role updated successfully.']);
    }
}
