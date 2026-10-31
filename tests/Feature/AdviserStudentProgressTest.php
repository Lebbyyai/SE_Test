<?php

use App\Models\DefenseRequest;
use App\Models\DefenseServiceRecord;
use App\Models\Panelist;
use App\Models\StudentProgressRecord;
use App\Models\User;
use App\Services\DefenseServiceConfirmation;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

function progressDefense(User $student, User $adviser, array $attributes = []): DefenseRequest
{
    return DefenseRequest::withoutEvents(fn () => DefenseRequest::create(array_merge([
        'submitted_by' => $student->id, 'adviser_user_id' => $adviser->id,
        'first_name' => 'Test', 'last_name' => 'Student', 'school_id' => $student->school_id,
        'program' => 'Master of Arts', 'thesis_title' => 'Progress test', 'defense_type' => 'Proposal',
        'workflow_state' => 'submitted', 'status' => 'Pending', 'defense_adviser' => 'Adviser',
    ], $attributes)));
}

beforeEach(function () {
    $this->withoutVite();
    $this->adviser = User::factory()->create(['role' => 'Adviser']);
    $this->student = User::factory()->create(['role' => 'Student', 'school_id' => '2023000123', 'program' => 'Master of Arts']);
    $this->adviser->advisedStudents()->attach($this->student->id, ['status' => 'accepted']);
    $this->url = '/adviser/students/'.$this->student->id.'/progress';
    $this->payload = [
        'version' => 0, 'enrollment_year' => 2023, 'completion_year' => null, 'semester' => '1st semester 2026-2027',
        'subjects' => [['code' => 'RES101', 'title' => 'Research Methods']],
        'record_reference' => 'Checked enrollment record, school year 2026-2027.', 'verified_against_school_records' => true,
    ];
});

it('shows empty progress honestly and does not infer years from the school id or account creation', function () {
    $this->actingAs($this->adviser)->get($this->url)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('adviser/students-list/progress')->where('progress.record', null)
        ->where('progress.stage', null)->where('progress.status', 'No defense request')
        ->has('progress.defenses', 0)->has('progress.portal.subjects', 0)->missing('progress.student.password'));
});

it('restricts viewing and updating to the accepted assigned adviser', function () {
    foreach (['Student', 'Finance', 'Coordinator', 'Dean', 'Adviser'] as $role) {
        $other = User::factory()->create(['role' => $role]);
        $this->actingAs($other)->get($this->url)->assertForbidden();
        $this->put($this->url, $this->payload)->assertForbidden();
    }
    $this->assertDatabaseCount('student_progress_records', 0);
});

it('denies pending rejected and revoked assignments', function () {
    foreach (['pending', 'rejected'] as $status) {
        $this->adviser->advisedStudents()->updateExistingPivot($this->student->id, ['status' => $status]);
        $this->actingAs($this->adviser)->get($this->url)->assertForbidden();
        $this->put($this->url, $this->payload)->assertForbidden();
    }
    $this->adviser->advisedStudents()->detach($this->student->id);
    $this->get($this->url)->assertForbidden();
});

it('records academic details with provenance without changing roles or defense status', function () {
    $defense = progressDefense($this->student, $this->adviser);
    $this->actingAs($this->adviser)->put($this->url, $this->payload + ['student_id' => $this->adviser->id, 'role' => 'Dean'])->assertRedirect();
    $record = StudentProgressRecord::where('student_id', $this->student->id)->firstOrFail();
    expect($record->enrollment_year)->toBe(2023)->and($record->completion_year)->toBeNull()
        ->and($record->recorded_by)->toBe($this->adviser->id)->and($record->version)->toBe(1)
        ->and($record->subjects[0]['title'])->toBe('Research Methods');
    expect($this->student->fresh()->role)->toBe('Student')->and($defense->fresh()->workflow_state)->toBe('submitted');
    $this->getJson('/api/adviser/students')->assertOk()->assertJsonPath('0.progress.enrollment_year', 2023)->assertJsonPath('0.progress.stage', 'Proposal');
});

it('requires verification a record reference and valid academic years', function () {
    $invalid = $this->payload;
    $invalid['verified_against_school_records'] = false;
    $invalid['record_reference'] = '';
    $invalid['enrollment_year'] = now('Asia/Manila')->year + 1;
    $this->actingAs($this->adviser)->putJson($this->url, $invalid)->assertUnprocessable()
        ->assertJsonValidationErrors(['verified_against_school_records', 'record_reference', 'enrollment_year']);
    $this->putJson($this->url, array_replace($this->payload, ['completion_year' => 2022]))->assertUnprocessable()->assertJsonValidationErrors('completion_year');
    $this->putJson($this->url, array_replace($this->payload, ['semester' => null]))->assertUnprocessable()->assertJsonValidationErrors('semester');
    $this->assertDatabaseCount('student_progress_records', 0);
});

