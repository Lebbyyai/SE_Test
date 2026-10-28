<?php

use App\Models\AaPaymentVerification;
use App\Models\DefenseRequest;
use App\Models\DocumentTemplate;
use App\Models\Notification;
use App\Models\StudentRecord;
use App\Models\User;
use App\Services\DocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class ExtendedFeatureAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('public');
    }

    private function defense(): array
    {
        $student = User::factory()->create(['role' => 'Student']);
        $adviser = User::factory()->create(['role' => 'Adviser']);
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        $request = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
            'submitted_by' => $student->id, 'adviser_user_id' => $adviser->id, 'coordinator_user_id' => $coordinator->id,
            'first_name' => 'Audit', 'last_name' => 'Student', 'school_id' => 'EXTENDED-TEST', 'program' => 'Master of Arts',
            'thesis_title' => 'Isolated audit fixture', 'defense_type' => 'Proposal', 'defense_adviser' => 'Audit Adviser',
            'proof_of_payment' => 'receipt.pdf', 'status' => 'Pending', 'workflow_state' => 'submitted',
        ]));

        return [$request, $student, $adviser, $coordinator];
    }

    private function template(User $owner): DocumentTemplate
    {
        $pdf = new FPDF;
        $pdf->AddPage('L', [210, 297]);
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(80, 10, 'Audit template');
        Storage::disk('public')->put('templates/audit.pdf', $pdf->Output('S'));

        return DocumentTemplate::create(['name' => 'Audit Template', 'code' => 'audit-template', 'file_path' => 'templates/audit.pdf', 'version' => 1, 'created_by' => $owner->id, 'fields' => []]);
    }

    public function test_unrelated_adviser_cannot_open_another_request_details(): void
    {
        [$request] = $this->defense();
        $this->actingAs(User::factory()->create(['role' => 'Adviser']))->get('/adviser/defense-requirements/'.$request->id.'/details')->assertForbidden();
    }

    public function test_guest_cannot_replace_an_endorsement_link(): void
    {
        [$request] = $this->defense();
        $this->postJson('/adviser/defense-requirements/'.$request->id.'/endorsement-form', ['url' => '/storage/foreign.pdf'])->assertUnauthorized();
        $this->assertNull($request->fresh()->endorsement_form);
    }

    public function test_student_cannot_create_a_coordinator_adviser_registration(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Student']))->postJson('/api/coordinator/advisers', ['name' => 'Audit Adviser', 'email' => 'audit@example.test'])->assertForbidden();
        $this->assertDatabaseCount('advisers', 0);
    }

    public function test_coordinator_cannot_register_a_student_account_as_an_adviser(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $this->actingAs(User::factory()->create(['role' => 'Coordinator']))->postJson('/api/coordinator/advisers', ['name' => 'Audit Student', 'email' => $student->email])->assertUnprocessable();
        $this->assertDatabaseCount('advisers', 0);
    }

    public function test_student_cannot_attach_another_student_as_their_advisee(): void
    {
        $other = User::factory()->create(['role' => 'Student']);
        $this->actingAs(User::factory()->create(['role' => 'Student']))->postJson('/api/adviser/students', ['student_id' => $other->id])->assertForbidden();
        $this->assertDatabaseCount('adviser_student', 0);
    }

    public function test_adviser_cannot_attach_a_dean_as_a_student(): void
    {
        $dean = User::factory()->create(['role' => 'Dean']);
        $this->actingAs(User::factory()->create(['role' => 'Adviser']))->postJson('/api/adviser/students', ['student_id' => $dean->id])->assertUnprocessable();
    }

    public function test_student_cannot_modify_honorarium_specs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Student']))->putJson('/panelists/honorarium-specs', ['specs' => [['role' => 'Chairperson', 'defense_type' => 'Proposal', 'amount' => 999]]])->assertForbidden();
    }

    public function test_student_cannot_browse_or_delete_other_student_records(): void
    {
        $record = StudentRecord::create(['first_name' => 'Private', 'last_name' => 'Student', 'program' => 'Master of Arts']);
        $this->actingAs(User::factory()->create(['role' => 'Student']))->get('/student-records')->assertForbidden();
        $this->delete('/student-records/'.$record->id)->assertForbidden();
        $this->assertDatabaseHas('student_records', ['id' => $record->id]);
    }

    public function test_student_cannot_bulk_verify_payments(): void
    {
        [$request] = $this->defense();
        $verification = AaPaymentVerification::create(['defense_request_id' => $request->id, 'status' => 'pending']);
        $this->actingAs(User::factory()->create(['role' => 'Student']))->postJson('/aa/payment-verifications/bulk-update', ['verification_ids' => [$verification->id], 'status' => 'verified'])->assertForbidden();
        $this->assertSame('pending', $verification->fresh()->status);
    }

    public function test_assistant_cannot_bulk_verify_another_assistants_payments(): void
    {
        [$request] = $this->defense();
        $owner = User::factory()->create(['role' => 'Administrative Assistant']);
        $verification = AaPaymentVerification::create(['defense_request_id' => $request->id, 'assigned_to' => $owner->id, 'status' => 'pending']);
        $this->actingAs(User::factory()->create(['role' => 'Administrative Assistant']))->postJson('/aa/payment-verifications/bulk-update', ['verification_ids' => [$verification->id], 'status' => 'verified'])->assertForbidden();
        $this->assertSame('pending', $verification->fresh()->status);
    }

    public function test_assistant_payment_verification_page_loads(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Administrative Assistant']))->get('/aa/payment-verifications')->assertOk();
    }

    public function test_document_dialog_gets_json_and_an_authorized_preview_url(): void
    {
        [$request, $student, $adviser] = $this->defense();
        $template = $this->template($adviser);
        $response = $this->actingAs($adviser)->postJson('/api/generate-document', ['template_id' => $template->id, 'defense_request_id' => $request->id, 'fields' => []]);
        $response->assertOk()->assertJsonPath('ok', true);
        $this->get($response->json('download_url'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_generated_pdf_keeps_template_page_dimensions(): void
    {
        [$request, $student, $adviser] = $this->defense();
        $generated = app(DocumentGenerator::class)->generate($this->template($adviser), $request);
        $pdf = new Fpdi;
        $pdf->setSourceFile(Storage::disk('public')->path($generated->output_path));
        $size = $pdf->getTemplateSize($pdf->importPage(1));
        $this->assertEqualsWithDelta(297, $size['width'], 0.1);
        $this->assertEqualsWithDelta(210, $size['height'], 0.1);
    }

    public function test_failed_pdf_replacement_keeps_the_previous_document(): void
    {
        [$request, $student, $adviser] = $this->defense();
        $template = $this->template($adviser);
        $generator = app(DocumentGenerator::class);
        $previous = $generator->generate($template, $request);
        $disk = Storage::disk('public');
        $faulty = Mockery::mock($disk);
        $faulty->shouldReceive('put')->andReturnFalse();
        Storage::shouldReceive('disk')->with('public')->andReturn($faulty);
        try {
            $generator->generate($template, $request);
        } catch (Throwable $error) { /* Simulate full disk. */
        }
        $this->assertDatabaseHas('generated_documents', ['id' => $previous->id]);
        $this->assertTrue($disk->exists($previous->output_path));
    }

    public function test_sample_email_route_does_not_send_to_a_hardcoded_recipient(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'Student']))->get('/send-mail');
        Mail::assertNothingSent();
    }

    public function test_schedule_calendar_handles_stored_time_strings_and_enforces_staff_access(): void
    {
        [$request, $student, $adviser, $coordinator] = $this->defense();
        $request->update(['scheduled_date' => '2026-11-12', 'scheduled_time' => '09:30:00', 'workflow_state' => 'scheduled']);
        $this->actingAs($coordinator)->getJson('/coordinator/schedule/calendar')->assertOk()->assertJsonFragment(['scheduled_time' => '09:30']);
        $this->actingAs($student)->getJson('/coordinator/schedule/calendar')->assertForbidden();
    }

    public function test_student_documents_use_the_public_disk_and_include_endorsements(): void
    {
        [$request, $student] = $this->defense();
        Storage::disk('public')->put('defense_requirements/manuscript.pdf', 'test-document');
        Storage::disk('public')->put('defense_documents/endorsement.pdf', 'endorsed');
        $request->update(['manuscript_proposal' => 'defense_requirements/manuscript.pdf', 'endorsement_form' => '/storage/defense_documents/endorsement.pdf']);
        $this->actingAs($student)->get('/student/documents')->assertOk()->assertInertia(fn ($page) => $page
            ->where('files', fn ($files) => collect($files)->contains(fn ($file) => $file['field'] === 'manuscript_proposal' && $file['size'] === 13)
                && collect($files)->contains(fn ($file) => $file['field'] === 'endorsement_form' && $file['size'] === 8)));
    }

    public function test_invalid_mapping_cannot_save_zero_canvas_dimensions(): void
    {
        $dean = User::factory()->create(['role' => 'Dean']);
        $template = $this->template($dean);
        $this->actingAs($dean)->putJson('/api/document-templates/'.$template->id.'/fields', ['fields' => [], 'fields_meta' => ['canvas_width' => 0, 'canvas_height' => 842]])
            ->assertUnprocessable()->assertJsonValidationErrors('fields_meta.canvas_width');
        $this->assertNull($template->fresh()->fields_meta);
    }

    public function test_template_upload_counts_real_pdf_pages_and_rejects_broken_pdf(): void
    {
        $dean = User::factory()->create(['role' => 'Dean']);
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->AddPage();
        $upload = UploadedFile::fake()->createWithContent('two-pages.pdf', $pdf->Output('S'));
        $this->actingAs($dean)->postJson('/api/document-templates', ['name' => 'Two pages', 'file' => $upload])->assertOk()->assertJsonPath('page_count', 2);
        $this->postJson('/api/document-templates', ['name' => 'Broken', 'file' => UploadedFile::fake()->createWithContent('broken.pdf', '%PDF-1.4 broken')])->assertUnprocessable();
        $this->assertDatabaseCount('document_templates', 1);
    }

    public function test_regenerating_an_attached_endorsement_keeps_the_link_valid(): void
    {
        [$request, $student, $adviser] = $this->defense();
        $template = $this->template($adviser);
        $generator = app(DocumentGenerator::class);
        $first = $generator->generate($template, $request);
        $request->update(['endorsement_form' => '/storage/'.$first->output_path]);
        $second = $generator->generate($template, $request);
        $this->assertNotSame($first->output_path, $second->output_path);
        $this->assertSame($second->output_path, $request->fresh()->endorsement_form);
        Storage::disk('public')->assertExists($second->output_path);
        $this->assertIsArray($second->payload);
    }

    public function test_assistant_can_process_own_verifications_and_export_well_formed_batches(): void
    {
        [$request] = $this->defense();
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $verification = AaPaymentVerification::create(['defense_request_id' => $request->id, 'assigned_to' => $assistant->id, 'status' => 'pending']);
        $this->actingAs($assistant)->postJson('/aa/payment-verifications/'.$verification->id.'/status', ['status' => 'verified', 'remarks' => "=SUM(1,2)\nQuoted \"text\""])->assertOk();
        $batch = $this->postJson('/aa/payment-verifications/batch', ['name' => 'Audit batch', 'verification_ids' => [$verification->id]])->assertCreated()->json('batch.id');
        $csv = $this->get('/aa/payment-batch/'.$batch.'/export')->assertOk()->streamedContent();
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        fgetcsv($stream, 0, ',', '"', '');
        $row = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);
        $this->assertSame('verified', $row[2]);
        $this->assertStringStartsWith("'=SUM", $row[3]);
        $this->actingAs(User::factory()->create(['role' => 'Administrative Assistant']))->get('/aa/payment-batch/'.$batch.'/export')->assertForbidden();
    }

    public function test_mixed_owner_bulk_verification_is_atomic(): void
    {
        [$request] = $this->defense();
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $other = User::factory()->create(['role' => 'Administrative Assistant']);
        $own = AaPaymentVerification::create(['defense_request_id' => $request->id, 'assigned_to' => $assistant->id, 'status' => 'pending']);
        $foreign = AaPaymentVerification::create(['defense_request_id' => $request->id, 'assigned_to' => $other->id, 'status' => 'pending']);
        $this->actingAs($assistant)->postJson('/aa/payment-verifications/bulk-update', ['verification_ids' => [$own->id, $foreign->id], 'status' => 'verified'])->assertForbidden();
        $this->assertSame('pending', $own->fresh()->status);
        $this->assertSame('pending', $foreign->fresh()->status);
    }

    public function test_direct_registration_cannot_create_a_second_active_adviser(): void
    {
        [$request, $student, $adviser] = $this->defense();
        $adviser->advisedStudents()->attach($student->id, ['status' => 'accepted']);
        $this->actingAs(User::factory()->create(['role' => 'Adviser']))->postJson('/api/adviser/students', ['student_id' => $student->id])->assertConflict();
        $this->assertDatabaseCount('adviser_student', 1);
    }

    public function test_staff_legacy_report_and_rate_links_reach_existing_pages(): void
    {
        $dean = User::factory()->create(['role' => 'Dean']);
        $this->actingAs($dean)->get('/generate-report')->assertRedirect('/honorarium-summary');
        $this->get('/panelists/honorarium-specs')->assertRedirect('/dean/payment-rates');
        $this->putJson('/panelists/honorarium-specs', ['specs' => []])->assertGone();
        $this->get('/profile')->assertOk()->assertInertia(fn ($page) => $page->component('settings/profile'));
    }

    public function test_empty_template_mappings_can_be_saved_with_valid_dimensions(): void
    {
        $dean = User::factory()->create(['role' => 'Dean']);
        $template = $this->template($dean);
        $this->actingAs($dean)->putJson('/api/document-templates/'.$template->id.'/fields', ['fields' => [], 'fields_meta' => ['canvas_width' => 595, 'canvas_height' => 842]])->assertOk();
        $this->assertSame([], $template->fresh()->fields);
    }

    public function test_endorsement_link_accepts_only_a_generated_file_for_the_same_request(): void
    {
        [$request, $student, $adviser] = $this->defense();
        $document = app(DocumentGenerator::class)->generate($this->template($adviser), $request);
        $url = '/adviser/defense-requirements/'.$request->id.'/endorsement-form';
        $this->actingAs($adviser)->postJson($url, ['url' => '/storage/not-owned.pdf'])->assertUnprocessable();
        $this->postJson($url, ['url' => '/storage/'.$document->output_path])->assertOk();
        $this->assertSame($document->output_path, $request->fresh()->endorsement_form);
        $this->actingAs($student)->postJson($url, ['url' => '/storage/'.$document->output_path])->assertForbidden();
    }

    public function test_reading_notifications_and_marking_read_cannot_change_another_users_notices(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $other = User::factory()->create(['role' => 'Student']);
        $notice = Notification::create(['user_id' => $other->id, 'type' => 'test', 'title' => 'Private notice', 'message' => 'Private content', 'read' => false]);
        $this->actingAs($student)->getJson('/notifications/feed')->assertOk()->assertJsonMissing(['title' => 'Private notice']);
        $this->patchJson('/notifications/'.$notice->id.'/read')->assertForbidden();
        $this->assertFalse((bool) $notice->fresh()->read);
    }

    public function test_coordinator_queue_does_not_expose_other_coordinators_requests(): void
    {
        [$request, $student, $adviser, $coordinator] = $this->defense();
        $request->update(['workflow_state' => 'adviser-approved']);
        $other = User::factory()->create(['role' => 'Coordinator']);
        $this->actingAs($other)->getJson('/api/coordinator/defense-requests')->assertOk()->assertJsonMissing(['id' => $request->id]);
        $this->actingAs($coordinator)->getJson('/api/coordinator/defense-requests')->assertOk()->assertJsonFragment(['id' => $request->id]);
    }

    public function test_missing_generation_template_returns_an_actionable_validation_error(): void
    {
        [$request, , $adviser] = $this->defense();
        $template = $this->template($adviser);
        Storage::disk('public')->delete($template->file_path);
        $this->actingAs($adviser)->postJson('/api/generate-document', [
            'template_id' => $template->id, 'defense_request_id' => $request->id, 'fields' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors('template_id');
        $this->assertDatabaseCount('generated_documents', 0);
    }
}
