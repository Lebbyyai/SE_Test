<?php

namespace App\Console\Commands;

use App\Models\ComprehensiveExamResult;
use App\Models\CoordinatorProgram;
use App\Models\ExamApplication;
use App\Models\Panelist;
use App\Models\PaymentRate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateWorkflowDemo extends Command
{
    protected $signature = 'demo:graduate-workflow';

    protected $description = 'Create local SQLite demo accounts and sample graduate workflow records';

    public function handle(): int
    {
        if (! app()->environment('local') || config('database.default') !== 'sqlite') {
            $this->error('Use a separate local SQLite database for this demo.');

            return self::FAILURE;
        }

        if ($this->call('demo:diagram-accounts') !== self::SUCCESS || $this->call('demo:finance-account') !== self::SUCCESS) {
            return self::FAILURE;
        }

        DB::transaction(function () {
            foreach (['Registrar', 'Proctor'] as $name) {
                $role = Role::firstOrCreate(['name' => $name]);
                $user = User::firstOrNew(['email' => strtolower($name).'@uic.edu.ph']);
                if (! $user->exists) {
                    $user->forceFill(['first_name' => 'Demo', 'last_name' => $name, 'school_id' => 'DEMO-'.strtoupper($name), 'password' => Hash::make('password'), 'role' => $name, 'role_id' => $role->id, 'is_demo_account' => true, 'role_manually_assigned' => true, 'email_verified_at' => now()])->save();
                    $user->roles()->sync([$role->id]);
                }
            }
            $student = User::where('email', 'student@uic.edu.ph')->firstOrFail();
            $coordinator = User::where('email', 'acadcoor@uic.edu.ph')->firstOrFail();
            CoordinatorProgram::firstOrCreate(['coordinator_id' => $coordinator->id, 'program' => $student->program]);
            ExamApplication::firstOrCreate(['student_id' => $student->school_id, 'school_year' => '2026-2027'], ['program' => $student->program, 'created_at' => now(), 'permit_status' => 'pending', 'final_approval_status' => 'pending', 'registrar_approval_status' => 'pending']);

            $defenseStudent = User::where('email', 'student2@uic.edu.ph')->firstOrFail();
            $adviser = User::where('email', 'adviser@uic.edu.ph')->firstOrFail();
            ComprehensiveExamResult::firstOrCreate([
                'student_id' => $defenseStudent->id, 'program' => $defenseStudent->program,
            ], [
                'result' => 'passed', 'exam_date' => now('Asia/Manila')->subDay()->toDateString(),
                'record_reference' => 'DEMO ONLY - simulated passing result', 'recorded_by' => $coordinator->id,
                'version' => 1, 'history' => [],
            ]);
            $defenseStudent->advisers()->syncWithoutDetaching([$adviser->id => ['status' => 'accepted', 'requested_by' => $coordinator->id]]);
            foreach (['DEMO Chair' => 'chair@example.test', 'DEMO Panel Member' => 'panel@example.test'] as $name => $email) {
                Panelist::firstOrCreate(['email' => $email], ['name' => $name, 'role' => 'Panel Member', 'status' => 'Not Assigned']);
            }
            foreach (['Panel Chair' => 1500, 'Panel Member 1' => 600, 'Adviser' => 1000] as $type => $amount) {
                PaymentRate::firstOrCreate(['program_level' => 'Masteral', 'defense_type' => 'Proposal', 'type' => $type], ['amount' => $amount]);
            }
        });

        $this->info('Application ready for Registrar review. Use student2@uic.edu.ph to start a Proposal Defense.');
        $this->line('Registrar and Proctor use registrar@uic.edu.ph and proctor@uic.edu.ph. All local demo passwords are password.');

        return self::SUCCESS;
    }
}
