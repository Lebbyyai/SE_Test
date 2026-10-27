<?php

use App\Models\User;
use App\Services\LegacyPortalClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Isolate this data migration from older application migrations and their
    // MySQL-only statements. All data lives in phpunit's in-memory SQLite DB.
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
    });
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('first_name')->default('Test');
        $table->string('middle_name')->nullable();
        $table->string('last_name')->default('Adviser');
        $table->string('email')->nullable();
        $table->string('role')->nullable();
        $table->foreignId('role_id')->nullable()->constrained('roles');
        $table->timestamps();
    });
    Schema::create('role_user', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained('roles');
        $table->foreignId('user_id')->constrained('users');
        $table->primary(['role_id', 'user_id']);
    });
    Schema::create('honorarium_payments', function (Blueprint $table) {
        $table->id();
        $table->string('panelist_type');
    });
});

afterEach(function () {
    Schema::dropIfExists('honorarium_payments');
    Schema::dropIfExists('role_user');
    Schema::dropIfExists('users');
    Schema::dropIfExists('roles');
});

function migrateAdviserRole(): void
{
    $migration = require database_path('migrations/2026_10_22_000001_rename_faculty_role_to_adviser.php');
    $migration->up();
}

it('renames the old role in place and preserves its user and pivot IDs', function () {
    $roleId = DB::table('roles')->insertGetId(['name' => 'Faculty']);
    $userId = DB::table('users')->insertGetId(['role' => 'Faculty', 'role_id' => $roleId]);
    DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $userId]);
    DB::table('honorarium_payments')->insert(['panelist_type' => 'Faculty']);

    migrateAdviserRole();

    expect(DB::table('roles')->where('id', $roleId)->value('name'))->toBe('Adviser');
    expect(DB::table('users')->where('id', $userId)->value('role'))->toBe('Adviser');
    expect(DB::table('users')->where('id', $userId)->value('role_id'))->toBe($roleId);
    expect(DB::table('role_user')->where('user_id', $userId)->value('role_id'))->toBe($roleId);
    expect(DB::table('honorarium_payments')->value('panelist_type'))->toBe('Adviser');
});

it('merges existing roles without losing memberships or other user roles', function () {
    $faculty = DB::table('roles')->insertGetId(['name' => 'Faculty']);
    $adviser = DB::table('roles')->insertGetId(['name' => 'Adviser']);
    $coordinator = DB::table('roles')->insertGetId(['name' => 'Coordinator']);
    $user = DB::table('users')->insertGetId(['role' => 'Faculty', 'role_id' => $faculty]);
    $other = DB::table('users')->insertGetId(['role' => 'Coordinator', 'role_id' => $coordinator]);
    DB::table('role_user')->insert([
        ['role_id' => $faculty, 'user_id' => $user],
        ['role_id' => $adviser, 'user_id' => $user],
        ['role_id' => $faculty, 'user_id' => $other],
        ['role_id' => $coordinator, 'user_id' => $other],
    ]);

    migrateAdviserRole();
    migrateAdviserRole(); // Safe to re-run.

    expect(DB::table('roles')->where('name', 'Faculty')->exists())->toBeFalse();
    expect(DB::table('role_user')->where('user_id', $user)->pluck('role_id')->all())->toBe([$adviser]);
    expect(DB::table('role_user')->where('user_id', $other)->count())->toBe(2);
    expect(DB::table('users')->where('id', $user)->value('role_id'))->toBe($adviser);
    expect(DB::table('users')->where('id', $other)->value('role'))->toBe('Coordinator');
    expect(DB::table('users')->where('id', $other)->value('role_id'))->toBe($coordinator);
});

it('creates the adviser role on fresh databases and backfills missing role IDs', function () {
    $user = DB::table('users')->insertGetId(['role' => 'Faculty']);
    migrateAdviserRole();
    $adviser = DB::table('roles')->where('name', 'Adviser')->value('id');
    expect($adviser)->not->toBeNull();
    expect(DB::table('users')->where('id', $user)->value('role_id'))->toBe($adviser);
});

it('normalizes legacy role assignments and serializes them as Adviser', function () {
    $user = new User(['role' => 'Faculty']);
    expect($user->role)->toBe('Adviser');
    expect($user->getAttributes()['role'])->toBe('Adviser');
    $user->setRawAttributes(['role' => 'Faculty']);
    expect($user->toArray()['role'])->toBe('Adviser');
    expect($user->hasRole('Adviser'))->toBeTrue();
    expect($user->isRole('Adviser'))->toBeTrue();
});

it('adds the canonical pivot role once and preserves other roles', function () {
    $id = DB::table('users')->insertGetId(['role' => 'Coordinator']);
    $user = User::findOrFail($id);
    $user->addRole('Faculty');
    $user->addRole('Adviser');
    expect(DB::table('roles')->where('name', 'Faculty')->exists())->toBeFalse();
    expect($user->roles()->count())->toBe(1);
    expect($user->hasRole('Adviser'))->toBeTrue();
    expect($user->allRoleNames())->toBe(['Adviser', 'Coordinator']);
});

it('maps the external portal Faculty title to Adviser without changing coordinator titles', function () {
    $client = new LegacyPortalClient;
    expect($client->extractStaffRole('<span class="white-text">Faculty</span>'))
        ->toBe(['role' => 'Adviser', 'title' => 'Faculty']);
    expect($client->extractStaffRole('<span class="white-text">Adviser</span>')['role'])->toBe('Adviser');
    expect($client->extractStaffRole('<span class="white-text">Academic Coordinator</span>')['role'])->toBe('Coordinator');
});

it('finds advisers by primary or pivot role and keeps the old search URL usable', function () {
    $role = DB::table('roles')->insertGetId(['name' => 'Adviser']);
    $primary = DB::table('users')->insertGetId(['role' => 'Adviser', 'first_name' => 'Anna']);
    $pivot = DB::table('users')->insertGetId(['role' => 'Coordinator', 'first_name' => 'Ana']);
    DB::table('users')->insert(['role' => 'Student', 'first_name' => 'Ann']);
    DB::table('role_user')->insert(['role_id' => $role, 'user_id' => $pivot]);

    config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    $response = $this->getJson('/api/adviser-search?q=An')->assertOk();
    expect(collect($response->json())->pluck('id')->sort()->values()->all())->toBe([$primary, $pivot]);
    $this->get('/api/faculty-search?q=An')->assertRedirect('/api/adviser-search?q=An');
});
