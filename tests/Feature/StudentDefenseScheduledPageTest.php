<?php

use App\Models\DefenseRequest;
use App\Models\User;

test('student defense page renders a persisted panel assignment timestamp', function (string $state) {
    $this->withoutVite();
    $student = User::factory()->create(['role' => 'Student']);
    $defense = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
        'submitted_by' => $student->id, 'first_name' => 'Page', 'last_name' => 'Test', 'school_id' => 'PAGE-TEST',
        'program' => 'Master of Arts', 'thesis_title' => 'Scheduled page fixture', 'defense_type' => 'Proposal',
        'defense_adviser' => 'Test Adviser', 'proof_of_payment' => 'receipt.pdf',
        'status' => 'Approved', 'workflow_state' => $state, 'panels_assigned_at' => '2026-10-28 11:10:00',
        'scheduled_date' => $state === 'scheduled' ? '2026-10-30' : null,
        'scheduled_time' => $state === 'scheduled' ? '09:00:00' : null,
        'scheduled_end_time' => $state === 'scheduled' ? '12:00:00' : null,
    ]));
    $expected = $defense->fresh()->panels_assigned_at->toIso8601String();
    $this->actingAs($student)->get('/defense-requirements')->assertOk()->assertInertia(fn ($page) => $page
        ->where('defenseRequirements.0.workflow_state', $state)
        ->where('defenseRequirements.0.panels_assigned_at', $expected)
        ->where('defenseRequest.panels_assigned_at', $expected)
        ->where('defenseRequirements.0.scheduled_date', $state === 'scheduled' ? '2026-10-30' : null));
})->with(['panels-assigned', 'scheduled']);

test('panel assignment timestamp remains nullable before assignment', function () {
    $this->withoutVite();
    $student = User::factory()->create(['role' => 'Student']);
    DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
        'submitted_by' => $student->id, 'first_name' => 'Page', 'last_name' => 'Test', 'school_id' => 'PAGE-EMPTY',
        'program' => 'Master of Arts', 'thesis_title' => 'Unassigned page fixture', 'defense_type' => 'Proposal',
        'defense_adviser' => 'Test Adviser', 'proof_of_payment' => 'receipt.pdf', 'workflow_state' => 'submitted', 'status' => 'Pending',
    ]));
    $this->actingAs($student)->get('/defense-requirements')->assertOk()->assertInertia(fn ($page) => $page
        ->where('defenseRequirements.0.panels_assigned_at', null)->where('defenseRequest.panels_assigned_at', null));
});
