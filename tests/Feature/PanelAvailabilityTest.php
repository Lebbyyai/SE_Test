<?php

use App\Models\DefenseAcademicOutcome;
use App\Models\DefenseRequest;
use App\Models\Panelist;
use App\Models\User;
use App\Services\DefenseAcademicProgress;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

function panelAvailabilityDefense(User $coordinator, array $attributes = []): DefenseRequest
{
    return DefenseRequest::withoutEvents(fn () => DefenseRequest::create(array_merge([
        'submitted_by' => User::factory()->create(['role' => 'Student'])->id,
        'coordinator_user_id' => $coordinator->id,
        'first_name' => 'Test', 'last_name' => 'Student', 'school_id' => 'AVAILABILITY-TEST',
        'program' => 'Master of Arts', 'thesis_title' => 'Private thesis title',
        'defense_type' => 'Proposal', 'defense_adviser' => 'Fixture Adviser',
        'workflow_state' => 'coordinator-approved', 'status' => 'Pending',
    ], $attributes)));
}

beforeEach(function () {
    Mail::fake();
    $this->coordinator = User::factory()->create(['role' => 'Coordinator']);
    $this->panel = Panelist::create(['name' => 'Busy Panel', 'email' => 'busy@example.test', 'status' => 'Not Assigned']);
    $this->otherPanel = Panelist::create(['name' => 'Other Panel', 'email' => 'other@example.test', 'status' => 'Not Assigned']);
    $this->current = panelAvailabilityDefense($this->coordinator);
    $this->endpoint = '/coordinator/defense-requests/'.$this->current->id.'/panel-availability';
    $this->slot = ['date' => '2099-05-10', 'time' => '08:00', 'end_time' => '10:00'];
    $this->booking = fn ($attributes = []) => panelAvailabilityDefense($this->coordinator, array_merge([
        'workflow_state' => 'scheduled', 'scheduled_date' => '2099-05-10',
        'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00',
        'defense_chairperson' => (string) $this->panel->id,
        'defense_panelist1' => (string) $this->otherPanel->id,
    ], $attributes));
});

it('reports overlapping times and daily counts without exposing student details', function () {
    ($this->booking)();
    $response = $this->actingAs($this->coordinator)->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertOk();
    $panel = collect($response->json('options'))->firstWhere('id', 'panelist-'.$this->panel->id);
    expect($panel['is_available'])->toBeFalse()->and($panel['daily_defenses'])->toBe(1)
        ->and($panel['bookings'][0]['start'])->toBe('09:00')->and($panel['bookings'][0]['overlaps'])->toBeTrue();
    expect($response->getContent())->not->toContain('Private thesis title')->not->toContain('AVAILABILITY-TEST');
});

it('allows adjacent slots and other-day bookings', function () {
    ($this->booking)();
    ($this->booking)(['scheduled_date' => '2099-05-11']);
    $this->slot['time'] = '10:00';
    $this->slot['end_time'] = '12:00';
    $response = $this->actingAs($this->coordinator)->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertOk();
    $panel = collect($response->json('options'))->firstWhere('id', 'panelist-'.$this->panel->id);
    expect($panel['is_available'])->toBeTrue()->and($panel['daily_defenses'])->toBe(1);
});

it('counts the current defense once but does not conflict with itself', function () {
    $this->current->updateQuietly(['workflow_state' => 'scheduled', 'scheduled_date' => $this->slot['date'],
        'scheduled_time' => '09:00', 'scheduled_end_time' => '11:00',
        'defense_chairperson' => (string) $this->panel->id, 'defense_panelist1' => (string) $this->otherPanel->id]);
    $response = $this->actingAs($this->coordinator)->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertOk();
    $panel = collect($response->json('options'))->firstWhere('id', 'panelist-'.$this->panel->id);
    expect($panel['is_available'])->toBeTrue()->and($panel['daily_defenses'])->toBe(1)->and($panel['bookings'])->toBe([]);
});

it('excludes cancelled and rejected bookings and includes completed daily workload', function () {
    ($this->booking)(['status' => 'Cancelled']);
    ($this->booking)(['status' => 'Rejected']);
    ($this->booking)(['workflow_state' => 'adviser-rejected']);
    ($this->booking)(['workflow_state' => 'completed', 'status' => 'Completed']);
    $response = $this->actingAs($this->coordinator)->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertOk();
    $panel = collect($response->json('options'))->firstWhere('id', 'panelist-'.$this->panel->id);
    expect($panel['daily_defenses'])->toBe(1)->and($panel['is_available'])->toBeTrue();
});

