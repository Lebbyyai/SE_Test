<?php

use App\Models\User;
use App\Services\LegacyPortalClient;
use App\Services\ProgramResearchType;
use Database\Seeders\DemoProgramStudentsSeeder;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

it('creates local program students with scoped assignments and preserves edits on rerun', function () {
    $this->withoutVite();
    $adviser = User::factory()->create(['email' => 'adviser@uic.edu.ph', 'role' => 'Adviser', 'is_demo_account' => true]);
    $coordinator = User::factory()->create(['email' => 'acadcoor@uic.edu.ph', 'role' => 'Coordinator', 'is_demo_account' => true]);
    $this->seed(DemoProgramStudentsSeeder::class);
    $student = User::where('email', 'doctorate@uic.edu.ph')->firstOrFail();
    expect($student->program)->toBe('Doctor in Business Management')->and(Hash::check('password', $student->password))->toBeTrue();
    $student->update(['first_name' => 'Edited Demo']);
    $this->seed(DemoProgramStudentsSeeder::class);
    $this->assertDatabaseCount('users', 8);
    expect($student->fresh()->first_name)->toBe('Edited Demo');
    foreach ([$adviser, $coordinator] as $reader) {
        $this->actingAs($reader)->get('/academic-progress')->assertOk()->assertInertia(fn (Assert $p) => $p->has('students', 6));
    }
    $this->assertDatabaseCount('defense_requests', 0);
    $this->assertDatabaseCount('student_progress_records', 0);
});

it('allows each new local student to sign in without contacting the school portal', function ($email, $type, $fee) {
    $this->seed(DemoProgramStudentsSeeder::class);
    $this->withoutVite();
    $this->mock(LegacyPortalClient::class)->shouldNotReceive('login', 'loginCoordinator');
    $this->post('/login', ['identifier' => $email, 'password' => 'password'])->assertRedirect();
    $student = User::where('email', $email)->firstOrFail();
    $this->assertAuthenticatedAs($student);
    $this->get('/defense-requirements')->assertOk()->assertInertia(fn (Assert $p) => $p->where('defenseFees.Proposal', $fee));
    expect(app(ProgramResearchType::class)->forProgram($student->program)['label'])->toBe($type);
})->with([
    ['doctorate@uic.edu.ph', 'Dissertation', 17150],
    ['masterit@uic.edu.ph', 'Capstone', 11250],
    ['masterpharmacy@uic.edu.ph', 'Thesis', 11250],
    ['mastermba@uic.edu.ph', 'Business Plan', 11250],
    ['demo.defense@uic.edu.ph', 'Thesis', 11250],
    ['demo.defense2@uic.edu.ph', 'Thesis', 11250],
]);

it('does not overwrite an existing non-demo account', function () {
    $student = User::factory()->create(['email' => 'doctorate@uic.edu.ph', 'is_demo_account' => false]);
    $this->expectException(RuntimeException::class);
    try {
        $this->seed(DemoProgramStudentsSeeder::class);
    } finally {
        $this->assertDatabaseCount('users', 1);
        expect($student->fresh()->is_demo_account)->toBeFalse();
    }
});

it('refuses to seed easy-password program accounts in production', function () {
    app()->instance('env', 'production');
    $this->expectException(RuntimeException::class);
    (new DemoProgramStudentsSeeder)->run();
});
