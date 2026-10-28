<?php

use App\Models\DefenseRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
    $this->withoutVite();
});

function attachmentFixture(): array
{
    $student = User::factory()->create(['role' => 'Student']);
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $coordinator = User::factory()->create(['role' => 'Coordinator']);
    $row = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
        'submitted_by' => $student->id, 'adviser_user_id' => $adviser->id, 'coordinator_user_id' => $coordinator->id,
        'first_name' => 'Attachment', 'last_name' => 'Student', 'school_id' => 'ATTACH-TEST',
        'program' => 'Master of Arts', 'thesis_title' => 'Attachment test', 'defense_type' => 'Proposal',
        'defense_adviser' => 'Adviser', 'workflow_state' => 'submitted', 'status' => 'Pending',
        'proof_of_payment' => 'defense_requirements/receipt.pdf',
    ]));
    Storage::disk('public')->put('defense_requirements/receipt.pdf', "%PDF-1.4\nAttachment test");

    return [$row, $student, $adviser, $coordinator];
}

test('assigned adviser student and coordinator can open the actual defense upload directory', function () {
    [, $student, $adviser, $coordinator] = attachmentFixture();
    foreach ([$student, $adviser, $coordinator] as $user) {
        $this->actingAs($user)->get('/storage/defense_requirements/receipt.pdf')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }
});

test('unrelated adviser and student cannot read another defense attachment', function () {
    attachmentFixture();
    foreach (['Adviser', 'Student', 'Coordinator'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))
            ->get('/storage/defense_requirements/receipt.pdf')->assertForbidden();
    }
});

test('guest cannot read a defense attachment', function () {
    attachmentFixture();
    $this->get('/storage/defense_requirements/receipt.pdf')->assertRedirect('/login');
});

test('old storage prefixes resolve but files not attached to any request do not', function () {
    [$row, , $adviser] = attachmentFixture();
    $row->update(['proof_of_payment' => '/storage/defense_requirements/receipt.pdf']);
    $this->actingAs($adviser)->get('/storage/defense_requirements/receipt.pdf')->assertOk();
    Storage::disk('public')->put('defense_requirements/unreferenced.pdf', 'Private file');
    $this->get('/storage/defense_requirements/unreferenced.pdf')->assertNotFound();
});

test('missing physical file returns not found instead of a misleading permission error', function () {
    [, , $adviser] = attachmentFixture();
    Storage::disk('public')->delete('defense_requirements/receipt.pdf');
    $this->actingAs($adviser)->get('/storage/defense_requirements/receipt.pdf')->assertNotFound();
});

test('adviser uploaded certificate and endorsement can be opened after submission', function () {
    [$row, $student, $adviser] = attachmentFixture();
    $pdf = "%PDF-1.4\nCertificate fixture";
    $this->actingAs($adviser)->post('/adviser/defense-requirements/'.$row->id.'/documents', [
        'ai_detection_certificate' => UploadedFile::fake()->createWithContent('certificate.pdf', $pdf),
        'endorsement_form' => UploadedFile::fake()->createWithContent('endorsement.pdf', $pdf),
    ], ['Accept' => 'application/json'])->assertOk();
    $row->refresh();
    foreach (['ai_detection_certificate', 'endorsement_form'] as $field) {
        $url = $row->$field;
        expect($url)->toStartWith('/storage/defense_documents/');
        $this->actingAs($adviser)->get($url)->assertOk();
        $this->actingAs($student)->get($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'Adviser']))->get($url)->assertForbidden();
    }
});

test('attached generated endorsements use the same ownership checks', function () {
    [$row, $student, $adviser] = attachmentFixture();
    Storage::disk('public')->put('generated/endorsement.pdf', "%PDF-1.4\nGenerated fixture");
    $row->update(['endorsement_form' => 'generated/endorsement.pdf']);
    $this->actingAs($adviser)->get('/storage/generated/endorsement.pdf')->assertOk();
    $this->actingAs($student)->get('/storage/generated/endorsement.pdf')->assertOk();
    $this->actingAs(User::factory()->create(['role' => 'Adviser']))->get('/storage/generated/endorsement.pdf')->assertForbidden();
});

test('every correction replacement can be opened by its student and assigned adviser', function () {
    [$row, $student, $adviser] = attachmentFixture();
    $row->update(['workflow_state' => 'adviser-corrections', 'correction_stage' => 'adviser']);
    $data = ['student_response' => 'Replaced all requested documents'];
    $fields = ['manuscript_proposal', 'similarity_index', 'rec_endorsement', 'proof_of_payment', 'avisee_adviser_attachment'];
    foreach ($fields as $field) {
        $data[$field] = UploadedFile::fake()->createWithContent($field.'.pdf', "%PDF-1.4\nCorrection replacement fixture");
    }
    $this->actingAs($student)->post('/defense-requirements/'.$row->id.'/resubmit', $data,
        ['Accept' => 'application/json'])->assertOk();
    $row->refresh();
    expect($row->workflow_state)->toBe('adviser-review');
    foreach ($fields as $field) {
        expect($row->$field)->toStartWith('defense_requirements/');
        $url = '/storage/'.$row->$field;
        $this->actingAs($student)->get($url)->assertOk();
        $this->actingAs($adviser)->get($url)->assertOk();
        $this->actingAs(User::factory()->create(['role' => 'Adviser']))->get($url)->assertForbidden();
    }
});

