<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('r', 32))]);
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
    });
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('first_name')->default('Test');
        $table->string('middle_name')->nullable();
        $table->string('last_name')->default('Account');
        $table->string('email')->unique();
        $table->string('role')->default('Student');
        $table->foreignId('role_id')->nullable()->constrained('roles');
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamps();
    });
    Schema::create('role_user', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained('roles');
        $table->foreignId('user_id')->constrained('users');
        $table->primary(['role_id', 'user_id']);
    });
    $migration = require database_path('migrations/2026_10_23_000001_add_manual_role_assignment_to_users.php');
    $migration->up();
});

afterEach(function () {
    Schema::dropIfExists('role_user');
    Schema::dropIfExists('users');
    Schema::dropIfExists('roles');
});

function roleTestAccount(string $role, string $email): User
{
    $id = DB::table('users')->insertGetId(['email' => $email, 'role' => $role]);

    return User::findOrFail($id);
}

it('allows Super Admin to assign supported roles and synchronizes all role representations', function (string $role) {
    $admin = roleTestAccount('Super Admin', 'admin@example.test');
    $user = roleTestAccount('Student', 'user@example.test');
    $oldRole = DB::table('roles')->insertGetId(['name' => 'Student']);
    DB::table('role_user')->insert(['user_id' => $user->id, 'role_id' => $oldRole]);

    $this->actingAs($admin)->patchJson('/admin/users/'.$user->id.'/role', ['role' => $role])->assertOk();

    $user->refresh();
    expect($user->role)->toBe($role);
    expect($user->role_manually_assigned)->toBeTrue();
    expect(DB::table('roles')->where('id', $user->role_id)->value('name'))->toBe($role);
    expect($user->roles()->pluck('name')->all())->toBe([$role]);
})->with(['Student', 'Adviser', 'Coordinator', 'Administrative Assistant', 'Dean']);

it('rejects unauthenticated role changes', function () {
    $user = roleTestAccount('Student', 'user@example.test');
    $this->patchJson('/admin/users/'.$user->id.'/role', ['role' => 'Dean'])->assertUnauthorized();
    expect($user->fresh()->role)->toBe('Student');
});

it('rejects role changes from non-admin accounts', function (string $role) {
    $actor = roleTestAccount($role, 'actor@example.test');
    $user = roleTestAccount('Student', 'user@example.test');
    $this->actingAs($actor)->patchJson('/admin/users/'.$user->id.'/role', ['role' => 'Dean'])->assertForbidden();
    expect($user->fresh()->role)->toBe('Student');
})->with(['Student', 'Adviser', 'Coordinator', 'Administrative Assistant', 'Dean', 'Registrar']);

it('rejects unsupported and privileged role payloads', function (string $role) {
    $admin = roleTestAccount('Super Admin', 'admin@example.test');
    $user = roleTestAccount('Student', 'user@example.test');
    $this->actingAs($admin)->patchJson('/admin/users/'.$user->id.'/role', ['role' => $role])
        ->assertUnprocessable()->assertJsonValidationErrors('role');
    expect($user->fresh()->role)->toBe('Student');
})->with(['Super Admin', 'SuperAdmin', 'Faculty', 'Registrar', 'Owner', '']);

it('protects the current Super Admin account from demotion', function () {
    $admin = roleTestAccount('Super Admin', 'admin@example.test');
    $this->actingAs($admin)->patchJson('/admin/users/'.$admin->id.'/role', ['role' => 'Student'])->assertForbidden();
    expect($admin->fresh()->role)->toBe('Super Admin');
});

it('protects another admin with a pivot-based Super Admin membership', function () {
    $admin = roleTestAccount('Super Admin', 'admin@example.test');
    $other = roleTestAccount('Coordinator', 'other@example.test');
    $id = DB::table('roles')->insertGetId(['name' => 'Super Admin']);
    DB::table('role_user')->insert(['role_id' => $id, 'user_id' => $other->id]);
    $this->actingAs($admin)->patchJson('/admin/users/'.$other->id.'/role', ['role' => 'Student'])->assertForbidden();
    expect($other->fresh()->role)->toBe('Coordinator');
});

it('keeps role membership idempotent on repeated saves', function () {
    $admin = roleTestAccount('Super Admin', 'admin@example.test');
    $user = roleTestAccount('Student', 'user@example.test');
    $this->actingAs($admin)->patchJson('/admin/users/'.$user->id.'/role', ['role' => 'Dean'])->assertOk();
    $this->patchJson('/admin/users/'.$user->id.'/role', ['role' => 'Dean'])->assertOk();
    expect($user->roles()->count())->toBe(1);
});

it('does not allow portal login to add extra roles after manual assignment', function () {
    $admin = roleTestAccount('Super Admin', 'admin@example.test');
    $user = roleTestAccount('Adviser', 'user@example.test');
    $this->actingAs($admin)->patchJson('/admin/users/'.$user->id.'/role', ['role' => 'Administrative Assistant'])->assertOk();
    $user->refresh();
    $user->addRole('Adviser');
    $user->addRole('Student');
    expect($user->roles()->pluck('name')->all())->toBe(['Administrative Assistant']);
    expect($user->role)->toBe('Administrative Assistant');
});

it('rejects attempts to self-register with a privileged role', function (string $role) {
    $this->postJson('/register', [
        'first_name' => 'Test', 'middle_name' => 'M', 'last_name' => 'Student',
        'email' => 'new@uic.edu.ph', 'school_id' => 'TEST001',
        'password' => 'test-password-123', 'password_confirmation' => 'test-password-123',
        'role' => $role,
    ])->assertUnprocessable()->assertJsonValidationErrors('role');
    expect(User::count())->toBe(0);
})->with(['Dean', 'Administrative Assistant', 'Coordinator', 'Super Admin']);