it('allows missing academic values without fabricating completion', function () {
    $this->actingAs($this->adviser)->put($this->url, [
        'version' => 0, 'subjects' => [], 'record_reference' => 'School records requested; no year or subjects confirmed.', 'verified_against_school_records' => true,
    ])->assertRedirect();
    $record = StudentProgressRecord::firstOrFail();
    expect($record->enrollment_year)->toBeNull()->and($record->completion_year)->toBeNull()->and($record->subjects)->toBe([]);
});

it('preserves prior academic values and rejects stale edits', function () {
    $this->actingAs($this->adviser)->put($this->url, $this->payload)->assertRedirect();
    $updated = array_replace($this->payload, ['version' => 1, 'completion_year' => 2026, 'record_reference' => 'Checked official program completion record.']);
    $this->put($this->url, $updated)->assertRedirect();
    $record = StudentProgressRecord::firstOrFail();
    expect($record->version)->toBe(2)->and($record->completion_year)->toBe(2026)
        ->and($record->history[0]['completion_year'])->toBeNull()->and($record->history[0]['subjects'][0]['code'])->toBe('RES101');
    $this->putJson($this->url, $updated)->assertUnprocessable()->assertJsonValidationErrors('version');
    expect($record->fresh()->version)->toBe(2);
});

it('does not mark an automatically completed final defense as a graduated program', function () {
    progressDefense($this->student, $this->adviser, ['defense_type' => 'Final', 'workflow_state' => 'completed', 'status' => 'Approved']);
    $this->actingAs($this->adviser)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('progress.stage', 'Final')->where('progress.status', 'Completion not confirmed')->where('progress.record', null));
    $this->assertDatabaseCount('student_progress_records', 0);
});

it('uses matching service evidence for a held defense but never infers program completion', function () {
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $chair = Panelist::create(['name' => 'Chair', 'email' => 'chair-progress@example.test']);
    $panel = Panelist::create(['name' => 'Panel', 'email' => 'panel-progress@example.test']);
    $defense = progressDefense($this->student, $this->adviser, ['defense_type' => 'Final', 'workflow_state' => 'completed',
        'status' => 'Completed', 'defense_chairperson' => (string) $chair->id, 'defense_panelist1' => (string) $panel->id,
        'scheduled_date' => '2026-10-30', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00']);
    DefenseServiceRecord::create(['defense_request_id' => $defense->id, 'snapshot_hash' => app(DefenseServiceConfirmation::class)->snapshot($defense),
        'participants' => [], 'completed_on' => '2026-10-30', 'confirmed_by' => $coordinator->id, 'confirmed_at' => now(), 'history' => []]);
    $this->actingAs($this->adviser)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('progress.status', 'Defense held (confirmed)')->where('progress.defenses.0.held_on', '2026-10-30')->where('progress.record', null));
    $defense->updateQuietly(['defense_venue' => 'Changed venue']);
    $this->get($this->url)->assertInertia(fn (Assert $page) => $page->where('progress.status', 'Completion not confirmed')->where('progress.defenses.0.held_on', null));
});

it('selects the latest viable stage and keeps rejected attempts in history', function () {
    progressDefense($this->student, $this->adviser, ['defense_type' => 'Proposal', 'created_at' => now()->subDays(3)]);
    progressDefense($this->student, $this->adviser, ['defense_type' => 'Pre-final', 'workflow_state' => 'scheduled', 'created_at' => now()->subDays(2)]);
    progressDefense($this->student, $this->adviser, ['defense_type' => 'Final', 'workflow_state' => 'adviser-rejected', 'created_at' => now()->subDay()]);
    $this->actingAs($this->adviser)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->where('progress.stage', 'Prefinal')->has('progress.defenses', 3)->where('progress.defenses.0.status', 'Rejected by adviser'));
});

it('limits cached subjects to the selected school semester and omits grades and balances', function () {
    Cache::put('scraped_data_user_'.$this->student->id, [
        'success' => true, 'current_semester_id' => '2026-1', 'timestamp' => '2026-10-30T01:00:00Z',
        'all_semester_grades' => [
            'old' => ['semester_label' => 'Old term', 'grades' => [['code' => 'OLD', 'title' => 'Old subject']]],
            '2026-1' => ['semester_label' => '1st semester 2026-2027', 'grades' => [['code' => 'RES101', 'title' => 'Research Methods', 'section' => 'A', 'average' => 'PRIVATE-GRADE', 'current_balance' => 'PRIVATE-BALANCE']]],
        ],
    ], 60);
    $this->actingAs($this->adviser)->get($this->url)->assertInertia(fn (Assert $page) => $page
        ->has('progress.portal.subjects', 1)->where('progress.portal.subjects.0.title', 'Research Methods')
        ->where('progress.portal.semester', '1st semester 2026-2027')->where('progress.record', null)
        ->missing('progress.portal.subjects.0.average')->missing('progress.portal.subjects.0.current_balance'));
});

it('requires login for student progress', function () {
    $this->get($this->url)->assertRedirect('/login');
});
