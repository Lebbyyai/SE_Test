<?php

use App\Models\DefenseRequest;
use App\Models\Panelist;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

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
    $this->slot = ['date' => '2099-05-10', 'time' => '09:30', 'end_time' => '10:30'];
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
    $this->slot['time'] = '14:00';
    $this->slot['end_time'] = '15:00';
    $response = $this->actingAs($this->coordinator)->getJson($this->endpoint.'?'.http_build_query($this->slot))->assertOk();
    $panel = collect($response->json('options'))->firstWhere('id', 'panelist-'.$this->panel->id);
    expect($panel['daily_defenses'])->toBe(5)->and($panel['is_available'])->toBeTrue();
    $this->actingAs($this->coordinator)->postJson('/coordinator/defense-requests/'.$this->current->id.'/schedule-json', [
        'scheduled_date' => $this->slot['date'], 'scheduled_time' => '14:00', 'scheduled_end_time' => '15:00',
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
