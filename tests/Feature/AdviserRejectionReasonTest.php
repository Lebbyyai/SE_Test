<?php

use App\Models\DefenseRequest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

function rejectionReasonFixture(): array
{
    $student = User::factory()->create(['role' => 'Student']);
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $row = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
        'submitted_by' => $student->id, 'adviser_user_id' => $adviser->id, 'first_name' => 'Reason', 'last_name' => 'Student',
        'school_id' => 'REASON-TEST', 'program' => 'Master of Arts', 'thesis_title' => 'Rejection reason fixture',
        'defense_adviser' => $adviser->name, 'defense_type' => 'Proposal', 'proof_of_payment' => 'receipt.pdf',
        'workflow_state' => 'adviser-review', 'status' => 'Pending',
    ]));

    return [$row, $student, $adviser];
}

test('adviser cannot reject a request without a meaningful reason', function ($comment) {
    [$row, , $adviser] = rejectionReasonFixture();
    $this->actingAs($adviser)->patchJson('/adviser/defense-requirements/'.$row->id.'/adviser-status',
        ['adviser_status' => 'Rejected', 'comment' => $comment])->assertUnprocessable()->assertJsonValidationErrors('comment');
    expect($row->fresh()->workflow_state)->toBe('adviser-review');
    expect(Notification::count())->toBe(0);
})->with([null, '', '   ', 'No']);

test('adviser rejection reason is saved in feedback and student-visible history', function () {
    [$row, $student, $adviser] = rejectionReasonFixture();
    $reason = 'The submitted manuscript does not meet the required format.';
    $this->actingAs($adviser)->patchJson('/adviser/defense-requirements/'.$row->id.'/adviser-status',
        ['adviser_status' => 'Rejected', 'comment' => $reason])->assertOk();
    $row->refresh();
    expect($row->adviser_comments)->toBe($reason)->and($row->workflow_state)->toBe('adviser-rejected');
    $history = $row->workflow_history;
    expect($history[array_key_last($history)]['comment'])->toBe($reason);
    $this->actingAs($student)->get('/defense-requirements')->assertOk()->assertInertia(fn ($page) => $page
        ->where('defenseRequest.adviser_comments', $reason)
        ->where('defenseRequest.workflow_history.'.array_key_last($history).'.comment', $reason));
    $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'title' => 'Adviser Rejected Your Defense Request']);
});

test('unassigned adviser cannot reject a request even with a reason', function () {
    [$row] = rejectionReasonFixture();
    $this->actingAs(User::factory()->create(['role' => 'Adviser']))->patchJson('/adviser/defense-requirements/'.$row->id.'/adviser-status',
        ['adviser_status' => 'Rejected', 'comment' => 'A valid reason'])->assertForbidden();
    expect($row->fresh()->workflow_state)->toBe('adviser-review');
});
