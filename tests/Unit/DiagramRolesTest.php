<?php

use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Services\LegacyPortalClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('d', 32))]);
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name')->unique();
    });
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        foreach (['first_name', 'middle_name', 'last_name', 'email', 'password', 'role', 'school_id', 'program', 'coordinator_code'] as $field) {
            $table->string($field)->nullable();
        }
        $table->foreignId('role_id')->nullable()->constrained('roles');
        $table->boolean('role_manually_assigned')->default(false);
        $table->boolean('is_demo_account')->default(false);
        $table->timestamp('email_verified_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    Schema::create('role_user', function (Blueprint $table) {
        $table->foreignId('role_id')->constrained('roles');
        $table->foreignId('user_id')->constrained('users');
        $table->primary(['role_id', 'user_id']);
    });
    Schema::create('payment_rates', function (Blueprint $table) {
        $table->id();
        $table->string('program_level');
        $table->string('type');
        $table->string('defense_type');
        $table->decimal('amount', 12, 2);
        $table->timestamps();
    });
});

afterEach(function () {
    foreach (['payment_rates', 'role_user', 'users', 'roles'] as $table) {
        Schema::dropIfExists($table);
    }
});

it('creates the configured demo role accounts and is safe to rerun', function () {
    $this->artisan('demo:diagram-accounts')->assertSuccessful();
    $this->artisan('demo:diagram-accounts')->assertSuccessful();
    expect(User::count())->toBe(count(config('demo.accounts')));
    foreach (config('demo.accounts') as $email => $data) {
        $user = User::where('email', $email)->firstOrFail();
        expect($user->role)->toBe($data['role']);
        expect($user->roles()->pluck('name')->all())->toBe([$data['role']]);
        expect(Hash::check('password', $user->password))->toBeTrue();
    }
});

it('does not overwrite an existing non-demo account', function () {
    DB::table('users')->insert(['email' => 'dean@uic.edu.ph', 'role' => 'Dean']);
    $this->artisan('demo:diagram-accounts')->assertFailed();
    expect(User::count())->toBe(1);
    expect(User::first()->is_demo_account)->toBeFalse();
});

it('refuses to create demo accounts in production', function () {
    $this->app->instance('env', 'production');
    $this->artisan('demo:diagram-accounts')->assertFailed();
    expect(User::count())->toBe(0);
});

it('authenticates each demo account locally without calling the school portal', function () {
    $this->artisan('demo:diagram-accounts')->assertSuccessful();
    $this->mock(LegacyPortalClient::class)->shouldNotReceive('login', 'loginCoordinator');
    foreach (array_keys(config('demo.accounts')) as $email) {
        Auth::forgetGuards();
        $request = LoginRequest::create('/login', 'POST', ['identifier' => $email, 'password' => 'password']);
        $request->setContainer(app());
        $request->authenticate();
        expect(Auth::user()->email)->toBe($email);
    }
});

it('rejects the wrong demo password', function () {
    $this->artisan('demo:diagram-accounts')->assertSuccessful();
    $request = LoginRequest::create('/login', 'POST', ['identifier' => 'dean@uic.edu.ph', 'password' => 'wrong']);
    $request->setContainer(app());
    expect(fn () => $request->authenticate())->toThrow(ValidationException::class);
    $this->assertGuest();
});

it('allows only the dean to change payment rates among the diagram roles', function () {
    $this->artisan('demo:diagram-accounts')->assertSuccessful();
    $payload = ['rates' => [['program_level' => 'Masteral', 'type' => 'REC Fee', 'defense_type' => 'Proposal', 'amount' => 500]]];
    foreach (array_keys(config('demo.accounts')) as $email) {
        $user = User::where('email', $email)->firstOrFail();
        $response = $this->actingAs($user)->postJson('/dean/payment-rates', $payload);
        if ($user->role === 'Dean') {
            $response->assertOk();
        } else {
            $response->assertForbidden();
        }
    }
    expect(DB::table('payment_rates')->count())->toBe(1);
});

it('prevents non-deans from uploading document templates', function () {
    $this->artisan('demo:diagram-accounts')->assertSuccessful();
    foreach (array_keys(config('demo.accounts')) as $email) {
        $user = User::where('email', $email)->firstOrFail();
        $response = $this->actingAs($user)->postJson('/api/document-templates', []);
        if ($user->role === 'Dean') {
            $response->assertUnprocessable();
        } else {
            $response->assertForbidden();
        }
    }
});
it('authenticates the Finance demo locally without contacting school services', function () {
    $this->artisan('demo:finance-account')->assertSuccessful();
    $this->artisan('demo:finance-account')->assertSuccessful();
    expect(User::where('email', 'finance@uic.edu.ph')->count())->toBe(1);
    $this->mock(LegacyPortalClient::class)->shouldNotReceive('login', 'loginCoordinator');
    $request = LoginRequest::create('/login', 'POST', ['identifier' => 'finance@uic.edu.ph', 'password' => 'password']);
    $request->setContainer(app());
    $request->authenticate();
    expect(Auth::user()->role)->toBe('Finance');
});
