<?php

use App\Mail\StudentAcceptedByAdviser;
use App\Mail\StudentAssignedToAdviser;
use App\Mail\StudentRejectedByAdviser;
use App\Models\Adviser;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

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
    Mail::assertSent(StudentAssignedToAdviser::class, 1);
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
    $this->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->has('auth.user.pendingAdvisers', 1)->has('auth.user.advisers', 0));
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
    $this->actingAs($student)->get('/dashboard')->assertInertia(fn (AssertableInertia $page) => $page->has('auth.user.pendingAdvisers', 0)->has('auth.user.advisers', $status === 'accepted' ? 1 : 0));
})->with([
    ['accept', 'accepted', 'Adviser assignment accepted'],
    ['reject', 'rejected', 'Adviser assignment declined'],
]);

test('adviser email choice does not change the decision or in-app notices', function (string $decision, string $mailClass, bool $sendEmail) {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $student = User::factory()->create(['role' => 'Student']);
    $adviser->advisedStudents()->attach($student->id, ['status' => 'pending', 'requested_by' => $coordinator->id]);
    $this->actingAs($adviser)->postJson('/api/adviser/pending-students/'.$student->id.'/'.$decision, ['send_email' => $sendEmail])->assertOk();
    $this->assertDatabaseHas('adviser_student', ['adviser_id' => $adviser->id, 'student_id' => $student->id, 'status' => $decision === 'accept' ? 'accepted' : 'rejected']);
    $this->assertDatabaseCount('notifications', 2);
    if ($sendEmail) {
        Mail::assertSent($mailClass, fn ($mail) => $mail->hasTo($student->email));
        Mail::assertSentCount(1);
    } else {
        Mail::assertNothingSent();
    }
})->with([
    ['accept', StudentAcceptedByAdviser::class, false],
    ['accept', StudentAcceptedByAdviser::class, true],
    ['reject', StudentRejectedByAdviser::class, false],
    ['reject', StudentRejectedByAdviser::class, true],
]);

test('invalid email choice cannot update a pending assignment', function () {
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $student = User::factory()->create(['role' => 'Student']);
    $adviser->advisedStudents()->attach($student->id, ['status' => 'pending']);
    foreach (['accept', 'reject'] as $decision) {
        $this->actingAs($adviser)->postJson('/api/adviser/pending-students/'.$student->id.'/'.$decision, ['send_email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors('send_email');
    }
    $this->assertDatabaseHas('adviser_student', ['adviser_id' => $adviser->id, 'student_id' => $student->id, 'status' => 'pending']);
    $this->assertDatabaseCount('notifications', 0);
    Mail::assertNothingSent();
});
