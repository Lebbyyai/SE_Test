<?php

use App\Models\Adviser;
use App\Models\CoordinatorProgramAssignment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('dean receives staff program assignments and accepted student counts without unrelated users', function () {
    $this->withoutVite();
    $dean = User::factory()->create(['role' => 'Dean']);
    $coordinator = User::factory()->create(['role' => 'Coordinator', 'last_name' => 'A']);
    $assistant = User::factory()->create(['role' => 'Administrative Assistant', 'last_name' => 'B']);
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $accepted = User::factory()->create(['role' => 'Student']);
    $pending = User::factory()->create(['role' => 'Student']);
    Adviser::create(['coordinator_id' => $coordinator->id, 'user_id' => $adviser->id, 'first_name' => 'Test', 'last_name' => 'Adviser', 'email' => $adviser->email, 'status' => 'active']);
    $adviser->advisedStudents()->attach($accepted->id, ['status' => 'accepted', 'requested_by' => $coordinator->id]);
    $adviser->advisedStudents()->attach($pending->id, ['status' => 'pending', 'requested_by' => $coordinator->id]);
    CoordinatorProgramAssignment::create(['coordinator_user_id' => $coordinator->id, 'program_name' => 'Test Master Program', 'is_active' => true, 'assigned_by' => $dean->id]);
    CoordinatorProgramAssignment::create(['coordinator_user_id' => $coordinator->id, 'program_name' => 'Inactive Program', 'is_active' => false, 'assigned_by' => $dean->id]);
    $this->actingAs($dean)->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->has('graduateStaff', 2)->where('graduateStaff.0.id', $coordinator->id)
        ->where('graduateStaff.0.students', 1)->where('graduateStaff.0.advisers', 1)
        ->where('graduateStaff.0.programs', ['Test Master Program'])
        ->where('graduateStaff.1.id', $assistant->id)->where('graduateStaff.1.students', null));
    $this->actingAs($accepted)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->missing('graduateStaff'));
});
