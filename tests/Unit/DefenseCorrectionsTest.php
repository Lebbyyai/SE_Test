<?php

use App\Models\DefenseRequest;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Schema::create('users', function (Blueprint $table) {
        $table->id();
        foreach (['first_name', 'last_name', 'email', 'role', 'program'] as $field) {
            $table->string($field)->nullable();
        }
        $table->timestamp('email_verified_at')->nullable();
        $table->timestamps();
    });
    Schema::create('defense_requests', function (Blueprint $table) {
        $table->id();
        foreach (['submitted_by', 'adviser_user_id', 'coordinator_user_id', 'assigned_to_user_id', 'last_status_updated_by', 'adviser_reviewed_by', 'coordinator_reviewed_by'] as $field) {
            $table->unsignedBigInteger($field)->nullable();
        }
        foreach (['thesis_title', 'program', 'workflow_state', 'status', 'adviser_status', 'coordinator_status', 'defense_type', 'scheduled_time', 'scheduled_end_time', 'defense_venue', 'manuscript_proposal', 'similarity_index', 'rec_endorsement', 'proof_of_payment', 'avisee_adviser_attachment'] as $field) {
            $table->string($field)->nullable();
        }
        foreach (['submitted_at', 'last_status_updated_at', 'adviser_reviewed_at', 'coordinator_reviewed_at'] as $field) {
            $table->timestamp($field)->nullable();
        }
        $table->text('adviser_comments')->nullable();
        $table->text('coordinator_comments')->nullable();
        $table->date('scheduled_date')->nullable();
        $table->json('workflow_history')->nullable();
        $table->timestamps();
    });
    (require database_path('migrations/2026_10_23_000004_add_defense_corrections.php'))->up();
    (require glob(database_path('migrations/*create_notifications_table.php'))[0])->up();
    (require database_path('migrations/2026_10_23_000003_add_notification_event_tracking.php'))->up();
    foreach (['Student', 'Adviser', 'Coordinator', 'Student', 'Adviser', 'Coordinator', 'Dean'] as $index => $role) {
        DB::table('users')->insert([
            'id' => $index + 1, 'first_name' => 'Test', 'last_name' => $role,
            'role' => $role, 'program' => 'Test',
            'email' => 'user'.($index + 1).'@example.test', 'email_verified_at' => now(),
        ]);
    }
    Storage::fake('public');
    Mail::fake();
});

afterEach(function () {
    foreach (['notifications', 'defense_requests', 'users'] as $table) {
        Schema::dropIfExists($table);
    }
});

function correctionDefense(array $attributes = []): DefenseRequest
{
    return DefenseRequest::create(array_merge([
        'submitted_by' => 1, 'adviser_user_id' => 2, 'coordinator_user_id' => 3,
        'program' => 'Test', 'thesis_title' => 'Original research title',
        'workflow_state' => 'submitted', 'status' => 'Pending',
        'workflow_history' => [[
            'action' => 'submitted', 'timestamp' => now()->toIso8601String(),
            'user_id' => 1, 'comment' => 'Original submission', 'to_state' => 'submitted',
        ]],
    ], $attributes));
}

it('allows the assigned reviewer to return the request with feedback and a student notification', function ($stage, $reviewer, $state) {
    $defense = correctionDefense(['workflow_state' => $state]);
    $this->actingAs(User::find($reviewer))->postJson("/defense-requests/{$defense->id}/request-corrections", [
        'stage' => $stage, 'notes' => 'Please update the methodology and replace the manuscript.',
    ])->assertSuccessful();

    $defense->refresh();
    expect($defense->workflow_state)->toBe($stage.'-corrections')
        ->and($defense->status)->toBe('Pending')
        ->and($defense->correction_stage)->toBe($stage)
        ->and((int) $defense->correction_requested_by)->toBe($reviewer)
        ->and($defense->correction_requested_at)->not->toBeNull()
        ->and((int) $defense->correction_count)->toBe(1)
        ->and($defense->correction_notes)->toContain('methodology')
        ->and($defense->workflow_history[0]['comment'])->toBe('Original submission')
        ->and(collect($defense->workflow_history)->last()['action'])->toBe('corrections-requested')
        ->and(Notification::where('user_id', 1)->where('defense_request_id', $defense->id)->count())->toBe(1);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();
})->with([
    'adviser initial submission' => ['adviser', 2, 'submitted'],
    'adviser review' => ['adviser', 2, 'adviser-review'],
    'coordinator endorsed request' => ['coordinator', 3, 'adviser-approved'],
    'coordinator review' => ['coordinator', 3, 'coordinator-review'],
]);

