<?php

use App\Models\CoordinatorProgram;
use App\Models\ExamApplication;
use App\Models\PaymentSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ExamReviewPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Mail::fake();
    }

    private function application(): array
    {
        $student = User::factory()->create(['role' => 'Student', 'program' => 'Master of Arts', 'school_id' => 'EXAM-REVIEW-STUDENT']);
        $application = ExamApplication::create(['student_id' => $student->school_id, 'school_year' => '2026-2027', 'program' => $student->program, 'permit_status' => 'pending', 'final_approval_status' => 'pending', 'created_at' => now()]);

        return [$application, $student];
    }

    private function receipt(User $student): PaymentSubmission
    {
        return PaymentSubmission::create(['student_id' => $student->id, 'or_number' => 'OR-AUDIT', 'amount_paid' => 500, 'payment_date' => today(), 'payment_type' => 'exam', 'receipt_image' => 'test-receipt.png', 'status' => 'pending']);
    }

    public function test_assistant_can_view_and_approve_an_application_without_coordinator_program_assignments(): void
    {
        [$application, $student] = $this->application();
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $this->actingAs($assistant)->get('/coordinator/compre-exam')->assertOk()->assertInertia(fn ($page) => $page->where('notEligible.0.application_id', $application->application_id));
        $this->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('exam_application', ['application_id' => $application->application_id, 'final_approval_status' => 'approved', 'reviewed_by' => $assistant->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $student->id, 'type' => 'comprehensive-exam-reviewed']);
        $this->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'rejected', 'remarks' => 'Try again'])->assertConflict();
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_assistant_application_rejection_requires_and_preserves_a_reason(): void
    {
        [$application] = $this->application();
        $this->actingAs(User::factory()->create(['role' => 'Administrative Assistant']))->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'rejected'])->assertUnprocessable();
        $this->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'rejected', 'remarks' => 'Incomplete supporting documents'])->assertOk();
        $this->assertSame('Incomplete supporting documents', $application->fresh()->review_remarks);
    }

    public function test_coordinator_application_reviews_remain_scoped_to_their_programs(): void
    {
        [$application] = $this->application();
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => 'Another Program']);
        $this->actingAs($coordinator)->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertForbidden();
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => 'Master of Arts']);
        $this->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertOk();
    }

    public function test_students_cannot_review_exam_applications_or_receipts(): void
    {
        [$application, $student] = $this->application();
        $receipt = $this->receipt($student);
        $this->actingAs($student)->postJson('/coordinator/compre-exam/'.$application->application_id.'/review', ['decision' => 'approved'])->assertForbidden();
        $this->post('/coordinator/compre-payment/'.$receipt->payment_id.'/approve')->assertForbidden();
    }

    public function test_assistant_can_review_receipts_and_records_the_reviewer(): void
    {
        [$application, $student] = $this->application();
        $receipt = $this->receipt($student);
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $this->actingAs($assistant)->get('/coordinator/compre-payment')->assertOk()->assertInertia(fn ($page) => $page->where('counts.pending', 1));
        $this->post('/coordinator/compre-payment/'.$receipt->payment_id.'/approve')->assertRedirect('/coordinator/compre-payment');
        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertEquals($assistant->id, $receipt->fresh()->checked_by);
    }

    public function test_coordinator_cannot_review_a_receipt_outside_their_programs(): void
    {
        [$application, $student] = $this->application();
        $receipt = $this->receipt($student);
        $coordinator = User::factory()->create(['role' => 'Coordinator']);
        CoordinatorProgram::create(['coordinator_id' => $coordinator->id, 'program' => 'Another Program']);
        $this->actingAs($coordinator)->post('/coordinator/compre-payment/'.$receipt->payment_id.'/approve')->assertSessionHasErrors('status');
        $this->assertSame('pending', $receipt->fresh()->status);
    }

    public function test_assistant_can_reject_a_receipt_with_a_reason(): void
    {
        [$application, $student] = $this->application();
        $receipt = $this->receipt($student);
        $assistant = User::factory()->create(['role' => 'Administrative Assistant']);
        $this->actingAs($assistant)->post('/coordinator/compre-payment/'.$receipt->payment_id.'/reject', ['remarks' => 'Receipt number does not match'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $receipt->fresh()->status);
        $this->assertSame('Receipt number does not match', $receipt->fresh()->remarks);
        $this->assertEquals($assistant->id, $receipt->fresh()->checked_by);
    }
}
