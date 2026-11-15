<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateComprehensiveDemoRegistrar extends Command
{
    protected $signature = 'comprehensive:demo-registrar';

    protected $description = 'Create a local-only Registrar account for comprehensive exam testing';

    public function handle(): int
    {
        if (! app()->environment('local') || ! in_array(config('database.connections.'.config('database.default').'.host'), ['127.0.0.1', 'localhost'], true)) {
            $this->error('This command is only available with a local environment and local database.');

            return self::FAILURE;
        }
        $user = User::where('email', 'registrar@uic.edu.ph')->first();
        if ($user) {
            $this->line('That email already exists. Its role and password were not changed.');

            return self::SUCCESS;
        }
        DB::transaction(function () {
            $role = Role::firstOrCreate(['name' => 'Registrar']);
            $user = User::create(['first_name' => 'Demo', 'last_name' => 'Registrar', 'email' => 'registrar@uic.edu.ph', 'password' => Hash::make('password'), 'role' => 'Registrar', 'school_id' => 'DEMO-REGISTRAR']);
            $user->forceFill(['role_id' => $role->id, 'role_manually_assigned' => true, 'is_demo_account' => true, 'email_verified_at' => now()])->save();
            $user->roles()->sync([$role->id]);
        });
        $this->info('Created local-only demo Registrar: registrar@uic.edu.ph. Password: password. No existing accounts changed.');

        return self::SUCCESS;
    }
}
