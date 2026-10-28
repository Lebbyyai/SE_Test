<?php

use App\Models\Adviser;
use App\Models\User;
use App\Services\AccountProfileName;
use App\Services\LegacyPortalClient;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

beforeEach(function () {
    $this->withoutVite();
    config(['services.google.client_id' => 'test', 'services.google.client_secret' => 'test',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
        'services.google.allowed_domain' => 'uic.edu.ph']);
});

function mockGoogleNameIdentity(array $profile = []): void
{
    $raw = array_merge(['email_verified' => true, 'hd' => 'uic.edu.ph',
        'given_name' => 'Lebron Carlo', 'family_name' => 'Fuentes'], $profile);
    $identity = (new GoogleUser)->setRaw($raw)->map(['id' => 'google-profile-test',
        'email' => 'profile@uic.edu.ph', 'name' => 'Lebron Carlo Fuentes']);
    $provider = Mockery::mock();
    $provider->shouldReceive('user')->once()->andReturn($identity);
    Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
}

test('verified Google sign in repairs a placeholder and preserves credentials and role', function () {
    $user = User::factory()->create(['email' => 'profile@uic.edu.ph', 'first_name' => 'New',
        'middle_name' => null, 'last_name' => 'User', 'role' => 'Adviser', 'is_demo_account' => false]);
    $password = $user->password;
    mockGoogleNameIdentity();
    $this->get('/auth/google/callback')->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    $user->refresh();
    expect($user->first_name)->toBe('Lebron Carlo')->and($user->last_name)->toBe('Fuentes')
        ->and($user->role)->toBe('Adviser')->and($user->password)->toBe($password);
});

test('Google sign in preserves a previously corrected name', function () {
    $user = User::factory()->create(['email' => 'profile@uic.edu.ph', 'first_name' => 'Corrected',
        'middle_name' => 'Middle', 'last_name' => 'Name', 'is_demo_account' => false]);
    mockGoogleNameIdentity();
    $this->get('/auth/google/callback')->assertRedirect('/dashboard');
    expect($user->fresh()->first_name)->toBe('Corrected')->and($user->fresh()->last_name)->toBe('Name')
        ->and($user->fresh()->middle_name)->toBe('Middle');
});

test('missing Google names do not replace a placeholder with blank data', function () {
    $user = User::factory()->create(['email' => 'profile@uic.edu.ph', 'first_name' => 'New',
        'last_name' => 'User', 'role' => 'Student', 'is_demo_account' => false]);
    mockGoogleNameIdentity(['given_name' => '', 'family_name' => null]);
    $this->get('/auth/google/callback')->assertRedirect('/dashboard');
    expect($user->fresh()->first_name)->toBe('New')->and($user->fresh()->last_name)->toBe('User');
});

test('unverified Google identity cannot change account names', function () {
    $user = User::factory()->create(['email' => 'profile@uic.edu.ph', 'first_name' => 'New',
        'last_name' => 'User', 'is_demo_account' => false]);
    mockGoogleNameIdentity(['email_verified' => false]);
    $this->get('/auth/google/callback')->assertSessionHasErrors('google');
    $this->assertGuest();
    expect($user->fresh()->first_name)->toBe('New');
});

test('adviser registration repairs the placeholder before the list can overwrite the real name', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $user = User::factory()->create(['role' => 'Adviser', 'first_name' => 'New', 'last_name' => 'User']);
    $row = Adviser::create(['coordinator_id' => $coordinator->id, 'email' => $user->email,
        'first_name' => 'Actual', 'middle_name' => 'Carlo', 'last_name' => 'Adviser', 'status' => 'active']);
    $this->actingAs($coordinator)->getJson('/api/coordinator/advisers')->assertOk();
    expect($user->fresh()->first_name)->toBe('Actual')->and($row->fresh()->last_name)->toBe('Adviser');
});

test('conflicting adviser registration names are not guessed', function () {
    $user = User::factory()->create(['role' => 'Adviser', 'first_name' => 'New', 'last_name' => 'User']);
    foreach (['First', 'Different'] as $name) {
        Adviser::create(['coordinator_id' => User::factory()->create(['role' => 'Coordinator'])->id,
            'email' => $user->email, 'first_name' => $name, 'last_name' => 'Adviser', 'status' => 'active']);
    }
    app(AccountProfileName::class)->fromAdviserRegistration($user);
    expect($user->fresh()->first_name)->toBe('New');
});

test('email login repairs a placeholder from the existing adviser registration', function () {
    $this->mock(LegacyPortalClient::class)->shouldNotReceive('login', 'loginCoordinator');
    $user = User::factory()->create(['role' => 'Adviser', 'email' => 'adviser@uic.edu.ph',
        'first_name' => 'New', 'last_name' => 'User', 'is_demo_account' => true]);
    Adviser::create(['coordinator_id' => User::factory()->create(['role' => 'Coordinator'])->id,
        'email' => $user->email, 'first_name' => 'Actual', 'last_name' => 'Adviser', 'status' => 'active']);
    $this->post('/login', ['identifier' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->first_name)->toBe('Actual');
});
