<?php

use App\Models\Adviser;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

function assignmentAdviser(User $coordinator, ?User $user): Adviser
{
    return Adviser::create(['coordinator_id' => $coordinator->id, 'user_id' => $user?->id, 'first_name' => 'Test', 'last_name' => 'Adviser', 'email' => $user?->email ?? 'unregistered@example.test', 'status' => $user ? 'active' : 'inactive']);
}

test('coordinator assigns an unassigned student and adviser receives a pending request', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $adviserUser = User::factory()->create(['role' => 'Adviser']);
    $student = User::factory()->create(['role' => 'Student']);
    $adviser = assignmentAdviser($coordinator, $adviserUser);
    $this->actingAs($coordinator)->postJson('/api/coordinator/advisers/'.$adviser->id.'/students', ['email' => $student->email])->assertOk()->assertJsonPath('0.id', $student->id);
    $this->assertDatabaseHas('adviser_student', ['adviser_id' => $adviserUser->id, 'student_id' => $student->id, 'status' => 'pending', 'requested_by' => $coordinator->id]);
    $this->postJson('/api/coordinator/advisers/'.$adviser->id.'/students', ['email' => $student->email])->assertConflict()->assertJsonPath('error', 'This student is already assigned to another adviser.');
});

test('assignment rejects invalid missing and unregistered students with useful responses', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $adviser = assignmentAdviser($coordinator, User::factory()->create(['role' => 'Adviser']));
    $this->actingAs($coordinator)->postJson('/api/coordinator/advisers/'.$adviser->id.'/students', ['email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('email');
    $this->postJson('/api/coordinator/advisers/'.$adviser->id.'/students', ['email' => 'missing@example.test'])->assertNotFound()->assertJsonPath('error', 'Student not found.');
    $this->assertDatabaseCount('adviser_student', 0);
});

test('assignment requires an active linked adviser owned by the coordinator', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $other = User::factory()->create(['role' => 'Coordinator']);
    $student = User::factory()->create(['role' => 'Student']);
    $inactive = assignmentAdviser($coordinator, null);
    $this->actingAs($coordinator)->postJson('/api/coordinator/advisers/'.$inactive->id.'/students', ['email' => $student->email])->assertBadRequest();
    $foreign = assignmentAdviser($other, User::factory()->create(['role' => 'Adviser']));
    $this->postJson('/api/coordinator/advisers/'.$foreign->id.'/students', ['email' => $student->email])->assertNotFound();
    $this->assertDatabaseCount('adviser_student', 0);
});