test('already saved correction files using the historical hyphen directory remain accessible only to authorized accounts', function () {
    [$row, $student, $adviser] = attachmentFixture();
    $row->update(['manuscript_proposal' => 'defense-requirements/revised.pdf']);
    Storage::disk('public')->put('defense-requirements/revised.pdf', "%PDF-1.4\nLegacy correction fixture");
    $url = '/storage/defense-requirements/revised.pdf';
    $this->actingAs($adviser)->get($url)->assertOk();
    $this->actingAs($student)->get($url)->assertOk();
    $this->actingAs(User::factory()->create(['role' => 'Student']))->get($url)->assertForbidden();
});

test('adviser upload retains the original document name for display and download', function () {
    [$row, , $adviser] = attachmentFixture();
    $this->actingAs($adviser)->post('/adviser/defense-requirements/'.$row->id.'/documents', [
        'endorsement_form' => UploadedFile::fake()->create('Proposal Endorsement.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
    ], ['Accept' => 'application/json'])->assertOk();
    $row->refresh();
    expect($row->attachment_names['endorsement_form'])->toBe('Proposal Endorsement.docx');
    expect(basename($row->endorsement_form))->not->toBe('Proposal Endorsement.docx');
    $response = $this->get($row->endorsement_form)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('Proposal Endorsement.docx');
});

test('correction replacement updates its original name while preserving the previous name in history', function () {
    [$row, $student, $adviser] = attachmentFixture();
    $row->update(['workflow_state' => 'adviser-corrections', 'manuscript_proposal' => 'defense_requirements/old.pdf',
        'attachment_original_names' => ['manuscript_proposal' => 'First Manuscript.pdf']]);
    $this->actingAs($student)->post('/defense-requirements/'.$row->id.'/resubmit', [
        'student_response' => 'Replaced the manuscript',
        'manuscript_proposal' => UploadedFile::fake()->createWithContent('Revised Manuscript.pdf', "%PDF-1.4\nOriginal name fixture"),
    ], ['Accept' => 'application/json'])->assertOk();
    $row->refresh();
    expect($row->attachment_names['manuscript_proposal'])->toBe('Revised Manuscript.pdf');
    $history = $row->workflow_history;
    $change = $history[array_key_last($history)]['attachments']['manuscript_proposal'];
    expect($change['previous_name'])->toBe('First Manuscript.pdf')->and($change['current_name'])->toBe('Revised Manuscript.pdf');
    $response = $this->actingAs($adviser)->get('/storage/'.$row->manuscript_proposal)->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('Revised Manuscript.pdf');
});

test('older attachment uses a readable fallback filename instead of its storage identifier', function () {
    [$row, , $adviser] = attachmentFixture();
    $response = $this->actingAs($adviser)->get('/storage/defense_requirements/receipt.pdf')->assertOk();
    expect($response->headers->get('Content-Disposition'))->toContain('Proof of Payment - Attachment test.pdf');
    expect($row->fresh()->attachment_names['proof_of_payment'])->toBe('Proof of Payment - Attachment test.pdf');
});

test('initial student submission records original filenames alongside randomized storage paths', function () {
    $student = User::factory()->create(['role' => 'Student', 'program' => 'Master of Arts', 'school_id' => 'NAME-TEST']);
    $adviser = User::factory()->create(['role' => 'Adviser']);
    $student->advisers()->attach($adviser->id, ['status' => 'accepted']);
    Mail::fake();
    $data = ['first_name' => $student->first_name, 'last_name' => $student->last_name,
        'school_id' => $student->school_id, 'program' => $student->program, 'thesis_title' => 'Original names fixture',
        'adviser_id' => $adviser->id, 'adviser' => $adviser->name, 'defense_type' => 'Proposal',
        'amount' => 100, 'reference_no' => 'TEST-OR-NAMES'];
    foreach (['manuscript_proposal', 'similarity_index', 'proof_of_payment', 'avisee_adviser_attachment'] as $field) {
        $data[$field] = UploadedFile::fake()->createWithContent($field.' Original.pdf', "%PDF-1.4\nInitial name fixture");
    }
    $this->actingAs($student)->post('/defense-requirements', $data)->assertSessionHasNoErrors();
    $row = DefenseRequest::where('submitted_by', $student->id)->firstOrFail();
    expect($row->attachment_original_names['manuscript_proposal'])->toBe('manuscript_proposal Original.pdf');
    $this->get('/student/documents')->assertInertia(fn ($page) => $page
        ->where('files.0.filename', 'manuscript_proposal Original.pdf'));
});