it('requires reviewer notes and preserves the request on validation failure', function ($notes) {
    $defense = correctionDefense();
    $before = $defense->fresh()->getAttributes();
    $this->actingAs(User::find(2))->postJson("/defense-requests/{$defense->id}/request-corrections", [
        'stage' => 'adviser', 'notes' => $notes,
    ])->assertUnprocessable()->assertJsonValidationErrors('notes');
    expect($defense->fresh()->getAttributes())->toBe($before);
})->with(['empty notes' => [''], 'oversized notes' => [str_repeat('a', 5001)]]);

it('rejects correction requests from other users and roles', function ($user, $stage, $state) {
    $defense = correctionDefense(['workflow_state' => $state]);
    $this->actingAs(User::find($user))->postJson("/defense-requests/{$defense->id}/request-corrections", [
        'stage' => $stage, 'notes' => 'Change the manuscript.',
    ])->assertForbidden();
    expect($defense->fresh()->workflow_state)->toBe($state);
})->with([
    'unassigned adviser' => [5, 'adviser', 'submitted'],
    'unassigned coordinator' => [6, 'coordinator', 'adviser-approved'],
    'student' => [1, 'adviser', 'submitted'],
    'dean' => [7, 'coordinator', 'adviser-approved'],
    'adviser cannot act as coordinator' => [2, 'coordinator', 'adviser-approved'],
]);

it('rejects corrections outside the reviewers active stage', function ($stage, $user, $state) {
    $defense = correctionDefense(['workflow_state' => $state]);
    $this->actingAs(User::find($user))->postJson("/defense-requests/{$defense->id}/request-corrections", [
        'stage' => $stage, 'notes' => 'Change the manuscript.',
    ])->assertUnprocessable();
    expect($defense->fresh()->workflow_state)->toBe($state);
})->with([
    ['adviser', 2, 'scheduled'], ['coordinator', 3, 'submitted'], ['adviser', 2, 'adviser-corrections'],
]);

