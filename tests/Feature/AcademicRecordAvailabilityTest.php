<?php

use App\Models\User;
use App\Services\LegacyPortalClient;
use Illuminate\Support\Facades\Cache;

it('shows demo records as unavailable without contacting the school portal', function () {
    $user = User::factory()->create(['role' => 'Student', 'is_demo_account' => true]);
    $this->mock(LegacyPortalClient::class)->shouldNotReceive('fetchAcademicRecordsHtml');
    $this->actingAs($user)->getJson('/legacy/academic-records')
        ->assertOk()->assertJsonPath('available', false)
        ->assertJsonMissingPath('data.records');
});

it('explains a missing school portal session for a real account', function () {
    $user = User::factory()->create(['role' => 'Student', 'is_demo_account' => false]);
    Cache::forget('legacy_session_'.$user->id);
    $this->mock(LegacyPortalClient::class)->shouldNotReceive('fetchAcademicRecordsHtml');
    $this->actingAs($user)->getJson('/legacy/academic-records')
        ->assertStatus(440)->assertJsonPath('error', 'The school-portal session is unavailable. Sign in again using your school-portal credentials to load academic records.');
});
