<?php

use App\Models\Adviser;
use App\Models\DefenseRequest;
use App\Models\DocumentTemplate;
use App\Models\Panelist;
use App\Models\PaymentSubmission;
use App\Models\User;
use App\Models\UserSignature;
use App\Services\DocumentGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\Fpdi;
use Tests\TestCase;

class ManuscriptFeatureRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
        Storage::fake('public');
    }

    private function panelData(): array
    {
        return ['name' => 'Audit Panelist', 'email' => 'audit-panel@example.test', 'role' => 'Panel Member', 'status' => 'Not Assigned'];
    }

    public function test_coordinator_can_add_edit_search_and_delete_a_panelist(): void
    {
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        $this->actingAs($coordinator)->post('/panelists', $this->panelData())->assertRedirect();
        $panel = Panelist::where('email', 'audit-panel@example.test')->firstOrFail();
        $this->getJson('/api/panelists')->assertOk()->assertJsonFragment(['name' => 'Audit Panelist']);
        $data = $this->panelData();
        $data['name'] = 'Revised Panelist';
        $this->put('/panelists/'.$panel->id, $data)->assertRedirect();
        $this->assertDatabaseHas('panelists', ['id' => $panel->id, 'name' => 'Revised Panelist']);
        $this->delete('/panelists/'.$panel->id)->assertRedirect();
        $this->assertDatabaseMissing('panelists', ['id' => $panel->id]);
    }

    public function test_student_cannot_maintain_panelist_records(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $response = $this->actingAs($student)->postJson('/panelists', $this->panelData());
        $this->assertDatabaseMissing('panelists', ['email' => 'audit-panel@example.test']);
        $response->assertForbidden();
    }

    public function test_coordinator_can_register_edit_and_remove_an_unlinked_adviser(): void
    {
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        $this->actingAs($coordinator)->postJson('/api/coordinator/advisers', ['name' => 'Audit Adviser', 'email' => 'unlinked@example.test'])->assertOk();
        $adviser = Adviser::where('email', 'unlinked@example.test')->firstOrFail();
        $this->assertSame('inactive', $adviser->status);
        $this->putJson('/api/coordinator/advisers/'.$adviser->id, ['name' => 'Revised Adviser', 'email' => 'revised@example.test'])->assertOk();
        $this->assertDatabaseHas('advisers', ['id' => $adviser->id, 'first_name' => 'Revised', 'email' => 'revised@example.test']);
        $this->deleteJson('/api/coordinator/advisers/'.$adviser->id)->assertOk();
        $this->assertDatabaseMissing('advisers', ['id' => $adviser->id]);
    }

    public function test_deleting_one_coordinator_adviser_record_keeps_another_coordinator_student_connection(): void
    {
        $first = User::factory()->create(['role' => 'Coordinator']);
        $second = User::factory()->create(['role' => 'Coordinator']);
        $adviser = User::factory()->create(['role' => 'Adviser']);
        $student = User::factory()->create(['role' => 'Student']);
        $data = ['user_id' => $adviser->id, 'first_name' => 'Audit', 'last_name' => 'Adviser', 'email' => $adviser->email, 'status' => 'active'];
        $own = Adviser::create($data + ['coordinator_id' => $first->id]);
        Adviser::create($data + ['coordinator_id' => $second->id]);
        $adviser->advisedStudents()->attach($student->id, ['status' => 'accepted', 'requested_by' => $second->id]);
        $this->actingAs($first)->deleteJson('/api/coordinator/advisers/'.$own->id)->assertOk();
        $this->assertDatabaseHas('adviser_student', ['adviser_id' => $adviser->id, 'student_id' => $student->id, 'requested_by' => $second->id]);
    }

    public function test_adviser_code_respects_disabled_auto_accept(): void
    {
        $adviser = User::factory()->create(['role' => 'Adviser', 'adviser_code' => 'AUDIT123']);
        $student = User::factory()->create(['role' => 'Student']);
        DB::table('adviser_settings')->updateOrInsert(['adviser_id' => $adviser->id], ['auto_accept_students' => false]);
        $this->actingAs($student)->postJson('/api/adviser/register-with-code', ['adviser_code' => 'AUDIT123'])->assertOk();
        $this->assertDatabaseHas('adviser_student', ['adviser_id' => $adviser->id, 'student_id' => $student->id, 'status' => 'pending']);
    }

    public function test_dean_can_upload_a_pdf_template_and_student_cannot(): void
    {
        $dean = User::factory()->create(['role' => 'Dean']);
        $file = UploadedFile::fake()->createWithContent('template.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        $this->actingAs($dean)->postJson('/api/document-templates', ['name' => 'Endorsement Form (Proposal)', 'file' => $file])->assertOk();
        $template = DocumentTemplate::firstOrFail();
        Storage::disk('public')->assertExists($template->file_path);
        $student = User::factory()->create(['role' => 'Student']);
        $this->actingAs($student)->postJson('/api/document-templates', ['name' => 'Other', 'file' => $file])->assertForbidden();
    }

    public function test_pdf_generator_dependencies_load(): void
    {
        try {
            $pdf = new Fpdi;
            $this->assertIsObject($pdf);
        } catch (Throwable $error) {
            $this->fail('PDF generator cannot start: '.$error->getMessage());
        }
    }

    public function test_user_can_save_a_drawn_signature(): void
    {
        $user = User::factory()->create(['role' => 'Dean']);
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jR3sAAAAASUVORK5CYII=';
        $this->actingAs($user)->postJson('/api/signatures', ['image_base64' => $png, 'label' => 'Drawn Signature'])->assertOk();
        $signature = UserSignature::where('user_id', $user->id)->firstOrFail();
        Storage::disk('public')->assertExists($signature->image_path);
    }

    public function test_invalid_signature_upload_does_not_delete_the_previous_signature(): void
    {
        $user = User::factory()->create(['role' => 'Dean']);
        Storage::disk('public')->put('signatures/audit.png', 'previous-signature-fixture');
        $signature = UserSignature::create(['user_id' => $user->id, 'image_path' => 'signatures/audit.png', 'active' => true]);
        $this->actingAs($user)->postJson('/api/signatures', ['image_base64' => 'invalid'])->assertUnprocessable();
        $this->assertDatabaseHas('user_signatures', ['id' => $signature->id]);
        Storage::disk('public')->assertExists('signatures/audit.png');
    }

    private function submission(): array
    {
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        $adviser = User::factory()->create(['role' => 'Adviser']);
        $student = User::factory()->create(['role' => 'Student']);
        $adviser->advisedStudents()->attach($student->id, ['status' => 'accepted', 'requested_by' => $coordinator->id]);
        $this->actingAs($student);

        return ['first_name' => 'Audit', 'last_name' => 'Student', 'school_id' => 'AUDIT-STUDENT', 'program' => 'Master of Arts', 'thesis_title' => 'Synthetic audit request', 'defense_type' => 'Proposal', 'adviser' => $adviser->display_name, 'adviser_id' => $adviser->id, 'reference_no' => 'AUDIT-001', 'amount' => 100];
    }

    public function test_submission_rejects_missing_required_document_files(): void
    {
        $response = $this->postJson('/defense-requirements', $this->submission());
        $this->assertDatabaseCount('defense_requests', 0);
        $response->assertUnprocessable();
    }

    public function test_submission_rejects_a_disallowed_manuscript_type(): void
    {
        $data = $this->submission();
        $data['manuscript_proposal'] = UploadedFile::fake()->create('unsafe.exe', 1, 'application/octet-stream');
        $this->postJson('/defense-requirements', $data)->assertUnprocessable()->assertJsonValidationErrors('manuscript_proposal');
        $this->assertDatabaseCount('defense_requests', 0);
    }

    public function test_submission_rejects_a_file_above_the_configured_limit(): void
    {
        config(['upload.max_file_size_kb' => 1]);
        $data = $this->submission();
        $data['manuscript_proposal'] = UploadedFile::fake()->create('oversized.pdf', 2, 'application/pdf');
        $this->postJson('/defense-requirements', $data)->assertUnprocessable()->assertJsonValidationErrors('manuscript_proposal');
        $this->assertDatabaseCount('defense_requests', 0);
    }

    public function test_an_unassigned_adviser_cannot_bulk_endorse_another_adviser_request(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        $owner = User::factory()->create(['role' => 'Adviser']);
        $other = User::factory()->create(['role' => 'Adviser']);
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        $other->coordinators()->attach($coordinator->id);
        $request = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
            'submitted_by' => $student->id, 'adviser_user_id' => $owner->id, 'assigned_to_user_id' => $owner->id,
            'first_name' => 'Audit', 'last_name' => 'Student', 'school_id' => 'AUDIT-FOREIGN', 'program' => 'Master of Arts',
            'thesis_title' => 'Other adviser request', 'defense_type' => 'Proposal', 'defense_adviser' => $owner->display_name,
            'proof_of_payment' => 'fixture.pdf', 'status' => 'Pending', 'workflow_state' => 'submitted',
        ]));
        $this->actingAs($other)->getJson('/api/defense-request/'.$request->id)->assertForbidden();
        foreach (['bulk-approve', 'bulk-reject', 'bulk-retrieve'] as $action) {
            $this->postJson('/defense-requests/'.$action, ['ids' => [$request->id]]);
        }
        $this->assertSame('submitted', $request->fresh()->workflow_state, 'An unrelated adviser must not change this request through bulk approval.');
    }

    public function test_prefinal_submission_requires_rec_endorsement_on_the_server(): void
    {
        $data = $this->submission();
        $data['defense_type'] = 'Prefinal';
        foreach (['manuscript_proposal', 'similarity_index', 'proof_of_payment'] as $field) {
            $data[$field] = UploadedFile::fake()->createWithContent($field.'.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        }
        $response = $this->postJson('/defense-requirements', $data);
        $this->assertDatabaseCount('defense_requests', 0);
        $response->assertUnprocessable()->assertJsonValidationErrors('rec_endorsement');
    }

    public function test_all_controller_routes_resolve_to_existing_public_methods(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            $action = $route->getActionName();
            if ($action === 'Closure' || ! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            $this->assertTrue(class_exists($class), $action);
            $this->assertTrue(method_exists($class, $method), $action);
            $this->assertTrue((new ReflectionMethod($class, $method))->isPublic(), $action);
        }
    }

    public function test_signature_file_upload_uses_a_default_label(): void
    {
        $user = User::factory()->create(['role' => 'Adviser']);
        $this->actingAs($user)->postJson('/api/signatures', ['image' => UploadedFile::fake()->image('signature.png')])
            ->assertOk()->assertJsonPath('label', 'Signature');
        $this->assertDatabaseHas('user_signatures', ['user_id' => $user->id, 'label' => 'Signature']);
    }

    public function test_pdf_generation_imports_a_template_and_writes_a_document(): void
    {
        $this->submission();
        $user = auth()->user();
        $request = DefenseRequest::withoutEvents(fn () => DefenseRequest::create([
            'submitted_by' => $user->id, 'first_name' => 'Audit', 'last_name' => 'Student',
            'defense_adviser' => 'Audit Adviser', 'proof_of_payment' => 'receipt.pdf',
            'school_id' => 'PDF-TEST', 'program' => 'Master of Arts', 'thesis_title' => 'PDF Test',
            'defense_type' => 'Proposal', 'status' => 'Pending', 'workflow_state' => 'submitted',
        ]));
        $pdf = new FPDF;
        $pdf->AddPage();
        $pdf->SetFont('Arial', '', 12);
        $pdf->Cell(50, 10, 'Endorsement template');
        Storage::disk('public')->put('templates/test.pdf', $pdf->Output('S'));
        $template = DocumentTemplate::create([
            'name' => 'Test template', 'code' => 'pdf-test', 'file_path' => 'templates/test.pdf',
            'created_by' => $user->id, 'version' => 1,
            'fields' => [['page' => 1, 'type' => 'text', 'key' => 'student.full_name', 'x' => 10, 'y' => 50, 'width' => 200, 'height' => 20]],
        ]);
        $generated = app(DocumentGenerator::class)->generate($template, $request);
        $this->assertStringStartsWith('%PDF-', Storage::disk('public')->get($generated->output_path));
        $this->assertDatabaseHas('generated_documents', ['id' => $generated->id, 'status' => 'generated']);
        $this->get('/generated-documents/'.$generated->id)->assertDownload();
        $this->actingAs(User::factory()->create(['role' => 'Student']))->get('/generated-documents/'.$generated->id)->assertForbidden();
        $this->postJson('/api/generate-document', ['template_id' => $template->id, 'defense_request_id' => $request->id])->assertForbidden();

    }

    public function test_coordinator_code_registration_enforces_roles(): void
    {
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        $adviser = User::factory()->create(['role' => 'Adviser']);
        $this->actingAs($coordinator)->getJson('/api/coordinator/code')->assertOk()->assertJsonPath('coordinator_code', $coordinator->coordinator_code);
        $this->actingAs($adviser)->postJson('/api/adviser/register-with-coordinator-code', ['coordinator_code' => $coordinator->coordinator_code])->assertOk();
        $this->assertTrue($adviser->coordinators()->whereKey($coordinator->id)->exists());
        $this->actingAs(User::factory()->create(['role' => 'Student']))->getJson('/api/coordinator/code')->assertForbidden();
    }

    public function test_student_dashboard_returns_the_latest_submitted_payment(): void
    {
        $student = User::factory()->create(['role' => 'Student']);
        PaymentSubmission::create([
            'student_id' => $student->id, 'or_number' => 'OR-TEST', 'amount_paid' => 1234,
            'payment_date' => today(), 'payment_type' => 'exam', 'receipt_image' => 'receipt.pdf', 'status' => 'pending',
        ]);
        $this->actingAs($student)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page->where('recentPayment.amount', 1234));
    }

    public function test_assistant_dashboard_metrics_use_saved_records(): void
    {
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $this->actingAs($assistant)->get('/dashboard')->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.pending_honorariums', 0)->where('stats.pending_payments', 0));
    }

    public function test_valid_proposal_upload_remains_available_and_only_the_assigned_adviser_can_upload_review_documents(): void
    {
        $data = $this->submission();
        foreach (['manuscript_proposal', 'similarity_index', 'proof_of_payment', 'avisee_adviser_attachment'] as $field) {
            $data[$field] = UploadedFile::fake()->createWithContent($field.'.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF");
        }
        $this->post('/defense-requirements', $data)->assertSessionHasNoErrors()->assertSessionHas('success');
        $request = DefenseRequest::firstOrFail();
        Storage::disk('public')->assertExists($request->manuscript_proposal);
        $url = '/adviser/defense-requirements/'.$request->id.'/documents';
        $this->postJson($url, ['endorsement_form' => UploadedFile::fake()->create('form.pdf', 1, 'application/pdf')])->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'Adviser']))->postJson($url)->assertForbidden();
        $adviser = User::findOrFail($data['adviser_id']);
        $this->actingAs($adviser)->postJson($url, ['endorsement_form' => UploadedFile::fake()->create('form.exe', 1, 'application/octet-stream')])->assertUnprocessable();
        $this->postJson($url, ['endorsement_form' => UploadedFile::fake()->createWithContent('form.pdf', "%PDF-1.4\n%%EOF")])->assertOk();
    }
}
