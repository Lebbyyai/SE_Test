<?php

use App\Http\Controllers\DefenseRegisterController;
use App\Models\DefenseRequest;
use App\Models\DefenseServiceRecord;
use App\Models\FinanceDocumentSubmission;
use App\Models\HonorariumDisbursement;
use App\Models\Panelist;
use App\Models\PaymentRate;
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
    $this->actingAs($users['aa'])->get('/defense-register/print')->assertOk()->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false)->assertSee('Unclassified panel');
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
