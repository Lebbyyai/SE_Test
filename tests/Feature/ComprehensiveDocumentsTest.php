<?php

use App\Models\ComprehensiveDocument;
use App\Models\CoordinatorProgram;
use App\Models\User;
use App\Services\ComprehensiveDocumentChecklist;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->withoutVite();
    Storage::fake('local');
    $this->student = User::factory()->create(['role' => 'Student', 'program' => 'Master of Arts']);
    $this->coordinator = User::factory()->create(['role' => 'Coordinator']);
    CoordinatorProgram::create(['coordinator_id' => $this->coordinator->id, 'program' => $this->student->program]);
    $this->base = '/comprehensive-documents/'.$this->student->id.'/tor';
});

it('lets students submit documents before applying without a portal session', function () {
    $this->actingAs($this->student)->get('/comprehensive-documents')->assertOk();
    $this->post($this->base.'/upload', ['version' => 0, 'file' => UploadedFile::fake()->create('Transcript.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
    $record = ComprehensiveDocument::firstOrFail();
    expect($record->status)->toBe('pending')->and($record->original_name)->toBe('Transcript.pdf');
    Storage::disk('local')->assertExists($record->path);
    $this->get($this->base.'/download')->assertOk()->assertDownload('Transcript.pdf');
    $this->assertDatabaseHas('notifications', ['user_id' => $this->coordinator->id, 'type' => 'comprehensive-document']);
});

it('blocks other students and Finance from private documents and requirement changes', function () {
    foreach (['Student', 'Finance', 'Adviser'] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]))->get($this->base.'/download')->assertForbidden();
        $this->patch($this->base.'/requirement', ['version' => 0, 'required' => true, 'instructions' => 'Please upload TOR'])->assertForbidden();
    }
});

it('scopes coordinator review to assigned programs', function () {
    $this->actingAs(User::factory()->create(['role' => 'Coordinator']))->patch($this->base.'/requirement', ['version' => 0, 'required' => true, 'instructions' => 'Please upload TOR'])->assertForbidden();
    $this->actingAs($this->coordinator)->patch($this->base.'/requirement', ['version' => 0, 'required' => true, 'instructions' => 'Please upload TOR'])->assertSessionHasNoErrors();
    expect(ComprehensiveDocument::first()->required)->toBeTrue();
    $this->assertDatabaseHas('notifications', ['user_id' => $this->student->id]);
});

it('allows AA review and gives the Dean read only access', function () {
    $this->actingAs(User::factory()->create(['role' => 'Administrative Assistant']))->patch($this->base.'/requirement', ['version' => 0, 'required' => true, 'instructions' => 'Submit official TOR'])->assertSessionHasNoErrors();
    $this->actingAs(User::factory()->create(['role' => 'Dean']))->get('/comprehensive-documents')->assertOk();
    $this->patch($this->base.'/requirement', ['version' => 1, 'required' => false, 'instructions' => 'Already held'])->assertForbidden();
});

it('requires feedback when returning a document and resets review on replacement', function () {
    $this->actingAs($this->student)->post($this->base.'/upload', ['version' => 0, 'file' => UploadedFile::fake()->create('Old.pdf', 10, 'application/pdf')]);
    $oldPath = ComprehensiveDocument::first()->path;
    $this->actingAs($this->coordinator)->patch($this->base.'/review', ['version' => 1, 'status' => 'returned'])->assertSessionHasErrors('remarks');
    $this->patch($this->base.'/review', ['version' => 1, 'status' => 'returned', 'remarks' => 'Please upload a readable copy'])->assertSessionHasNoErrors();
    $this->actingAs($this->student)->post($this->base.'/upload', ['version' => 2, 'file' => UploadedFile::fake()->create('Readable.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
    $record = ComprehensiveDocument::first();
    expect($record->status)->toBe('pending')->and($record->reviewed_by)->toBeNull()->and($record->remarks)->toBeNull()->and($record->history)->toHaveCount(3);
    Storage::disk('local')->assertMissing($oldPath);
});

it('rejects stale reviews without approving the replacement file', function () {
    $this->actingAs($this->student)->post($this->base.'/upload', ['version' => 0, 'file' => UploadedFile::fake()->create('TOR.pdf', 10, 'application/pdf')]);
    $this->actingAs($this->coordinator)->patch($this->base.'/review', ['version' => 0, 'status' => 'verified'])->assertSessionHasErrors('version');
    expect(ComprehensiveDocument::first()->status)->toBe('pending');
});

it('validates file types and sizes and never verifies an absent file', function () {
    $this->actingAs($this->student)->post($this->base.'/upload', ['version' => 0, 'file' => UploadedFile::fake()->create('program.exe', 10, 'application/octet-stream')])->assertSessionHasErrors('file');
    $this->post($this->base.'/upload', ['version' => 0, 'file' => UploadedFile::fake()->create('Large.pdf', 10241, 'application/pdf')])->assertSessionHasErrors('file');
    $this->actingAs($this->coordinator)->patch($this->base.'/review', ['version' => 0, 'status' => 'verified'])->assertUnprocessable();
    $this->assertDatabaseCount('comprehensive_documents', 0);
});

it('blocks eligibility for requested documents but never grants official clearance', function () {
    $record = ComprehensiveDocument::create(['student_id' => $this->student->id, 'type' => 'tor', 'required' => true, 'status' => 'pending']);
    $service = app(ComprehensiveDocumentChecklist::class);
    $eligible = ['eligible' => true, 'requirements' => [['name' => 'Complete documents submitted', 'completed' => true]]];
    expect($service->apply($eligible, $this->student)['eligible'])->toBeFalse();
    $record->update(['status' => 'verified']);
    expect($service->apply($eligible, $this->student)['eligible'])->toBeTrue();
    expect($service->apply(['eligible' => false, 'requirements' => []], $this->student)['eligible'])->toBeFalse();
});

it('keeps API eligibility blocked when documents are requested without official clearance', function () {
    ComprehensiveDocument::create(['student_id' => $this->student->id, 'type' => 'tor', 'required' => true, 'status' => 'missing']);
    $this->actingAs($this->student)->getJson('/api/comprehensive-exam/eligibility')->assertOk()->assertJsonPath('eligible', false)->assertJsonPath('requirements.1.completed', false);
});

it('rejects an unknown document type and missing file downloads', function () {
    $this->actingAs($this->student)->get($this->base.'/download')->assertNotFound();
    $this->post('/comprehensive-documents/'.$this->student->id.'/unknown/upload', ['version' => 0])->assertNotFound();
});