it('matches adviser and panel identities by email and counts each defense only once', function () {
    $adviser = User::factory()->create(['role' => 'Adviser', 'email' => $this->panel->email]);
    ($this->booking)(['adviser_user_id' => $adviser->id]);
    $this->current->updateQuietly(['adviser_user_id' => $adviser->id]);
    $response = $this->actingAs($this->coordinator)->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertOk();
    foreach (['adviser-'.$adviser->id, 'panelist-'.$this->panel->id] as $id) {
        $panel = collect($response->json('options'))->firstWhere('id', $id);
        expect($panel['daily_defenses'])->toBe(1)->and($panel['is_available'])->toBeFalse();
    }
    $response->assertJsonPath('adviser.is_available', false);
});

it('requires a complete valid slot and authorizes the current request', function () {
    $this->actingAs($this->coordinator)->getJson($this->endpoint.'?date=2099-05-10&time=09:00')->assertUnprocessable();
    $this->actingAs($this->coordinator)->getJson($this->endpoint.'?date=2099-05-10&time=10:00&end_time=09:00')->assertUnprocessable();
    $this->actingAs(User::factory()->create(['role' => 'Coordinator']))->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertForbidden();
    $this->actingAs(User::factory()->create(['role' => 'Student']))->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertForbidden();
});

it('rechecks unsaved draft slots before assigning panels and rolls back conflicts', function () {
    ($this->booking)();
    $data = ['defense_chairperson' => 'panelist-'.$this->panel->id, 'defense_panelist1' => 'panelist-'.$this->otherPanel->id,
        'availability_date' => $this->slot['date'], 'availability_time' => $this->slot['time'], 'availability_end_time' => $this->slot['end_time']];
    $this->actingAs($this->coordinator)->postJson('/coordinator/defense-requests/'.$this->current->id.'/panels', $data)
        ->assertUnprocessable()->assertJsonValidationErrors('scheduled_time');
    expect($this->current->fresh()->defense_chairperson)->toBeNull();
});

it('can save a new schedule and selected panels together with no artificial daily limit', function () {
    for ($hour = 8; $hour < 13; $hour++) {
        ($this->booking)(['scheduled_time' => sprintf('%02d:00', $hour), 'scheduled_end_time' => sprintf('%02d:00', $hour + 1)]);
    }
    $this->slot['time'] = '13:00';
    $this->slot['end_time'] = '15:00';
    $response = $this->actingAs($this->coordinator)->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertOk();
    $panel = collect($response->json('options'))->firstWhere('id', 'panelist-'.$this->panel->id);
    expect($panel['daily_defenses'])->toBe(5)->and($panel['is_available'])->toBeTrue();
    $this->actingAs($this->coordinator)->postJson('/coordinator/defense-requests/'.$this->current->id.'/schedule-json', [
        'scheduled_date' => $this->slot['date'], 'scheduled_time' => '13:00', 'scheduled_end_time' => '15:00',
        'defense_mode' => 'online', 'defense_venue' => 'Fixture meeting',
        'defense_chairperson' => 'panelist-'.$this->panel->id, 'defense_panelist1' => 'panelist-'.$this->otherPanel->id,
    ])->assertOk();
    expect($this->current->fresh()->workflow_state)->toBe('scheduled');
});

