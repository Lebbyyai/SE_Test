<?php

use App\Http\Controllers\DefenseRegisterController;
use App\Models\DefenseRequest;
use App\Models\DefenseServiceRecord;
use App\Models\FinanceDocumentSubmission;
use App\Models\HonorariumDisbursement;
use App\Models\Panelist;
use App\Models\PaymentRate;
use App\Models\StudentProgressRecord;
use App\Models\User;
use App\Services\DefenseRegisterExport;
use App\Services\DefenseServiceConfirmation;
use App\Services\HonorariumAccess;
use App\Services\HonorariumLedgerService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(Carbon::parse('2026-10-26 14:00:00', 'Asia/Manila'));
    Http::preventStrayRequests();
    Mail::fake();
});

afterEach(fn () => $this->travelBack());

function registerFixture(array $overrides = []): array
{
    $users = [];
    foreach (['student' => 'Student', 'coordinator' => 'Coordinator', 'aa' => 'Administrative Assistant', 'finance' => 'Finance', 'adviser' => 'Adviser', 'other' => 'Coordinator'] as $name => $role) {
        $users[$name] = User::factory()->create(['role' => $role]);
    }
    $chair = Panelist::create(['name' => 'Test Chair', 'email' => 'chair@example.test', 'role' => 'Chairperson', 'status' => 'Assigned']);
    $member = Panelist::create(['name' => 'Test Member', 'email' => 'member@example.test', 'role' => 'Panel Member', 'status' => 'Assigned']);
    $defense = DefenseRequest::withoutEvents(fn () => DefenseRequest::create($overrides + ['submitted_by' => $users['student']->id, 'adviser_user_id' => $users['adviser']->id, 'coordinator_user_id' => $users['coordinator']->id, 'thesis_title' => 'Example Study', 'program' => 'Master of Arts in Education', 'school_id' => 'TEST-123', 'first_name' => 'Example', 'last_name' => 'Student', 'defense_type' => 'Proposal', 'workflow_state' => 'scheduled', 'status' => 'Approved', 'proof_of_payment' => 'test-receipt.pdf', 'defense_adviser' => 'Test Adviser', 'defense_chairperson' => $chair->id, 'defense_panelist1' => $member->id, 'scheduled_date' => '2026-10-25', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00', 'defense_mode' => 'online', 'defense_venue' => 'Test Room']));

    return [$defense, $users];
}

function registerPayload(DefenseRequest $defense, string $memberType = 'internal'): array
{
    return ['held' => true, 'completed_on' => '2026-10-25', 'notes' => '', 'participants' => array_map(fn ($person) => ['recipient_key' => $person['recipient_key'], 'affiliation' => $person['recipient_role'] === 'Adviser' ? null : ($person['recipient_role'] === 'Panel Member' ? $memberType : 'internal'), 'attendance' => 'rendered'], app(HonorariumLedgerService::class)->recipients($defense))];
}

function registerClassify(DefenseRequest $defense, array $users, array $payload): void
{
    test()->actingAs($users['coordinator'])->patchJson('/defense-register/'.$defense->id.'/panel-types', ['participants' => array_values(array_filter($payload['participants'], fn ($p) => str_starts_with($p['recipient_key'], 'panel:')))])->assertOk();
    $defense->refresh();
}

function registerVerify(DefenseRequest $defense): void
{
    foreach (['payment_receipt', 'panel_breakdown'] as $type) {
        FinanceDocumentSubmission::updateOrCreate(['defense_request_id' => $defense->id, 'document_type' => $type], ['status' => 'verified', 'payload' => $type === 'payment_receipt' ? ['receipt_path' => $defense->proof_of_payment] : ['panel_names' => app(HonorariumAccess::class)->panelNames($defense), 'panel_affiliations' => $defense->panel_affiliations ?? []]]);
    }
    foreach (['Panel Chair' => 1500, 'Panel Member 1' => 600, 'Adviser' => 1000] as $type => $amount) {
        PaymentRate::updateOrCreate(['program_level' => 'Masteral', 'defense_type' => 'Proposal', 'type' => $type], ['amount' => $amount]);
    }
}

test('register and exports reject students and signed-out visitors', function () {
    [$defense, $users] = registerFixture();
    foreach (['/defense-register', '/defense-register/export', '/defense-register/print'] as $path) {
        $this->getJson($path)->assertUnauthorized();
        $this->actingAs($users['student'])->getJson($path)->assertForbidden();
        auth()->logout();
    }
});

test('coordinators only see assigned defenses and exports enforce that same scope', function () {
    [$defense, $users] = registerFixture();
    $foreign = $defense->replicate();
    $foreign->coordinator_user_id = $users['other']->id;
    $foreign->thesis_title = 'FOREIGN PRIVATE TITLE';
    $foreign->saveQuietly();
    $this->actingAs($users['coordinator'])->get('/defense-register')->assertOk()->assertInertia(fn (Assert $page) => $page->has('items', 1)->where('items.0.id', $defense->id));
    $this->get('/defense-register/print')->assertOk()->assertDontSee('FOREIGN PRIVATE TITLE');
});

test('Dean and AA can filter one date by defense stage and export the matching sessions', function () {
    [$proposal, $users] = registerFixture();
    foreach ([
        ['Pre-final', 'Prefinal Study', '2026-10-25', 'Approved'],
        ['Final', 'Final Study', '2026-10-25', 'Approved'],
        ['Pre Final', 'Different Day', '2026-10-26', 'Approved'],
        ['Prefinal', 'Cancelled Session', '2026-10-25', 'Cancelled'],
    ] as [$type, $title, $date, $status]) {
        $copy = $proposal->replicate()->fill([
            'defense_type' => $type, 'thesis_title' => $title, 'scheduled_date' => $date, 'status' => $status,
        ]);
        $copy->saveQuietly();
    }

    $day = 'from=2026-10-25&to=2026-10-25';
    $dean = User::factory()->create(['role' => 'Dean']);
    foreach ([$dean, $users['aa']] as $reader) {
        $this->actingAs($reader)->get('/defense-register?'.$day)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('items', 3)->where('stage', '')->where('from', '2026-10-25')->where('to', '2026-10-25'));

        foreach (['proposal' => 'Example Study', 'prefinal' => 'Prefinal Study', 'final' => 'Final Study'] as $stage => $title) {
            $this->get('/defense-register?'.$day.'&stage='.$stage)->assertOk()->assertInertia(fn (Assert $page) => $page
                ->where('stage', $stage)->has('items', 1)->where('items.0.title', $title)
                ->where('items.0.student', $users['student']->full_name)
                ->where('items.0.date', '2026-10-25')->where('items.0.start', '09:00')->where('items.0.end', '10:00')
                ->where('items.0.participants.0.recipient_name', 'Test Chair')
                ->where('items.0.participants.1.recipient_name', 'Test Member'));
        }

        $this->get('/defense-register/print?'.$day.'&stage=prefinal')->assertOk()
            ->assertSee('Prefinal Study')->assertDontSee('Final Study')->assertDontSee('Different Day');
        $csv = $this->get('/defense-register/csv?'.$day.'&stage=prefinal')->assertOk()->streamedContent();
        expect($csv)->toContain('Prefinal Study')->not->toContain('Example Study', 'Different Day', 'Cancelled Session');
    }

    $this->getJson('/defense-register?'.$day.'&stage=invalid')->assertUnprocessable()->assertJsonValidationErrors('stage');
});

test('filters one panelist by date and defense type for Dean, AA and the assigned coordinator', function () {
    [$defense, $users] = registerFixture();
    StudentProgressRecord::create([
        'student_id' => $users['student']->id, 'semester' => '1st semester', 'school_year' => '2026-2027',
        'subjects' => [['title' => 'Thesis 1', 'research_subject' => 'Thesis 1']],
        'record_reference' => 'Verified test enrollment', 'recorded_at' => now(),
    ]);
    foreach ([
        ['Prefinal', 'Other stage', '2026-10-25', $defense->defense_panelist1],
        ['Proposal', 'Other day', '2026-10-24', $defense->defense_panelist1],
        ['Proposal', 'Other panel', '2026-10-25', null],
    ] as [$type, $title, $date, $member]) {
        $defense->replicate()->fill([
            'submitted_by' => User::factory()->create(['role' => 'Student'])->id,
            'defense_type' => $type, 'thesis_title' => $title, 'scheduled_date' => $date, 'defense_panelist1' => $member,
        ])->saveQuietly();
    }
    $query = '?from=2026-10-25&to=2026-10-25&stage=proposal&panelist='.$defense->defense_panelist1;
    foreach ([User::factory()->create(['role' => 'Dean']), $users['aa'], $users['coordinator']] as $reader) {
        $this->actingAs($reader)->get('/defense-register'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('canReviewPanelAssignments', true)->has('items', 1)->where('items.0.id', $defense->id)
            ->where('items.0.student', $users['student']->full_name)->where('items.0.current_stage', 'Proposal')
            ->where('items.0.subject_enrollment', '1st semester (2026-2027) - Thesis 1')
            ->where('items.0.participants.1.recipient_name', 'Test Member'));
        $csv = $this->get('/defense-register/csv'.$query)->assertOk()->streamedContent();
        expect($csv)->toContain('Example Study', 'Test Member', 'Thesis 1')->not->toContain('Other stage', 'Other day', 'Other panel');
    }
    $this->actingAs($users['other'])->get('/defense-register'.$query)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('items', 0)->has('panelists', 0));
    $this->actingAs($users['finance'])->getJson('/defense-register'.$query)->assertForbidden();
});

test('register rejects inverted and excessively large export date ranges', function () {
    [, $users] = registerFixture();
    $this->actingAs($users['aa'])->getJson('/defense-register/export?from=2026-10-26&to=2026-10-25')->assertUnprocessable();
    $this->getJson('/defense-register/export?from=2026-01-01&to=2026-12-31')->assertUnprocessable();
});

test('future classifications can be saved without claiming service was rendered', function () {
    [$defense, $users] = registerFixture(['scheduled_date' => '2026-10-27']);
    registerClassify($defense, $users, registerPayload($defense, 'external'));
    expect(DefenseServiceRecord::count())->toBe(0)->and($defense->workflow_state)->toBe('scheduled');
    $this->get('/defense-register')->assertInertia(fn (Assert $page) => $page->where('items.0.participants.1.affiliation', 'external')->where('items.0.service_status', 'pending'));
});

test('only assigned coordinators and authorized staff can classify or confirm service', function () {
    [$defense, $users] = registerFixture();
    foreach (['other', 'student', 'finance', 'adviser'] as $name) {
        $this->actingAs($users[$name])->patchJson('/defense-register/'.$defense->id.'/panel-types', ['participants' => registerPayload($defense)['participants']])->assertForbidden();
        $this->postJson('/defense-register/'.$defense->id.'/confirm-service', registerPayload($defense))->assertForbidden();
    }
});

test('service confirmation rejects future sessions, future completion dates and unsaved classifications', function () {
    [$defense, $users] = registerFixture(['scheduled_date' => '2026-10-27']);
    $payload = registerPayload($defense);
    registerClassify($defense, $users, $payload);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertUnprocessable();
    $defense->scheduled_date = '2026-10-25';
    $defense->saveQuietly();
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', ['completed_on' => '2026-10-27'] + $payload)->assertUnprocessable();
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', registerPayload($defense, 'external'))->assertUnprocessable();
});

test('confirmation requires an explicit held statement and a complete untampered attendance roster', function () {
    [$defense, $users] = registerFixture();
    $payload = registerPayload($defense);
    registerClassify($defense, $users, $payload);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', ['held' => false] + $payload)->assertUnprocessable();
    $missing = $payload;
    array_pop($missing['participants']);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $missing)->assertUnprocessable();
    $forged = $payload;
    $forged['participants'][0]['recipient_key'] = 'panel:999:Panel Chair';
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $forged)->assertUnprocessable();
    $duplicate = $payload;
    $duplicate['participants'][1] = $duplicate['participants'][0];
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $duplicate)->assertUnprocessable();
    expect(DefenseServiceRecord::count())->toBe(0);
});

