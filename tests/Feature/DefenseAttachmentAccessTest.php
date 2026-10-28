<?php

use App\Models\DefenseRequest;
use App\Models\User;
use Illuminate\Http\UploadedFile;
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