it('resubmits the same request to the reviewer with history and preserved documents', function ($stage, $reviewer, $nextState) {
    Storage::disk('public')->put('defense-requirements/original.pdf', 'Original manuscript');
    Storage::disk('public')->put('defense-requirements/payment.pdf', 'Original payment');
    $defense = correctionDefense([
        'workflow_state' => $stage.'-corrections', 'correction_stage' => $stage,
        'correction_notes' => 'Revise the manuscript.', 'correction_requested_by' => $reviewer,
        'correction_count' => 1, 'manuscript_proposal' => 'defense-requirements/original.pdf',
        'proof_of_payment' => 'defense-requirements/payment.pdf',
        'adviser_status' => $stage === 'coordinator' ? 'Approved' : 'Pending',
    ]);
    $this->actingAs(User::find(1))->postJson("/defense-requirements/{$defense->id}/resubmit", [
        'student_response' => 'Updated the methodology as requested.',
        'thesis_title' => 'Revised research title',
        'manuscript_proposal' => UploadedFile::fake()->create('revision.pdf', 12, 'application/pdf'),
        'submitted_by' => 4, 'coordinator_user_id' => 6, 'workflow_state' => 'coordinator-approved',
    ])->assertSuccessful();

    $defense->refresh();
    $history = collect($defense->workflow_history)->last();
    expect(DefenseRequest::count())->toBe(1)
        ->and($defense->workflow_state)->toBe($nextState)
        ->and($defense->status)->toBe('Pending')
        ->and($defense->thesis_title)->toBe('Revised research title')
        ->and((int) $defense->submitted_by)->toBe(1)
        ->and((int) $defense->coordinator_user_id)->toBe(3)
        ->and($defense->proof_of_payment)->toBe('defense-requirements/payment.pdf')
        ->and($defense->manuscript_proposal)->not->toBe('defense-requirements/original.pdf')
        ->and($defense->workflow_history[0]['comment'])->toBe('Original submission')
        ->and($history['action'])->toBe('resubmitted')
        ->and($history['comment'])->toBe('Updated the methodology as requested.')
        ->and($history['attachments']['manuscript_proposal']['previous'])->toBe('defense-requirements/original.pdf')
        ->and($history['attachments']['manuscript_proposal']['current'])->toBe($defense->manuscript_proposal)
        ->and(Notification::where('user_id', $reviewer)->where('defense_request_id', $defense->id)->count())->toBe(1);
    Storage::disk('public')->assertExists([$defense->manuscript_proposal, 'defense-requirements/original.pdf', 'defense-requirements/payment.pdf']);
    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    $this->postJson("/defense-requirements/{$defense->id}/resubmit", ['student_response' => 'Duplicate submission'])->assertUnprocessable();
    expect(DefenseRequest::count())->toBe(1)->and(count($defense->fresh()->workflow_history))->toBe(count($defense->workflow_history));
})->with([
    'adviser corrections' => ['adviser', 2, 'adviser-review'],
    'coordinator corrections' => ['coordinator', 3, 'adviser-approved'],
]);

it('allows a text-only correction while retaining all existing files and title', function () {
    $defense = correctionDefense([
        'workflow_state' => 'adviser-corrections', 'correction_stage' => 'adviser',
        'manuscript_proposal' => 'defense-requirements/original.pdf',
    ]);
    $this->actingAs(User::find(1))->postJson("/defense-requirements/{$defense->id}/resubmit", [
        'student_response' => 'The requested information is on page 14.',
    ])->assertSuccessful();
    expect($defense->fresh()->manuscript_proposal)->toBe('defense-requirements/original.pdf')
        ->and($defense->fresh()->thesis_title)->toBe('Original research title');
});

it('prevents another student or staff member from resubmitting', function ($user) {
    $defense = correctionDefense(['workflow_state' => 'adviser-corrections', 'correction_stage' => 'adviser']);
    $this->actingAs(User::find($user))->postJson("/defense-requirements/{$defense->id}/resubmit", [
        'student_response' => 'Updated.',
    ])->assertForbidden();
    expect($defense->fresh()->workflow_state)->toBe('adviser-corrections');
})->with([4, 2, 3, 7]);

