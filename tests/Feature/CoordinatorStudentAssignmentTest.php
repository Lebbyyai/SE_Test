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

test('assignment always notifies both users in app and emails only when selected', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $adviserUser = User::factory()->create(['role' => 'Adviser']);
    $adviser = assignmentAdviser($coordinator, $adviserUser);
    foreach ([false, true] as $sendEmail) {
        $student = User::factory()->create(['role' => 'Student']);
        $this->actingAs($coordinator)->postJson('/api/coordinator/advisers/'.$adviser->id.'/students', ['email' => $student->email, 'send_email' => $sendEmail])->assertOk();
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'title' => 'Adviser assignment awaiting confirmation', 'link' => '/dashboard']);
        $this->assertDatabaseHas('notifications', ['user_id' => $adviserUser->id, 'title' => 'Student assignment awaiting your confirmation', 'link' => '/adviser/students-list']);
        if (! $sendEmail) {
            Mail::assertNothingSent();
        }
    }
    Mail::assertSent(\App\Mail\StudentAssignedToAdviser::class, 1);
});

test('existing pending assignments recover notices once and dashboard distinguishes pending from accepted', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $student = User::factory()->create(['role' => 'Student']);
    $other = User::factory()->create(['role' => 'Student']);
    $adviser->advisedStudents()->attach($student->id, ['status' => 'pending', 'requested_by' => $coordinator->id]);
    $this->actingAs($other)->getJson('/notifications/feed')->assertOk()->assertJsonPath('unreadCount', 0);
    $this->actingAs($student)->getJson('/notifications/feed')->assertOk()->assertJsonPath('unreadCount', 1);
    $this->getJson('/notifications/feed')->assertOk()->assertJsonPath('unreadCount', 1);
    $this->assertDatabaseCount('notifications', 2);
    $this->get('/dashboard')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('auth.user.pendingAdvisers', 1)->has('auth.user.advisers', 0));
    Mail::assertNothingSent();
});

test('adviser decision notifies the student and coordinator and cannot be replayed or made by another adviser', function (string $decision, string $status, string $title) {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $otherAdviser = User::factory()->create(['role' => 'Adviser']);
    $student = User::factory()->create(['role' => 'Student']);
    $adviser->advisedStudents()->attach($student->id, ['status' => 'pending', 'requested_by' => $coordinator->id]);
    $url = '/api/adviser/pending-students/'.$student->id.'/'.$decision;
    $this->actingAs($otherAdviser)->postJson($url)->assertNotFound();
    $this->actingAs($adviser)->postJson($url)->assertOk();
    $this->assertDatabaseHas('adviser_student', ['adviser_id' => $adviser->id, 'student_id' => $student->id, 'status' => $status]);
    foreach ([$student, $coordinator] as $recipient) {
        $this->assertDatabaseHas('notifications', ['user_id' => $recipient->id, 'title' => $title]);
    }
    $this->postJson($url)->assertNotFound();
    $this->assertDatabaseCount('notifications', 2);
    $this->actingAs($student)->get('/dashboard')->assertInertia(fn (\Inertia\Testing\AssertableInertia $page) => $page->has('auth.user.pendingAdvisers', 0)->has('auth.user.advisers', $status === 'accepted' ? 1 : 0));
})->with([
    ['accept', 'accepted', 'Adviser assignment accepted'],
    ['reject', 'rejected', 'Adviser assignment declined'],
]);
