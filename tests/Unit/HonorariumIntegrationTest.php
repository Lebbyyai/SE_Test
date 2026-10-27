<?php

use App\Helpers\ProgramLevel;
use App\Models\DefenseRequest;
use App\Models\DefenseServiceRecord;
use App\Models\FinanceDocumentSubmission;
use App\Models\HonorariumActivity;
use App\Models\HonorariumDisbursement;
use App\Models\Notification;
use App\Models\User;
use App\Services\DefenseServiceConfirmation;
use App\Services\FinanceDocumentWorkflowService;
use App\Services\HonorariumLedgerService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

uses(TestCase::class);
beforeEach(function () {
    $this->withoutVite();
    Schema::create('users', function (Blueprint $t) {
        $t->id();
        foreach (['first_name', 'middle_name', 'last_name', 'email', 'role', 'school_id', 'program'] as $f) {
            $t->string($f)->nullable();
        }$t->timestamp('email_verified_at')->nullable();
        $t->timestamps();
    });
    Schema::create('advisers', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('user_id');
        $t->string('status');
    });
    Schema::create('panelists', function (Blueprint $t) {
        $t->id();
        $t->string('name');
        $t->string('email');
    });
    Schema::create('payment_rates', function (Blueprint $t) {
        $t->id();
        foreach (['program_level', 'type', 'defense_type'] as $f) {
            $t->string($f);
        }$t->decimal('amount', 10, 2);
    });
    Schema::create('honorarium_payments', function (Blueprint $t) {
        $t->id();
        $t->unsignedBigInteger('defense_request_id');
    });
    Schema::create('defense_requests', function (Blueprint $t) {
        $t->id();
        foreach (['submitted_by', 'adviser_user_id', 'coordinator_user_id', 'defense_chairperson', 'defense_panelist1', 'defense_panelist2', 'defense_panelist3', 'defense_panelist4'] as $f) {
            $t->unsignedBigInteger($f)->nullable();
        }foreach (['thesis_title', 'first_name', 'last_name', 'school_id', 'program', 'workflow_state', 'status', 'proof_of_payment', 'defense_type', 'scheduled_time', 'scheduled_end_time', 'defense_venue', 'defense_mode'] as $f) {
            $t->string($f)->nullable();
        }$t->unsignedBigInteger('schedule_set_by')->nullable();
        $t->unsignedBigInteger('panels_assigned_by')->nullable();
        $t->unsignedBigInteger('last_status_updated_by')->nullable();
        $t->timestamp('last_status_updated_at')->nullable();
        $t->timestamp('panels_assigned_at')->nullable();
        $t->date('scheduled_date')->nullable();
        $t->json('workflow_history')->nullable();
        $t->timestamps();
    });
    (require glob(database_path('migrations/*create_notifications_table.php'))[0])->up();
    (require database_path('migrations/2026_10_23_000003_add_notification_event_tracking.php'))->up();
    foreach (['2026_10_10_000001_create_finance_honorarium_submissions_table.php', '2026_10_17_000001_create_finance_document_submissions_table.php', '2026_10_17_000002_create_honorarium_activities_table.php'] as $m) {
        (require database_path('migrations/'.$m))->up();
    }
    (require database_path('migrations/2026_10_24_000003_create_honorarium_disbursements.php'))->up();
    (require database_path('migrations/2026_10_24_000004_create_defense_schedule_mutex.php'))->up();
    (require database_path('migrations/2026_10_26_000001_create_defense_service_records.php'))->up();
    foreach (['Student', 'Administrative Assistant', 'Finance', 'Adviser', 'Coordinator', 'Adviser'] as $i => $role) {
        DB::table('users')->insert(['id' => $i + 1, 'role' => $role, 'first_name' => 'Demo', 'last_name' => 'Person', 'email' => 'user'.($i + 1).'@example.test', 'school_id' => 'S'.($i + 1), 'program' => 'Test', 'email_verified_at' => now()]);
    }
    DB::table('panelists')->insert(['id' => 1, 'name' => 'Demo Person', 'email' => 'user4@example.test']);
    Mail::fake();
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-10-24 08:00:00', 'Asia/Manila'));
});
afterEach(function () {
    $this->travelBack();
    foreach (['defense_service_records', 'honorarium_disbursements', 'defense_schedule_mutex', 'honorarium_activities', 'finance_document_submissions', 'finance_honorarium_submissions', 'notifications', 'honorarium_payments', 'defense_requests', 'payment_rates', 'panelists', 'advisers', 'users'] as $t) {
        Schema::dropIfExists($t);
    }
});
function integratedDefense(array $data = []): DefenseRequest
{
    return DefenseRequest::create($data + ['submitted_by' => 1, 'adviser_user_id' => 4, 'coordinator_user_id' => 5, 'thesis_title' => 'Integration Study', 'program' => 'Test', 'workflow_state' => 'coordinator-approved', 'status' => 'Approved', 'proof_of_payment' => 'defense_requirements/receipt.pdf', 'defense_chairperson' => 1]);
}
function sendIntegratedReceipt(DefenseRequest $d)
{
    test()->actingAs(User::find(2))->post('/honorarium-documents/'.$d->id.'/submit-one')->assertRedirect();

    return FinanceDocumentSubmission::where('document_type', 'payment_receipt')->where('defense_request_id', $d->id)->firstOrFail();
}
it('sends receipts once and notifies Finance without sending email', function () {
    $d = integratedDefense();
    $doc = sendIntegratedReceipt($d);
    expect($doc->payload['receipt_path'])->toBe($d->proof_of_payment)->and(FinanceDocumentSubmission::count())->toBe(2)->and(Notification::where('user_id', 3)->count())->toBe(1);
    $this->postJson('/honorarium-documents/'.$d->id.'/submit-one')->assertStatus(422);
    expect(FinanceDocumentSubmission::count())->toBe(2);
    Mail::assertNothingSent();
});
it('allows only the AA to submit and Finance to review', function () {
    $d = integratedDefense();
    $this->actingAs(User::find(1))->postJson('/honorarium-documents/'.$d->id.'/submit-one')->assertForbidden();
    $doc = sendIntegratedReceipt($d);
    $this->postJson('/honorarium-documents/finance/'.$doc->id.'/review', ['status' => 'verified'])->assertForbidden();
    $this->actingAs(User::find(3))->post('/honorarium-documents/finance/'.$doc->id.'/review', ['status' => 'verified'])->assertRedirect();
    expect($doc->fresh()->status)->toBe('verified')->and($doc->fresh()->reviewed_by)->toBe(3);
});
it('unlocks once on the next Philippine calendar day', function () {
    $d = integratedDefense();
    sendIntegratedReceipt($d);
    $service = app(FinanceDocumentWorkflowService::class);
    expect($service->unlockDueDocuments())->toBe(0);
    $this->travelTo(Carbon::parse('2026-10-25 00:01:00', 'Asia/Manila'));
    expect($service->unlockDueDocuments())->toBe(1)->and($service->unlockDueDocuments())->toBe(0)->and(HonorariumActivity::where('activity_type', 'document_ready')->count())->toBe(1);
});
it('requires a verified receipt and current panelists before sending the breakdown', function () {
    $d = integratedDefense();
    $first = sendIntegratedReceipt($d);
    $this->travel(1)->days();
    $this->postJson('/honorarium-documents/'.$d->id.'/submit-two')->assertStatus(422);
    $this->actingAs(User::find(3))->post('/honorarium-documents/finance/'.$first->id.'/review', ['status' => 'verified'])->assertRedirect();
    $this->actingAs(User::find(2))->post('/honorarium-documents/'.$d->id.'/submit-two')->assertRedirect();
    $second = FinanceDocumentSubmission::where('document_type', 'panel_breakdown')->first();
    expect($second->payload['panel_names'])->toBe(['Demo Person']);
    $this->actingAs(User::find(3))->post('/honorarium-documents/finance/'.$second->id.'/review', ['status' => 'verified'])->assertRedirect();
    $this->get('/honorarium-workspace')->assertOk()->assertInertia(fn (Assert $p) => $p->component('honorarium/workspace/Index')->where('items.0.finance_status', 'verified'));
});
it('requires reasons for rejection and keeps prior review activity on resend', function () {
    $d = integratedDefense();
    $doc = sendIntegratedReceipt($d);
    $this->actingAs(User::find(3))->postJson('/honorarium-documents/finance/'.$doc->id.'/review', ['status' => 'rejected'])->assertUnprocessable();
    $this->post('/honorarium-documents/finance/'.$doc->id.'/review', ['status' => 'rejected', 'finance_notes' => 'Receipt unreadable'])->assertRedirect();
    sendIntegratedReceipt($d);
    expect(HonorariumActivity::where('activity_type', 'finance_review')->count())->toBe(1)->and(FinanceDocumentSubmission::count())->toBe(2);
});
it('does not send unapproved or correction-pending defenses to Finance', function ($state) {
    $d = integratedDefense(['workflow_state' => $state]);
    $this->actingAs(User::find(2))->postJson('/honorarium-documents/'.$d->id.'/submit-one')->assertStatus(422);
    expect(FinanceDocumentSubmission::count())->toBe(0);
})->with(['submitted', 'adviser-review', 'adviser-corrections', 'coordinator-corrections', 'cancelled']);
it('scopes adviser records by account email rather than a shared name and protects activity logs', function () {
    $d = integratedDefense();
    sendIntegratedReceipt($d);
    $this->actingAs(User::find(4))->get('/honorarium-workspace')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 1));
    $this->actingAs(User::find(6))->get('/honorarium-documents')->assertOk()->assertInertia(fn (Assert $p) => $p->has('items', 0)->has('activities', 0));
    $this->actingAs(User::find(1))->get('/honorarium-workspace')->assertForbidden();
});
it('does not verify a receipt that has been replaced since it was sent', function () {
    $d = integratedDefense();
    $doc = sendIntegratedReceipt($d);
    $d->update(['proof_of_payment' => 'new-receipt.pdf']);
    $this->actingAs(User::find(3))->postJson('/honorarium-documents/finance/'.$doc->id.'/review', ['status' => 'verified'])->assertStatus(422);
    expect($doc->fresh()->status)->toBe('rejected');
});
it('does not verify obsolete panel names after an assignment changes', function () {
    $d = integratedDefense();
    $first = sendIntegratedReceipt($d);
    $this->actingAs(User::find(3))->post('/honorarium-documents/finance/'.$first->id.'/review', ['status' => 'verified']);
    $this->travel(1)->days();
    $this->actingAs(User::find(2))->post('/honorarium-documents/'.$d->id.'/submit-two');
    $second = FinanceDocumentSubmission::where('document_type', 'panel_breakdown')->first();
    $d->update(['defense_chairperson' => null]);
    $this->actingAs(User::find(3))->postJson('/honorarium-documents/finance/'.$second->id.'/review', ['status' => 'verified'])->assertStatus(422);
});
it('keeps Finance in its workspace and denies unauthenticated access', function () {
    $this->getJson('/honorarium-documents')->assertUnauthorized();
    $this->actingAs(User::find(3))->get('/dashboard')->assertRedirect('/honorarium-workspace');
});

