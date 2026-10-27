<?php

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('g', 32)),
        'services.google.client_id' => 'test-client',
        'services.google.client_secret' => 'test-secret',
        'services.google.redirect' => 'http://localhost/auth/google/callback',
        'services.google.allowed_domain' => 'uic.edu.ph',
    ]);
    $this->withoutVite();
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        $table->string('first_name')->default('Test');
        $table->string('middle_name')->nullable();
        $table->string('last_name')->default('Account');
        $table->string('email')->unique();
        $table->string('password')->default('existing-password-hash');
        $table->string('role')->default('Student');
        $table->boolean('is_demo_account')->default(false);
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamp('google_verified_at')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });
    $migration = require database_path('migrations/2026_10_22_000002_add_google_id_to_users_table.php');
    $migration->up();
});

afterEach(function () {
    Schema::dropIfExists('users');
});

function googleIdentity(array $raw = [], string $email = 'person@uic.edu.ph', string $id = 'google-person'): GoogleUser
{
    return (new GoogleUser)->setRaw(array_merge([
        'email_verified' => true,
        'hd' => 'uic.edu.ph',
    ], $raw))->map(['id' => $id, 'email' => $email, 'name' => 'Test Account']);
}

function mockGoogleIdentity(GoogleUser $identity): void
{
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->once()->andReturn($identity);
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
}

it('redirects to Google with session state and a school domain hint', function () {
    $response = $this->get('/auth/google')->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect(parse_url($response->headers->get('Location'), PHP_URL_HOST))->toBe('accounts.google.com');
    expect($query['hd'])->toBe('uic.edu.ph');
    expect($query['prompt'])->toBe('select_account');
    expect($query['scope'])->toContain('email')->toContain('openid');
    expect($query['state'])->not->toBeEmpty();
    expect(session('state'))->toBe($query['state']);
});

it('disables Google sign-in cleanly when credentials are missing', function () {
    config(['services.google.client_id' => null]);
    Socialite::shouldReceive('driver')->never();
    $this->get('/auth/google')->assertRedirect('/login')->assertSessionHasErrors('google');
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
    $this->get('/login')->assertOk()->assertInertia(fn ($page) => $page
        ->component('auth/login')->where('googleEnabled', false));
});

it('signs in an existing adviser and preserves their password and role', function () {
    $id = DB::table('users')->insertGetId(['email' => 'person@uic.edu.ph', 'role' => 'Adviser']);
    mockGoogleIdentity(googleIdentity());
    $this->get('/auth/google/callback?code=test-code')->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs(User::findOrFail($id));
    $user = User::findOrFail($id);
    expect($user->role)->toBe('Adviser');
    expect($user->getRawOriginal('password'))->toBe('existing-password-hash');
    expect($user->google_id)->toBe('google-person');
    expect($user->google_verified_at)->not->toBeNull();
    expect($user->email_verified_at)->not->toBeNull();
    expect($user->toArray())->not->toHaveKey('google_id');
});

it('matches school email case insensitively', function () {
    $id = DB::table('users')->insertGetId(['email' => 'Person@uic.edu.ph', 'role' => 'Coordinator']);
    mockGoogleIdentity(googleIdentity([], 'PERSON@UIC.EDU.PH'));
    $this->get('/auth/google/callback')->assertRedirect('/dashboard');
    $this->assertAuthenticatedAs(User::findOrFail($id));
    expect(User::findOrFail($id)->role)->toBe('Coordinator');
});

it('does not create accounts or infer privileged roles from a Google identity', function () {
    mockGoogleIdentity(googleIdentity());
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('does not link a real Google identity to a local demo account', function () {
    DB::table('users')->insert(['email' => 'person@uic.edu.ph', 'is_demo_account' => true]);
    mockGoogleIdentity(googleIdentity());
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
    $this->assertGuest();
});

it('rejects unverified or non-school identities', function (array $raw, string $email) {
    DB::table('users')->insert(['email' => $email]);
    mockGoogleIdentity(googleIdentity($raw, $email));
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
    $this->assertGuest();
    expect(DB::table('users')->whereNotNull('google_id')->count())->toBe(0);
})->with([
    'unverified email' => [['email_verified' => false], 'person@uic.edu.ph'],
    'string false' => [['email_verified' => 'false'], 'person@uic.edu.ph'],
    'personal Google account' => [['hd' => 'gmail.com'], 'person@gmail.com'],
    'missing hosted domain' => [['hd' => null], 'person@uic.edu.ph'],
    'lookalike domain' => [['hd' => 'uic.edu.ph.attacker.com'], 'person@uic.edu.ph.attacker.com'],
]);

it('rejects a different Google identity for an already linked account', function () {
    DB::table('users')->insert(['email' => 'person@uic.edu.ph', 'google_id' => 'original-id']);
    mockGoogleIdentity(googleIdentity());
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
    $this->assertGuest();
    expect(DB::table('users')->value('google_id'))->toBe('original-id');
});

it('prevents one Google identity from being linked to a second local account', function () {
    DB::table('users')->insert([
        ['email' => 'other@uic.edu.ph', 'google_id' => 'google-person'],
        ['email' => 'person@uic.edu.ph', 'google_id' => null],
    ]);
    mockGoogleIdentity(googleIdentity());
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
    $this->assertGuest();
});

it('supports repeat sign-in without resetting the verification timestamp', function () {
    DB::table('users')->insert([
        'email' => 'person@uic.edu.ph',
        'google_id' => 'google-person',
        'google_verified_at' => '2025-01-01 00:00:00',
    ]);
    mockGoogleIdentity(googleIdentity());
    $this->get('/auth/google/callback')->assertRedirect('/dashboard');
    expect(DB::table('users')->value('google_verified_at'))->toBe('2025-01-01 00:00:00');
});

it('rejects expired or mismatched state before calling Google', function () {
    $this->withSession(['state' => 'expected-state'])
        ->get('/auth/google/callback?state=wrong-state&code=test-code')
        ->assertRedirect('/login')->assertSessionHasErrors('google');
    $this->assertGuest();
});

it('handles consent cancellation without authenticating', function () {
    Socialite::shouldReceive('driver')->never();
    $this->withSession(['state' => 'old-state'])->get('/auth/google/callback?error=access_denied')
        ->assertRedirect('/login')->assertSessionHasErrors('google')->assertSessionMissing('state');
    $this->assertGuest();
});

it('handles provider failure without leaking its exception message', function () {
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->once()->andThrow(new RuntimeException('private-provider-details'));
    Socialite::shouldReceive('driver')->once()->with('google')->andReturn($provider);
    $this->get('/auth/google/callback')->assertRedirect('/login')->assertSessionHasErrors('google');
    expect(session('errors')->first('google'))->not->toContain('private-provider-details');
    $this->assertGuest();
});