it('requires a student response before resubmitting', function () {
    $defense = correctionDefense(['workflow_state' => 'adviser-corrections', 'correction_stage' => 'adviser']);
    $this->actingAs(User::find(1))->postJson("/defense-requirements/{$defense->id}/resubmit", [
        'manuscript_proposal' => UploadedFile::fake()->create('revision.pdf', 12, 'application/pdf'),
    ])->assertUnprocessable()->assertJsonValidationErrors('student_response');
    expect($defense->fresh()->workflow_state)->toBe('adviser-corrections')
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('rejects an unsupported replacement before changing the request', function () {
    $defense = correctionDefense(['workflow_state' => 'adviser-corrections', 'correction_stage' => 'adviser']);
    $before = $defense->fresh()->getAttributes();
    $this->actingAs(User::find(1))->postJson("/defense-requirements/{$defense->id}/resubmit", [
        'student_response' => 'Updated.',
        'manuscript_proposal' => UploadedFile::fake()->create('program.exe', 12, 'application/x-msdownload'),
    ])->assertUnprocessable()->assertJsonValidationErrors('manuscript_proposal');
    expect($defense->fresh()->getAttributes())->toBe($before)
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('requires authentication for both correction actions', function () {
    $defense = correctionDefense();
    $this->postJson("/defense-requests/{$defense->id}/request-corrections", ['stage' => 'adviser', 'notes' => 'Update.'])->assertUnauthorized();
    $this->postJson("/defense-requirements/{$defense->id}/resubmit", ['student_response' => 'Updated.'])->assertUnauthorized();
});

it('keeps each correction round in history and notifies again for a later round', function () {
    $defense = correctionDefense();
    for ($round = 1; $round <= 2; $round++) {
        $this->actingAs(User::find(2))->postJson("/defense-requests/{$defense->id}/request-corrections", [
            'stage' => 'adviser', 'notes' => "Revision round {$round}.",
        ])->assertSuccessful();
        $this->actingAs(User::find(1))->postJson("/defense-requirements/{$defense->id}/resubmit", [
            'student_response' => "Addressed revision {$round}.",
        ])->assertSuccessful();
    }
    $defense->refresh();
    expect((int) $defense->correction_count)->toBe(2)
        ->and(collect($defense->workflow_history)->where('action', 'corrections-requested')->count())->toBe(2)
        ->and(collect($defense->workflow_history)->where('action', 'resubmitted')->count())->toBe(2)
        ->and(Notification::where('user_id', 1)->where('defense_request_id', $defense->id)->count())->toBe(2)
        ->and(Notification::where('user_id', 2)->where('defense_request_id', $defense->id)->count())->toBe(3);
});

it('blocks legacy review and scheduling actions while student corrections are pending', function ($method, $path, $user, $payload, $state) {
    $defense = correctionDefense(['workflow_state' => $state]);
    $before = $defense->fresh()->getAttributes();
    $notificationCount = Notification::count();

    $response = $this->actingAs(User::find($user))->json($method, str_replace('{id}', $defense->id, $path), $payload);
    $response->assertUnprocessable();
    expect($response->json('error'))->toContain('resubmit')
        ->and($defense->fresh()->getAttributes())->toBe($before)
        ->and(Notification::count())->toBe($notificationCount);
})->with([
    'adviser status update' => ['PATCH', '/adviser/defense-requirements/{id}/adviser-status', 2, ['status' => 'Approved', 'coordinator_user_id' => 3]],
    'general status update' => ['PATCH', '/defense-requests/{id}/status', 3, ['status' => 'Approved']],
    'coordinator scheduling' => ['POST', '/coordinator/defense-requests/{id}/schedule-json', 3, [
        'scheduled_date' => '2099-12-01', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00',
        'defense_mode' => 'face-to-face', 'defense_venue' => 'Room 1',
    ]],
    'coordinator panel assignment' => ['POST', '/coordinator/defense-requests/{id}/panels', 3, ['defense_chairperson' => 'Test Chairperson', 'defense_panelist1' => 'Test Panelist']],
])->with(['adviser-corrections', 'coordinator-corrections']);

it('skips requests awaiting corrections in bulk status updates', function () {
    $adviserCorrection = correctionDefense(['workflow_state' => 'adviser-corrections']);
    $coordinatorCorrection = correctionDefense(['workflow_state' => 'coordinator-corrections']);
    $before = DefenseRequest::orderBy('id')->get()->map(fn ($defense) => $defense->getAttributes())->all();
    $notificationCount = Notification::count();

    $this->actingAs(User::find(3))->patchJson('/defense-requests/bulk-status', [
        'ids' => [$adviserCorrection->id, $coordinatorCorrection->id], 'status' => 'Approved',
    ])->assertOk()->assertJsonPath('updated_ids', []);

    expect(DefenseRequest::orderBy('id')->get()->map(fn ($defense) => $defense->getAttributes())->all())->toBe($before)
        ->and(Notification::count())->toBe($notificationCount);
});
