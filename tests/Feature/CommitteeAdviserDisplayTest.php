<?php

use App\Models\DefenseRequest;
use App\Models\User;

function committeeFixture(): array
{
    $student = User::factory()->create(['role' => 'Student']);
    $adviser = User::factory()->create(['role' => 'Adviser', 'first_name' => 'Correct', 'middle_name' => null, 'last_name' => 'Adviser']);
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $row = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
        'submitted_by' => $student->id, 'adviser_user_id' => $adviser->id, 'coordinator_user_id' => $coordinator->id,
        'first_name' => 'Committee', 'last_name' => 'Student', 'school_id' => 'COMMITTEE-TEST',
        'program' => 'Master of Arts', 'thesis_title' => 'Committee test', 'defense_type' => 'Proposal',
        'defense_adviser' => 'New User', 'workflow_state' => 'adviser-approved', 'adviser_status' => 'Approved', 'status' => 'Pending',
    ]));

    return [$row, $adviser, $coordinator];
}

test('committee details use linked adviser identity rather than an outdated name snapshot', function () {
    $this->withoutVite();
    [$row, $adviser, $coordinator] = committeeFixture();
    $this->actingAs($coordinator)->get('/coordinator/defense-requests/'.$row->id.'/details')->assertOk()
        ->assertInertia(fn ($page) => $page->where('defenseRequest.defense_adviser', 'Correct Adviser')
            ->where('defenseRequest.adviser_profile.id', $adviser->id)
            ->where('defenseRequest.adviser_profile.email', $adviser->email)
            ->where('defenseRequest.adviser_status', 'Approved'));
    expect($row->fresh()->getRawOriginal('defense_adviser'))->toBe('New User');
    $adviser->update(['first_name' => 'Updated']);
    expect($row->fresh()->defense_adviser)->toBe('Updated Adviser');
});

test('unlinked historical adviser name is retained', function () {
    [$row] = committeeFixture();
    $row->update(['adviser_user_id' => null]);
    expect($row->fresh()->defense_adviser)->toBe('New User');
});

test('unrelated coordinator cannot open committee details', function () {
    $this->withoutVite();
    [$row] = committeeFixture();
    $this->actingAs(User::factory()->create(['role' => 'Coordinator']))
        ->get('/coordinator/defense-requests/'.$row->id.'/details')->assertForbidden();
});