it('rejects an overlapping combined schedule even if availability was previously checked', function () {
    ($this->booking)();
    $this->actingAs($this->coordinator)->postJson('/coordinator/defense-requests/'.$this->current->id.'/schedule-json', [
        'scheduled_date' => $this->slot['date'], 'scheduled_time' => $this->slot['time'], 'scheduled_end_time' => $this->slot['end_time'],
        'defense_mode' => 'online', 'defense_venue' => 'Fixture meeting',
        'defense_chairperson' => 'panelist-'.$this->panel->id, 'defense_panelist1' => 'panelist-'.$this->otherPanel->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_time');
    expect($this->current->fresh()->workflow_state)->toBe('coordinator-approved');
});

it('accepts each mandatory two-hour defense slot', function (string $start, string $end) {
    $this->actingAs($this->coordinator)->postJson('/coordinator/defense-requests/'.$this->current->id.'/schedule-json', [
        'scheduled_date' => $this->slot['date'], 'scheduled_time' => $start, 'scheduled_end_time' => $end,
        'defense_mode' => 'online', 'defense_venue' => 'Fixture meeting',
        'defense_chairperson' => (string) $this->panel->id, 'defense_panelist1' => (string) $this->otherPanel->id,
    ])->assertOk();
    expect(substr($this->current->fresh()->scheduled_time, 0, 5))->toBe($start)
        ->and(substr($this->current->fresh()->scheduled_end_time, 0, 5))->toBe($end);
})->with([['08:00', '10:00'], ['10:00', '12:00'], ['13:00', '15:00'], ['15:00', '17:00']]);

it('rejects custom times through scheduling and draft panel assignment', function (string $start, string $end) {
    $before = $this->current->fresh()->getAttributes();
    $panels = ['defense_chairperson' => (string) $this->panel->id, 'defense_panelist1' => (string) $this->otherPanel->id];
    $this->actingAs($this->coordinator)->postJson('/coordinator/defense-requests/'.$this->current->id.'/schedule-json', $panels + [
        'scheduled_date' => $this->slot['date'], 'scheduled_time' => $start, 'scheduled_end_time' => $end,
        'defense_mode' => 'online', 'defense_venue' => 'Fixture meeting',
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_time');
    $this->actingAs($this->coordinator)->postJson('/coordinator/defense-requests/'.$this->current->id.'/panels', $panels + [
        'availability_date' => $this->slot['date'], 'availability_time' => $start, 'availability_end_time' => $end,
    ])->assertUnprocessable()->assertJsonValidationErrors('scheduled_time');
    expect($this->current->fresh()->getAttributes())->toBe($before);
})->with([['09:00', '11:00'], ['08:00', '12:00'], ['12:00', '14:00'], ['17:00', '19:00'], ['08:00', '10:15']]);

it('carries the passed proposal panel into later stages and blocks conflicting slots or panel changes', function (string $stage) {
    $panel = [
        'defense_chairperson' => (string) $this->panel->id,
        'defense_panelist1' => (string) $this->otherPanel->id,
        'defense_panelist2' => null,
        'defense_panelist3' => null,
        'defense_panelist4' => null,
    ];
    $proposal = panelAvailabilityDefense($this->coordinator, $panel + [
        'workflow_state' => 'completed', 'status' => 'Completed',
        'scheduled_date' => '2026-01-10', 'scheduled_time' => '08:00', 'scheduled_end_time' => '10:00',
    ])->fresh();
    DefenseAcademicOutcome::create([
        'defense_request_id' => $proposal->id, 'completion' => 'done', 'result' => 'passed',
        'completed_on' => '2026-01-10', 'recorded_by' => $this->coordinator->id, 'version' => 1,
        'snapshot_hash' => app(DefenseAcademicProgress::class)->snapshot($proposal),
    ]);
    $later = panelAvailabilityDefense($this->coordinator, [
        'submitted_by' => $proposal->submitted_by, 'defense_type' => $stage,
        'defense_panelist4' => 'Legacy extra member',
    ]);
    ($this->booking)();

    $route = '/coordinator/defense-requests/'.$later->id;
    $this->withoutVite()->actingAs($this->coordinator)->get($route.'/details')->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('proposalPanel.defense_chairperson', $this->panel->name)
            ->where('proposalPanel.defense_panelist1', $this->otherPanel->name));
    $this->getJson($route.'/panel-availability?'.http_build_query($this->slot))->assertOk()
        ->assertJsonPath('proposal_panel_available', false);

    $schedule = [
        'scheduled_date' => $this->slot['date'], 'scheduled_time' => '08:00', 'scheduled_end_time' => '10:00',
        'defense_mode' => 'online', 'defense_venue' => 'Fixture meeting',
    ];
    $override = ['defense_chairperson' => 'panelist-'.$this->otherPanel->id, 'defense_panelist1' => 'panelist-'.$this->panel->id];
    $this->postJson($route.'/panels', $override)->assertUnprocessable()->assertJsonValidationErrors('panelists');
    $this->postJson($route.'/schedule-json', $schedule + $override)->assertUnprocessable()->assertJsonValidationErrors('panelists');
    $this->postJson($route.'/schedule-json', $schedule)->assertUnprocessable()->assertJsonValidationErrors('scheduled_time');
    expect($later->fresh()->defense_chairperson)->toBeNull();

    $schedule['scheduled_time'] = '10:00';
    $schedule['scheduled_end_time'] = '12:00';
    $this->postJson($route.'/schedule-json', $schedule)->assertOk();
    $saved = $later->fresh();
    expect($saved->only(array_keys($panel)))->toBe($panel)
        ->and($saved->panels_assigned_at)->not->toBeNull()
        ->and($saved->workflow_state)->toBe('scheduled');
})->with(['Prefinal', 'Final']);

it('blocks later-stage scheduling without a passed proposal for that student and program', function () {
    $this->current->updateQuietly(['defense_type' => 'Prefinal']);
    $this->actingAs($this->coordinator)->postJson('/coordinator/defense-requests/'.$this->current->id.'/schedule-json', [
        'scheduled_date' => $this->slot['date'], 'scheduled_time' => '08:00', 'scheduled_end_time' => '10:00',
        'defense_mode' => 'online', 'defense_venue' => 'Fixture meeting',
    ])->assertUnprocessable()->assertJsonValidationErrors('panelists');
    expect($this->current->fresh()->scheduled_date)->toBeNull();
});
