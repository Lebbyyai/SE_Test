<?php

use App\Models\CoordinatorProgram;
use App\Models\DefenseCertificateRequest;
use App\Models\DefenseRequest;
use App\Models\ExamApplication;
use App\Models\FinanceDocumentSubmission;
use App\Models\HonorariumDisbursement;
use App\Models\Panelist;
use App\Models\PaymentRate;
use App\Models\PaymentSubmission;
use App\Models\User;
use App\Services\ComprehensiveEligibilityService;
use App\Services\DefenseSubmissionEligibility;
use App\Services\HonorariumAccess;
use App\Services\HonorariumLedgerService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->student = User::factory()->create(['role' => 'Student', 'program' => 'Master of Arts', 'school_id' => 'WF-STUDENT']);
    $this->aa = User::factory()->create(['role' => 'Administrative Assistant']);
    $this->coordinator = User::factory()->create(['role' => 'Coordinator']);
    CoordinatorProgram::create(['coordinator_id' => $this->coordinator->id, 'program' => $this->student->program]);
    $this->faculty = User::factory()->create(['role' => 'Adviser']);
    $this->registrar = User::factory()->create(['role' => 'Registrar']);
});

it('connects a passed exam to a proposal defense, certificate and honorarium', function () {
    Mail::fake();
    Storage::fake('public');
    expect(app(DefenseSubmissionEligibility::class)->forStudent($this->student)['comprehensive_passed'])->toBeFalse();
    $this->actingAs($this->student)->post('/payment', [])->assertSessionHasErrors('form');
    $this->mock(ComprehensiveEligibilityService::class)->shouldReceive('check')->andReturn(['eligible' => true]);
    $this->post('/comprehensive-exam', [
        'schoolYear' => '2026-2027', 'mobileNo' => '09123456789',
        'subjects' => [['subject' => 'Research Methods', 'date' => today()->addDay()->toDateString(), 'startTime' => '09:00', 'endTime' => '11:00']],
    ])->assertSessionHasNoErrors()->assertRedirect('/comprehensive-exam');
    $application = ExamApplication::sole();
    $this->actingAs($this->registrar)->post('/registrar/comprehensive-exam/'.$application->application_id.'/approve')->assertRedirect('/dashboard');
    $this->actingAs($this->aa)->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertOk();
    expect(app(DefenseSubmissionEligibility::class)->forStudent($this->student)['comprehensive_passed'])->toBeFalse();
    $this->actingAs($this->student)->post('/payment', [
        'or_number' => 'DEMO-OR-1', 'payment_date' => today()->toDateString(), 'amount_paid' => 3120,
        'receipt_image' => UploadedFile::fake()->create('Exam Receipt.pdf', 20, 'application/pdf'),
    ])->assertSessionHasNoErrors()->assertRedirect('/payment');
    $receipt = PaymentSubmission::sole();
    expect((int) $receipt->exam_application_id)->toBe($application->application_id);
    $this->get('/comprehensive-receipts/'.$receipt->payment_id)->assertOk()->assertDownload('Exam Receipt - DEMO-OR-1.pdf');
    $this->actingAs($this->aa)->post('/coordinator/compre-payment/'.$receipt->payment_id.'/approve')->assertSessionHasNoErrors();
    $this->post('/coordinator/compre-payment/'.$receipt->payment_id.'/exam-permit', ['decision' => 'allowed'])->assertSessionHasNoErrors();
    $proctor = User::factory()->create(['role' => 'Proctor']);
    $this->actingAs($this->coordinator)->postJson('/coordinator/compre-schedule', [
        'student_id' => $this->student->id, 'program' => $this->student->program, 'date' => today()->addDay()->toDateString(),
        'time' => '09:00', 'venue' => 'Exam Room 1', 'proctor_ids' => [$proctor->id], 'teacher_assigned' => 'Exam Teacher',
    ])->assertCreated();
    $report = $this->actingAs(User::factory()->create(['role' => 'Finance']))->get('/comprehensive-exam/assignments.csv')
        ->assertOk()->assertDownload('comprehensive-exam-assignments.csv');
    expect($report->streamedContent())->toContain('Exam Teacher', 'Assignment only.');
    expect(app(DefenseSubmissionEligibility::class)->forStudent($this->student)['comprehensive_passed'])->toBeFalse();
    $this->travel(2)->days();
    $this->actingAs($this->coordinator)->patch('/academic-progress/'.$this->student->id.'/comprehensive-result', ['result' => 'passed', 'exam_date' => today()->subDay()->toDateString(), 'record_reference' => 'DEMO exam results sheet', 'verified_against_school_records' => true, 'version' => 0])->assertSessionHasNoErrors();
    expect(app(DefenseSubmissionEligibility::class)->forStudent($this->student)['stages']['Proposal']['allowed'])->toBeTrue();
    expect(app(DefenseSubmissionEligibility::class)->forStudent($this->student)['stages']['Prefinal']['allowed'])->toBeFalse();

    $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF");
    $this->student->advisers()->attach($this->faculty->id, ['status' => 'accepted', 'requested_by' => $this->coordinator->id]);
    $this->actingAs($this->student)->post('/defense-requirements', [
        'first_name' => $this->student->first_name,
        'last_name' => $this->student->last_name,
        'school_id' => $this->student->school_id,
        'program' => $this->student->program,
        'thesis_title' => 'Integrated proposal',
        'adviser_id' => $this->faculty->id,
        'adviser' => $this->faculty->name,
        'defense_type' => 'Proposal',
        'amount' => 11250,
        'reference_no' => 'DEFENSE-OR-1',
        'manuscript_proposal' => $pdf('Proposal.pdf'),
        'similarity_index' => $pdf('Similarity.pdf'),
        'proof_of_payment' => $pdf('Defense Receipt.pdf'),
        'avisee_adviser_attachment' => $pdf('Adviser Agreement.pdf'),
    ])->assertSessionHasNoErrors();
    $defense = DefenseRequest::where('submitted_by', $this->student->id)->sole();
    $adviserUrl = '/adviser/defense-requirements/'.$defense->id;
    $this->actingAs($this->faculty)->postJson($adviserUrl.'/documents', [
        'endorsement_form' => $pdf('Endorsement.pdf'),
        'ai_detection_certificate' => $pdf('AI Detection.pdf'),
    ])->assertOk();
    $this->patchJson($adviserUrl.'/adviser-status', ['adviser_status' => 'Approved', 'coordinator_user_id' => $this->coordinator->id])->assertOk();
    $this->actingAs($this->coordinator)->patchJson('/defense-requests/'.$defense->id.'/status', ['status' => 'Approved'])->assertOk();
    $chair = Panelist::create(['name' => 'Workflow Chair', 'email' => 'chair@example.test', 'status' => 'Not Assigned']);
    $member = Panelist::create(['name' => 'Workflow Member', 'email' => 'member@example.test', 'status' => 'Not Assigned']);
    $date = now('Asia/Manila')->addDay()->toDateString();
    $this->postJson('/coordinator/defense-requests/'.$defense->id.'/schedule-json', [
        'scheduled_date' => $date,
        'scheduled_time' => '08:00',
        'scheduled_end_time' => '10:00',
        'defense_mode' => 'online',
        'defense_venue' => 'Test meeting',
        'defense_chairperson' => (string) $chair->id,
        'defense_panelist1' => (string) $member->id,
    ])->assertOk();
    expect($defense->fresh()->workflow_state)->toBe('scheduled');

    $this->travelTo(Carbon::parse($date.' 11:00', 'Asia/Manila'));
    $this->put('/defense-outcomes/'.$defense->id, [
        'version' => 0, 'completion' => 'done', 'result' => 'passed',
        'subject' => 'Thesis 1', 'completed_on' => $date, 'next_stage' => 'Prefinal',
    ])->assertSessionHasNoErrors()->assertRedirect();
    $eligibility = app(DefenseSubmissionEligibility::class)->forStudent($this->student);
    expect($eligibility['stages']['Prefinal']['allowed'])->toBeTrue()
        ->and($eligibility['stages']['Final']['allowed'])->toBeFalse();

    $this->actingAs($this->student)->post('/certificates/request/'.$defense->id)->assertSessionHasNoErrors()->assertRedirect();
    $certificate = DefenseCertificateRequest::where('defense_request_id', $defense->id)->sole();
    $this->actingAs($this->aa)->post('/certificates/'.$certificate->id.'/release', [
        'pdf' => $pdf('Certificate.pdf'), 'email' => false,
    ])->assertSessionHasNoErrors()->assertRedirect();
    $this->actingAs($this->student)->get('/certificates/'.$certificate->id.'/download')->assertOk()
        ->assertDownload('Defense-Certificate-'.$defense->id.'.pdf');

    $participants = array_map(fn ($person) => [
        'recipient_key' => $person['recipient_key'],
        'affiliation' => $person['recipient_role'] === 'Adviser' ? null : 'internal',
        'attendance' => 'rendered',
    ], app(HonorariumLedgerService::class)->recipients($defense->fresh()));
    $panelists = array_values(array_filter($participants, fn ($person) => str_starts_with($person['recipient_key'], 'panel:')));
    $this->actingAs($this->coordinator)->patchJson('/defense-register/'.$defense->id.'/panel-types', [
        'participants' => $panelists,
    ])->assertOk();

    $finance = User::factory()->create(['role' => 'Finance']);
    $handoff = '/honorarium-documents/'.$defense->id;
    $documents = FinanceDocumentSubmission::where('defense_request_id', $defense->id);
    $this->actingAs($this->aa)->post($handoff.'/submit-one')->assertSessionHasNoErrors()->assertRedirect();
    $this->postJson($handoff.'/submit-two')->assertUnprocessable();
    $receiptUrl = app(HonorariumAccess::class)->receiptUrl($defense->proof_of_payment, $defense);
    $this->actingAs($finance)->get($receiptUrl)->assertOk()
        ->assertHeader('Content-Disposition', 'inline; filename="Defense Receipt.pdf"');
    $receipt = (clone $documents)->where('document_type', 'payment_receipt')->sole();
    $this->post('/honorarium-documents/finance/'.$receipt->id.'/review', ['status' => 'verified'])->assertSessionHasNoErrors()->assertRedirect();

    $this->travel(1)->days();
    $this->actingAs($this->aa)->post($handoff.'/submit-two')->assertSessionHasNoErrors()->assertRedirect();
    $breakdown = (clone $documents)->where('document_type', 'panel_breakdown')->sole();
    $this->actingAs($finance)->post('/honorarium-documents/finance/'.$breakdown->id.'/review', ['status' => 'verified'])->assertSessionHasNoErrors()->assertRedirect();
    expect($documents->where('status', 'verified')->count())->toBe(2);

    $ledger = '/honorarium-ledger/'.$defense->id.'/prepare';
    $term = ['school_year' => '2026-2027', 'semester' => '1st'];
    $this->actingAs($this->aa)->postJson($ledger, $term)->assertUnprocessable()->assertJsonValidationErrors('service');
    $this->postJson('/defense-register/'.$defense->id.'/confirm-service', [
        'held' => true, 'completed_on' => $date, 'participants' => $participants,
    ])->assertOk();
    foreach (['Panel Chair' => 1500, 'Panel Member 1' => 600, 'Adviser' => 1000] as $type => $amount) {
        PaymentRate::updateOrCreate(['program_level' => 'Masteral', 'defense_type' => 'Proposal', 'type' => $type], ['amount' => $amount]);
    }
    $this->postJson($ledger, $term)->assertOk();
    $payments = HonorariumDisbursement::where('defense_request_id', $defense->id)->get();
    expect($payments)->toHaveCount(3)->and((float) $payments->sum('amount'))->toBe(3100.0);

    $payment = '/honorarium-ledger/'.$payments->first()->id;
    $this->actingAs($finance)->patchJson($payment, ['status' => 'approved'])->assertOk();
    $this->patchJson($payment, ['status' => 'disbursed', 'event_date' => $date, 'payment_reference' => 'TEST-PAYOUT'])->assertOk();
    $this->patchJson($payment, ['status' => 'claimed', 'event_date' => $date])->assertOk();
    expect($payments->first()->fresh()->status)->toBe('claimed');

    foreach (['Prefinal' => [12780, 'Thesis 1', 'Final'], 'Final' => [6000, 'Thesis 2', null]] as $stage => [$fee, $subject, $nextStage]) {
        $this->actingAs($this->student)->post('/defense-requirements', [
            'first_name' => $this->student->first_name,
            'last_name' => $this->student->last_name,
            'school_id' => $this->student->school_id,
            'program' => $this->student->program,
            'thesis_title' => 'Integrated proposal',
            'adviser_id' => $this->faculty->id,
            'adviser' => $this->faculty->name,
            'defense_type' => $stage,
            'amount' => $fee,
            'reference_no' => 'OR-'.$stage,
            'manuscript_proposal' => $pdf($stage.' Manuscript.pdf'),
            'similarity_index' => $pdf('Similarity.pdf'),
            'proof_of_payment' => $pdf('Defense Receipt.pdf'),
            'rec_endorsement' => $pdf('REC Endorsement.pdf'),
        ])->assertSessionHasNoErrors();
        $request = DefenseRequest::where('submitted_by', $this->student->id)->where('defense_type', $stage)->sole();
        $adviserUrl = '/adviser/defense-requirements/'.$request->id;
        $this->actingAs($this->faculty)->postJson($adviserUrl.'/documents', [
            'endorsement_form' => $pdf('Endorsement.pdf'),
            'ai_detection_certificate' => $pdf('AI Detection.pdf'),
        ])->assertOk();
        $this->patchJson($adviserUrl.'/adviser-status', ['adviser_status' => 'Approved', 'coordinator_user_id' => $this->coordinator->id])->assertOk();
        $this->actingAs($this->coordinator)->patchJson('/defense-requests/'.$request->id.'/status', ['status' => 'Approved'])->assertOk();
        $date = now('Asia/Manila')->addDay()->toDateString();
        $this->postJson('/coordinator/defense-requests/'.$request->id.'/schedule-json', [
            'scheduled_date' => $date,
            'scheduled_time' => '10:00',
            'scheduled_end_time' => '12:00',
            'defense_mode' => 'online',
            'defense_venue' => 'Test meeting',
        ])->assertOk();
        expect($request->fresh()->defense_chairperson)->toBe($defense->fresh()->defense_chairperson)
            ->and($request->fresh()->defense_panelist1)->toBe($defense->fresh()->defense_panelist1);

        $this->travelTo(Carbon::parse($date.' 13:00', 'Asia/Manila'));
        $this->put('/defense-outcomes/'.$request->id, [
            'version' => 0, 'completion' => 'done', 'result' => 'passed',
            'subject' => $subject, 'completed_on' => $date, 'next_stage' => $nextStage,
        ])->assertSessionHasNoErrors();
        if ($nextStage) {
            expect(app(DefenseSubmissionEligibility::class)->forStudent($this->student)['stages'][$nextStage]['allowed'])->toBeTrue();
        }
    }

});