test('preparation needs confirmed service even when Finance documents are verified', function () {
    [$defense, $users] = registerFixture();
    registerVerify($defense);
    $this->actingAs($users['aa'])->postJson('/honorarium-ledger/'.$defense->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertUnprocessable()->assertJsonValidationErrors('service');
});

test('confirmed attendance excludes absent recipients and snapshots explicit external rates', function () {
    [$defense, $users] = registerFixture();
    $payload = registerPayload($defense, 'external');
    $payload['participants'][2]['attendance'] = 'absent';
    registerClassify($defense, $users, $payload);
    registerVerify($defense);
    PaymentRate::create(['program_level' => 'Masteral', 'defense_type' => 'Proposal', 'type' => 'External Panel Member', 'amount' => 2300]);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertOk();
    $this->actingAs($users['aa'])->postJson('/honorarium-ledger/'.$defense->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertOk();
    expect(HonorariumDisbursement::count())->toBe(2)->and($defense->fresh()->workflow_state)->toBe('completed');
    $external = HonorariumDisbursement::where('recipient_role', 'External Panel Member')->firstOrFail();
    expect($external->recipient_affiliation)->toBe('external')->and((float) $external->amount)->toBe(2300.0)->and($external->service_evidence['participant']['attendance'])->toBe('rendered');
});

test('missing external rates cannot fall back to ordinary internal rates', function () {
    [$defense, $users] = registerFixture();
    $payload = registerPayload($defense, 'external');
    registerClassify($defense, $users, $payload);
    registerVerify($defense);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertOk();
    $this->actingAs($users['aa'])->postJson('/honorarium-ledger/'.$defense->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertUnprocessable()->assertJsonValidationErrors('rates');
    expect(HonorariumDisbursement::count())->toBe(0);
});

test('internal members use the existing numbered Dean rate', function () {
    [$defense, $users] = registerFixture();
    $payload = registerPayload($defense);
    registerClassify($defense, $users, $payload);
    registerVerify($defense);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertOk();
    $this->actingAs($users['aa'])->postJson('/honorarium-ledger/'.$defense->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertOk();
    expect((float) HonorariumDisbursement::where('recipient_role', 'Panel Member')->firstOrFail()->amount)->toBe(600.0);
});

test('attendance revisions retain old evidence, void unpaid records and flag paid records', function () {
    [$defense, $users] = registerFixture();
    $payload = registerPayload($defense);
    registerClassify($defense, $users, $payload);
    registerVerify($defense);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertOk();
    $this->actingAs($users['aa'])->postJson('/honorarium-ledger/'.$defense->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertOk();
    $paid = HonorariumDisbursement::firstOrFail();
    $paid->update(['status' => 'disbursed', 'disbursed_on' => '2026-10-25', 'payment_reference' => 'TEST']);
    $payload['participants'][1]['attendance'] = 'absent';
    $this->actingAs($users['coordinator'])->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertUnprocessable();
    $payload['notes'] = 'Corrected attendance from signed session record.';
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertOk();
    expect($paid->fresh()->status)->toBe('disbursed')->and($paid->fresh()->requires_review)->toBeTrue()->and(HonorariumDisbursement::where('status', 'voided')->count())->toBe(2)->and(DefenseServiceRecord::first()->history)->toHaveCount(1);
});

test('classification edits invalidate Finance verification and current service evidence', function () {
    [$defense, $users] = registerFixture();
    $payload = registerPayload($defense);
    registerClassify($defense, $users, $payload);
    registerVerify($defense);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertOk();
    $this->patchJson('/defense-register/'.$defense->id.'/panel-types', ['notes' => 'Correct external membership', 'participants' => array_values(array_filter(registerPayload($defense, 'external')['participants'], fn ($p) => str_starts_with($p['recipient_key'], 'panel:')))])->assertOk();
    expect(FinanceDocumentSubmission::where('document_type', 'panel_breakdown')->first()->status)->toBe('rejected');
    expect(app(DefenseServiceConfirmation::class)->current($defense->fresh()))->toBeNull();
});

test('exports preserve parallel sessions and store user text literally without formulas', function () {
    [$defense, $users] = registerFixture(['thesis_title' => '=HYPERLINK("https://example.test","example")']);
    $parallel = $defense->replicate();
    $parallel->thesis_title = 'Parallel session';
    $parallel->saveQuietly();
    $longer = $defense->replicate();
    $longer->thesis_title = 'Same start, different end';
    $longer->scheduled_end_time = '11:00';
    $longer->saveQuietly();
    $this->actingAs($users['coordinator']);
    $request = Request::create('/defense-register');
    $request->setUserResolver(fn () => $users['coordinator']);
    $data = app(DefenseRegisterController::class)->data($request);
    $path = app(DefenseRegisterExport::class)->xlsx($data);
    try {
        $zip = new ZipArchive;
        expect($zip->open($path))->toBeTrue();
        $matrix = $zip->getFromName('xl/worksheets/sheet1.xml');
        expect($matrix)->toContain('Parallel session')->toContain('HYPERLINK')->not->toContain('<f>');
        $xml = new DOMDocument;
        expect($xml->loadXML($matrix))->toBeTrue();
        $xpath = new DOMXPath($xml);
        $xpath->registerNamespace('s', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        expect($xpath->evaluate('string(//s:c[@r="A5"])'))->toBe("09:00–10:00\nDefense")
            ->and($xpath->evaluate('string(//s:c[@r="A9"])'))->toBe("09:00–11:00\nDefense")
            ->and($xpath->evaluate('string(//s:c[@r="B5"])'))->toContain('Parallel session')->not->toContain('Same start, different end')
            ->and($xpath->evaluate('string(//s:c[@r="B9"])'))->toContain('Same start, different end');
        $details = $zip->getFromName('xl/worksheets/sheet2.xml');
        expect($details)->toContain('TEST-123')->toContain('s="1"');
        $zip->close();
    } finally {
        unlink($path);
    }
    $download = $this->get('/defense-register/export')->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    unlink($download->baseResponse->getFile()->getPathname());
});

test('print report escapes titles and clearly labels unclassified panelists', function () {
    [, $users] = registerFixture(['thesis_title' => '<script>bad()</script>']);
    $this->actingAs($users['aa'])->get('/defense-register/print')->assertOk()->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false)->assertSee('Unclassified panel')
        ->assertSee('<th>09:00–10:00<br>Defense</th>', false);
});

test('an ongoing defense cannot be marked completed before its end time', function () {
    [$defense, $users] = registerFixture(['scheduled_date' => '2026-10-26', 'scheduled_time' => '13:00', 'scheduled_end_time' => '15:00']);
    $payload = registerPayload($defense);
    $payload['completed_on'] = '2026-10-26';
    registerClassify($defense, $users, $payload);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertUnprocessable();
    expect(DefenseServiceRecord::count())->toBe(0);
});

test('cancelled defenses never appear in the register or exports', function () {
    [$defense, $users] = registerFixture(['status' => 'cancelled']);
    $this->actingAs($users['aa'])->get('/defense-register')->assertInertia(fn (Assert $page) => $page->has('items', 0));
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', registerPayload($defense))->assertUnprocessable();
});

test('legacy duplicate panel names cannot silently identify a financial recipient', function () {
    [$defense] = registerFixture();
    Panelist::create(['name' => 'Test Chair', 'email' => 'other-chair@example.test', 'role' => 'Chairperson', 'status' => 'Assigned']);
    $defense->defense_chairperson = 'Test Chair';
    expect(fn () => app(HonorariumLedgerService::class)->recipients($defense))->toThrow(ValidationException::class);
});

test('a disbursement cannot predate the confirmed completion date', function () {
    [$defense, $users] = registerFixture();
    $payload = registerPayload($defense);
    $payload['completed_on'] = '2026-10-26';
    registerClassify($defense, $users, $payload);
    registerVerify($defense);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertOk();
    $this->actingAs($users['aa'])->postJson('/honorarium-ledger/'.$defense->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertOk();
    $row = HonorariumDisbursement::firstOrFail();
    $this->actingAs($users['finance'])->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'approved'])->assertOk();
    $this->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'disbursed', 'event_date' => '2026-10-25', 'payment_reference' => 'TEST'])->assertUnprocessable()->assertJsonValidationErrors('event_date');
});

test('changed schedules cannot reuse stale payment service evidence', function () {
    [$defense, $users] = registerFixture();
    $payload = registerPayload($defense);
    registerClassify($defense, $users, $payload);
    registerVerify($defense);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertOk();
    $this->actingAs($users['aa'])->postJson('/honorarium-ledger/'.$defense->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertOk();
    $row = HonorariumDisbursement::firstOrFail();
    $defense->scheduled_time = '08:30';
    $defense->saveQuietly();
    $this->actingAs($users['finance'])->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'approved'])->assertUnprocessable()->assertJsonValidationErrors('service');
});

test('configuring an external honorarium rate does not automatically increase student fee summaries', function () {
    [$defense, $users] = registerFixture();
    registerVerify($defense);
    PaymentRate::create(['program_level' => 'Masteral', 'defense_type' => 'Proposal', 'type' => 'External Panel Member', 'amount' => 9999]);
    $this->actingAs($users['coordinator'])->getJson('/coordinator/defense-requests/all-defense-requests')->assertOk()->assertJsonPath('0.expected_rate', 3100);
});

test('service confirmation does not guess a missing session end time', function () {
    [$defense, $users] = registerFixture(['scheduled_end_time' => null]);
    $payload = registerPayload($defense);
    registerClassify($defense, $users, $payload);
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', $payload)->assertUnprocessable()->assertJsonValidationErrors('defense');
});

test('repeated approval preserves assigned scheduled and completed defenses', function () {
    [$defense, $users] = registerFixture(['scheduled_date' => '2026-11-03', 'coordinator_status' => 'Approved', 'workflow_history' => []]);
    foreach (['panels-assigned', 'scheduled', 'completed'] as $state) {
        $defense->forceFill(['workflow_state' => $state])->saveQuietly();
        $this->actingAs($users['coordinator'])->patchJson('/defense-requests/'.$defense->id.'/status', ['status' => 'Approved'])
            ->assertOk()->assertJsonPath('workflow_state', $state);
        expect($defense->fresh()->workflow_state)->toBe($state)->and($defense->fresh()->workflow_history)->toBe([]);
        if ($state !== 'panels-assigned') {
            $this->get('/defense-register?from=2026-11-01&to=2026-11-30')->assertOk()->assertInertia(fn (Assert $page) => $page->where('items.0.id', $defense->id));
        }
    }
});

test('approval repairs legacy pending approval without erasing a saved schedule or completion', function () {
    [$defense, $users] = registerFixture(['workflow_history' => []]);
    foreach (['panels-assigned', 'scheduled', 'completed'] as $state) {
        $defense->refresh()->forceFill(['workflow_state' => $state, 'coordinator_status' => 'Pending', 'status' => 'Pending', 'workflow_history' => []])->saveQuietly();
        $before = $defense->fresh()->only(['scheduled_date', 'scheduled_time', 'scheduled_end_time', 'defense_venue', 'defense_chairperson']);
        $this->actingAs($users['coordinator'])->patchJson('/defense-requests/'.$defense->id.'/status', ['status' => 'Approved'])
            ->assertOk()->assertJsonPath('coordinator_status', 'Approved')->assertJsonPath('workflow_state', $state);
        $updated = $defense->fresh();
        expect($updated->coordinator_status)->toBe('Approved')
            ->and($updated->status)->toBe($state === 'completed' ? 'Completed' : 'Approved')
            ->and($updated->only(array_keys($before)))->toEqual($before)
            ->and(count($updated->workflow_history))->toBe(1);
        $this->patchJson('/defense-requests/'.$defense->id.'/status', ['status' => 'Approved'])->assertOk();
        expect(count($defense->fresh()->workflow_history))->toBe(1);
    }
});
