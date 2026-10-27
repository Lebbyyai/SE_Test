<?php

use App\Models\DefenseRequest;
use App\Models\Event;
use App\Models\User;

function dashboardScheduledDefense(User $student): DefenseRequest
{
    return DefenseRequest::withoutEvents(fn () => DefenseRequest::create(['submitted_by' => $student->id, 'first_name' => 'Test', 'last_name' => 'Student', 'school_id' => 'S-'.$student->id, 'program' => 'Master of Arts', 'thesis_title' => 'Student '.$student->id.' defense', 'defense_type' => 'Proposal', 'defense_adviser' => 'Test Adviser', 'proof_of_payment' => 'receipt.pdf', 'workflow_state' => 'scheduled', 'status' => 'Approved', 'scheduled_date' => '2026-10-27', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00']));
}

test('dashboard calendar feed returns own defenses and school events but excludes another student defense', function () {
    $student = User::factory()->create(['role' => 'Student']);
    $other = User::factory()->create(['role' => 'Student']);
    $mine = dashboardScheduledDefense($student);
    $theirs = dashboardScheduledDefense($other);
    Event::create(['title' => 'School activity', 'start_at' => '2026-10-27 13:00:00', 'end_at' => '2026-10-27 14:00:00', 'all_day' => false, 'type' => 'general', 'created_by' => $student->id]);
    $response = $this->actingAs($student)->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk();
    $rows = collect($response->json());
    expect($rows->pluck('title')->all())->toContain('School activity')->toContain('Defense: '.$mine->thesis_title)->not->toContain('Defense: '.$theirs->thesis_title);
    expect($rows->where('origin', 'defense')->count())->toBe(1);
});

test('adviser dashboard calendar includes assigned defenses and school events without other adviser defenses', function () {
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $other = User::factory()->create(['role' => 'Adviser']);
    $mine = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $mine->update(['adviser_user_id' => $adviser->id]);
    $theirs = dashboardScheduledDefense(User::factory()->create(['role' => 'Student']));
    $theirs->update(['adviser_user_id' => $other->id]);
    Event::create(['title' => 'Graduate school event', 'start_at' => '2026-10-27 13:00:00', 'end_at' => '2026-10-27 14:00:00', 'all_day' => false, 'type' => 'general', 'created_by' => $adviser->id]);
    $response = $this->actingAs($adviser)->getJson('/api/calendar/events?from=2026-10-01&to=2026-10-31')->assertOk();
    $rows = collect($response->json());
    expect($rows->pluck('title')->all())->toContain('Graduate school event')->toContain('Defense: '.$mine->thesis_title)->not->toContain('Defense: '.$theirs->thesis_title);
    expect($rows->where('origin', 'defense')->count())->toBe(1);
});
