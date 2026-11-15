<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DemoProgramStudentsSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Program demo students can only be created locally or in tests.');
        }
        $accounts = config('demo.local_student_accounts', []);
        DB::transaction(function () use ($accounts) {
            $existing = User::whereIn('email', array_keys($accounts))->lockForUpdate()->get();
            if ($existing->contains(fn ($user) => ! $user->is_demo_account)) {
                throw new \RuntimeException('A requested demo email belongs to a non-demo user. No accounts were changed.');
            }
            $adviser = User::where('email', 'adviser@uic.edu.ph')->where('is_demo_account', true)->where('role', 'Adviser')->first();
            $coordinator = User::where('email', 'acadcoor@uic.edu.ph')->where('is_demo_account', true)->where('role', 'Coordinator')->first();
            $role = Role::firstOrCreate(['name' => 'Student']);
            foreach ($accounts as $email => $profile) {
                if ($existing->contains('email', $email)) {
                    continue; // Preserve edited profiles, passwords and assignments on rerun.
                }
                $student = new User;
                $student->forceFill($profile + ['email' => $email, 'password' => Hash::make('password'),
                    'is_demo_account' => true, 'email_verified_at' => now(), 'role_id' => $role->id,
                    'role_manually_assigned' => true])->save();
                $student->roles()->sync([$role->id]);
                if ($adviser && $coordinator) {
                    $student->advisers()->attach($adviser->id, ['status' => 'accepted', 'requested_by' => $coordinator->id]);
                }
            }
        });
        $this->command?->info(count($accounts).' local program demo accounts are ready. Existing accounts are unchanged. No defense, grade, outcome or payment records were created.');
    }
}