function verifiedLedgerDefense(): DefenseRequest
{
    $d = integratedDefense(['defense_type' => 'Proposal', 'workflow_state' => 'completed', 'status' => 'Completed', 'scheduled_date' => '2026-10-23', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00', 'panel_affiliations' => ['panel:1:Panel Chair' => 'internal']]);
    foreach (['payment_receipt', 'panel_breakdown'] as $type) {
        FinanceDocumentSubmission::create(['defense_request_id' => $d->id, 'document_type' => $type, 'status' => 'verified', 'payload' => $type === 'payment_receipt' ? ['receipt_path' => $d->proof_of_payment] : ['panel_names' => ['Demo Person'], 'panel_affiliations' => $d->panel_affiliations]]);
    }
    foreach (['Panel Chair' => 1500, 'Adviser' => 1000] as $type => $amount) {
        DB::table('payment_rates')->insert(['program_level' => 'Masteral', 'type' => $type, 'defense_type' => 'Proposal', 'amount' => $amount]);
    }

    $service = app(DefenseServiceConfirmation::class);
    DefenseServiceRecord::create(['defense_request_id' => $d->id, 'snapshot_hash' => $service->snapshot($d), 'participants' => array_map(fn ($person) => $person + ['affiliation' => $person['recipient_role'] === 'Adviser' ? null : 'internal', 'attendance' => 'rendered'], app(HonorariumLedgerService::class)->recipients($d)), 'completed_on' => '2026-10-23', 'confirmed_by' => 5, 'confirmed_at' => now(), 'history' => []]);

    return $d;
}
it('prepares immutable recipient amounts from Dean rates without duplicates', function () {
    $d = verifiedLedgerDefense();
    $this->actingAs(User::find(2))->postJson('/honorarium-ledger/'.$d->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertOk();
    $rows = HonorariumDisbursement::all();
    expect($rows)->toHaveCount(2)->and((float) $rows->sum('amount'))->toBe(2500.0);
    DB::table('payment_rates')->update(['amount' => 999]);
    expect((float) $rows->first()->fresh()->amount)->toBe(1500.0);
    $this->postJson('/honorarium-ledger/'.$d->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertUnprocessable();
    expect(HonorariumDisbursement::count())->toBe(2);
});
it('rolls back every prepared payment when one recipient has no rate', function () {
    $d = verifiedLedgerDefense();
    DB::table('payment_rates')->where('type', 'Adviser')->delete();
    $this->actingAs(User::find(2))->postJson('/honorarium-ledger/'.$d->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st'])->assertUnprocessable();
    expect(HonorariumDisbursement::count())->toBe(0);
});
it('records Finance approval disbursement and claiming in order with history', function () {
    $d = verifiedLedgerDefense();
    $this->actingAs(User::find(2))->postJson('/honorarium-ledger/'.$d->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st']);
    $row = HonorariumDisbursement::first();
    $this->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'approved'])->assertForbidden();
    $this->actingAs(User::find(3))->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'claimed', 'event_date' => '2026-10-24'])->assertUnprocessable();
    $this->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'approved'])->assertOk();
    $this->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'disbursed', 'event_date' => '2026-10-25', 'payment_reference' => 'TEST-RECORD'])->assertUnprocessable();
    $d->update(['workflow_state' => 'completed']);
    $this->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'disbursed', 'event_date' => '2026-10-24'])->assertUnprocessable();
    $this->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'disbursed', 'event_date' => '2026-10-24', 'payment_reference' => 'TEST-RECORD'])->assertOk();
    $this->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'claimed', 'event_date' => '2026-10-23'])->assertUnprocessable();
    $this->patchJson('/honorarium-ledger/'.$row->id, ['status' => 'claimed', 'event_date' => '2026-10-24'])->assertOk();
    expect($row->fresh()->status)->toBe('claimed')->and($row->fresh()->history)->toHaveCount(4);
});
it('invalidates changed Finance snapshots and preserves paid history for review', function () {
    $d = verifiedLedgerDefense();
    $this->actingAs(User::find(2))->postJson('/honorarium-ledger/'.$d->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st']);
    $rows = HonorariumDisbursement::get();
    $rows[0]->update(['status' => 'disbursed', 'disbursed_on' => '2026-10-24', 'payment_reference' => 'TEST-EXISTING']);
    $d->update(['proof_of_payment' => 'replacement.pdf']);
    expect(FinanceDocumentSubmission::where('status', 'rejected')->count())->toBe(2)->and($rows[0]->fresh()->requires_review)->toBeTrue()->and($rows[0]->fresh()->status)->toBe('disbursed')->and($rows[1]->fresh()->status)->toBe('voided');
    expect(HonorariumActivity::where('activity_type', 'document_invalidated')->count())->toBe(2);
    $this->actingAs(User::find(3))->patchJson('/honorarium-ledger/'.$rows[0]->id, ['status' => 'claimed', 'event_date' => '2026-10-24'])->assertUnprocessable();
});
it('scopes recipient payment history by email and denies student access', function () {
    $d = verifiedLedgerDefense();
    $this->actingAs(User::find(2))->postJson('/honorarium-ledger/'.$d->id.'/prepare', ['school_year' => '2026-2027', 'semester' => '1st']);
    $this->actingAs(User::find(4))->get('/honorarium-ledger')->assertOk()->assertInertia(fn (Assert $p) => $p->has('records', 2)->where('canRecord', false));
    $this->actingAs(User::find(6))->get('/honorarium-ledger')->assertOk()->assertInertia(fn (Assert $p) => $p->has('records', 0));
    $this->actingAs(User::find(1))->get('/honorarium-ledger')->assertForbidden();
});
function integrationScheduleData(array $extra = []): array
{
    return $extra + ['scheduled_date' => '2026-10-26', 'scheduled_time' => '09:30', 'scheduled_end_time' => '10:30', 'defense_mode' => 'face-to-face', 'defense_venue' => 'Room B'];
}
function scheduleCandidate(array $extra = []): DefenseRequest
{
    if (! DB::table('panelists')->where('id', 2)->exists()) {
        DB::table('panelists')->insert(['id' => 2, 'name' => 'Other Panelist', 'email' => 'otherpanel@example.test']);
    }

    return integratedDefense($extra + ['defense_panelist1' => 2]);
}
it('blocks overlapping defenses for a shared panelist through the actual save endpoint', function () {
    $first = scheduleCandidate(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-26', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00', 'defense_venue' => 'Room A']);
    $other = scheduleCandidate();
    $this->actingAs(User::find(5))->postJson('/coordinator/defense-requests/'.$other->id.'/schedule-json', integrationScheduleData())->assertUnprocessable()->assertJsonValidationErrors('scheduled_time');
    expect($other->fresh()->scheduled_date)->toBeNull();
});
it('allows back to back defenses and preserves the existing request on rescheduling', function () {
    scheduleCandidate(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-26', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00', 'defense_venue' => 'Room A']);
    $other = scheduleCandidate();
    $this->actingAs(User::find(5))->postJson('/coordinator/defense-requests/'.$other->id.'/schedule-json', integrationScheduleData(['scheduled_time' => '10:00', 'scheduled_end_time' => '11:00']))->assertOk();
    expect($other->fresh()->workflow_state)->toBe('scheduled');
    $this->postJson('/coordinator/defense-requests/'.$other->id.'/schedule-json', integrationScheduleData(['scheduled_date' => '2026-10-27']))->assertOk();
    expect(DefenseRequest::count())->toBe(2)->and(collect($other->fresh()->workflow_history)->last()['action'])->toBe('rescheduled');
});
it('rejects duplicate panel positions and unassigned coordinators', function () {
    $d = scheduleCandidate();
    $this->actingAs(User::find(5))->postJson('/coordinator/defense-requests/'.$d->id.'/panels', ['defense_chairperson' => '1', 'defense_panelist1' => '1'])->assertUnprocessable();
    DB::table('users')->insert(['id' => 7, 'first_name' => 'Other', 'last_name' => 'Coordinator', 'role' => 'Coordinator', 'email' => 'othercoord@example.test', 'email_verified_at' => now()]);
    $this->actingAs(User::find(7))->postJson('/coordinator/defense-requests/'.$d->id.'/schedule-json', integrationScheduleData())->assertForbidden();
});
it('does not reschedule into the past in Philippine time', function () {
    $d = scheduleCandidate(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-26', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00']);
    $this->actingAs(User::find(5))->postJson('/coordinator/defense-requests/'.$d->id.'/schedule-json', integrationScheduleData(['scheduled_date' => '2026-10-23']))->assertUnprocessable()->assertJsonValidationErrors('scheduled_date');
});
it('checks room and student overlaps even when the panels differ', function () {
    $first = scheduleCandidate(['workflow_state' => 'scheduled', 'scheduled_date' => '2026-10-26', 'scheduled_time' => '09:00', 'scheduled_end_time' => '10:00', 'defense_venue' => 'Room B']);
    foreach ([3, 4] as $id) {
        DB::table('panelists')->insert(['id' => $id, 'name' => 'New Panel '.$id, 'email' => 'new'.$id.'@example.test']);
    }
    $other = scheduleCandidate(['defense_chairperson' => 3, 'defense_panelist1' => 4, 'adviser_user_id' => null, 'submitted_by' => null]);
    $this->actingAs(User::find(5))->postJson('/coordinator/defense-requests/'.$other->id.'/schedule-json', integrationScheduleData())->assertUnprocessable()->assertJsonValidationErrors('defense_venue');
    $other->update(['submitted_by' => 1]);
    $this->postJson('/coordinator/defense-requests/'.$other->id.'/schedule-json', integrationScheduleData(['defense_venue' => 'Room C']))->assertUnprocessable()->assertJsonValidationErrors('scheduled_time');
});
it('accepts namespaced panel choices from the actual coordinator screen', function () {
    $d = scheduleCandidate();
    $this->actingAs(User::find(5))->postJson('/coordinator/defense-requests/'.$d->id.'/panels', ['defense_chairperson' => 'panelist-1', 'defense_panelist1' => 'panelist-2'])->assertOk();
    expect((int) $d->fresh()->defense_chairperson)->toBe(1)->and((int) $d->fresh()->defense_panelist1)->toBe(2);
    $this->getJson('/coordinator/panel-members')->assertOk()->assertJsonFragment(['id' => 'panelist-1']);
});
it('prevents unassigned advisers and coordinators from advancing another request', function () {
    $d = integratedDefense();
    $this->actingAs(User::find(6))->patchJson('/adviser/defense-requirements/'.$d->id.'/adviser-status', ['adviser_status' => 'Approved'])->assertForbidden();
    DB::table('users')->insert(['id' => 7, 'role' => 'Coordinator', 'first_name' => 'Other', 'last_name' => 'Coordinator', 'email' => 'outside@example.test', 'email_verified_at' => now()]);
    $this->actingAs(User::find(7))->patchJson('/defense-requests/'.$d->id.'/status', ['status' => 'Completed'])->assertForbidden();
    expect($d->fresh()->workflow_state)->toBe('coordinator-approved');
});
it('resolves registered adviser choices to panel records without confusing user and panel IDs', function () {
    $d = scheduleCandidate();
    $this->actingAs(User::find(5))->postJson('/coordinator/defense-requests/'.$d->id.'/panels', ['defense_chairperson' => 'adviser-4', 'defense_panelist1' => 'panelist-2'])->assertOk();
    expect((int) $d->fresh()->defense_chairperson)->toBe(1)->and($d->fresh()->panel_display['defense_chairperson'])->toBe('Demo Person');
    $this->actingAs(User::find(1))->getJson('/api/defense-request/'.$d->id)->assertOk()->assertJsonPath('defense_chairperson', 'Demo Person');
});
it('uses the correct program level for honorarium rates', function ($program, $expected) {
    expect(ProgramLevel::getLevel($program))->toBe($expected);
})->with([
    ['Master in Business Administration', 'Masteral'],
    ['Master of Arts in Educational Management', 'Masteral'],
    ['Doctor in Business Management', 'Doctorate'],
    ['Ph.D. in Information Technology', 'Doctorate'],
    ['DBA', 'Doctorate'],
]);
