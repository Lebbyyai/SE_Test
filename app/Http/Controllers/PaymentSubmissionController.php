<?php

namespace App\Http\Controllers;

use App\Models\ExamApplication;
use App\Models\Notification;
use App\Models\PaymentSubmission;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;

class PaymentSubmissionController extends Controller
{
    private function current(Request $r): ?ExamApplication
    {
        $user = $r->user();

        return ExamApplication::whereIn('student_id', array_filter([$user->school_id, $user->id]))->latest('application_id')->first();
    }

    private function receipt(Request $request, ExamApplication $application): ?PaymentSubmission
    {
        return PaymentSubmission::where('student_id', $request->user()->id)
            ->where('payment_type', 'exam')->where('exam_application_id', $application->application_id)
            ->latest('payment_id')->first();
    }

    public function index(Request $r)
    {
        abort_unless($r->user()->role === 'Student', 403);
        $w = $this->current($r);
        $payment = $w ? $this->receipt($r, $w) : null;

        $approvalStatus = match (true) {
            ! $w => 'not_submitted',
            $w->final_approval_status === 'rejected' => 'rejected',
            $w->registrar_approval_status !== 'approved' => 'registrar_pending',
            $w->final_approval_status !== 'approved' => 'aa_pending',
            default => 'approved',
        };

        return Inertia::render('payment/Index', [
            'student' => ['name' => $r->user()->name, 'program' => $r->user()->program, 'email' => $r->user()->email],
            'approvalStatus' => $approvalStatus, 'canSubmit' => $approvalStatus === 'approved', 'payment' => $payment ? [
                'payment_id' => $payment->payment_id, 'or_number' => $payment->or_number, 'payment_date' => $payment->payment_date?->toDateString(),
                'receipt_image' => $payment->receipt_image, 'status' => $payment->status, 'remarks' => $payment->remarks, 'amount_paid' => $payment->amount_paid, 'created_at' => $payment->created_at?->format('Y-m-d H:i:s'),
            ] : null,
        ]);
    }

    public function store(Request $r)
    {
        abort_unless($r->user()->role === 'Student', 403);
        $w = $this->current($r);
        if (! $w || $w->registrar_approval_status !== 'approved' || $w->final_approval_status !== 'approved') {
            return back()->withErrors(['form' => 'Registrar and Administrative Assistant approval are required before payment.']);
        }
        $existing = $this->receipt($r, $w);
        if ($existing && $existing->status !== 'rejected') {
            return back()->withErrors(['form' => 'This receipt is already submitted. Wait for review.']);
        }
        $d = $r->validate(['or_number' => 'required|string|max:50', 'payment_date' => 'required|date|before_or_equal:today', 'amount_paid' => 'required|numeric|min:0.01', 'receipt_image' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        $path = $r->file('receipt_image')->store('comprehensive-receipts', 'local');
        abort_unless($path, 500, 'The receipt could not be saved.');
        try {
            DB::transaction(function () use ($r, $w, $d, $path) {
                $w = ExamApplication::lockForUpdate()->findOrFail($w->application_id);
                abort_unless($w->registrar_approval_status === 'approved' && $w->final_approval_status === 'approved', 422, 'Registrar approval changed. Refresh before submitting.');
                $current = $this->receipt($r, $w);
                abort_if($current && $current->status !== 'rejected', 409, 'Receipt already submitted.');
                PaymentSubmission::create(['student_id' => $r->user()->id, 'exam_application_id' => $w->application_id, 'payment_type' => 'exam', 'or_number' => $d['or_number'], 'payment_date' => $d['payment_date'], 'amount_paid' => $d['amount_paid'], 'receipt_image' => $path, 'status' => 'pending']);
                foreach (User::where('role', 'Administrative Assistant')->pluck('id') as $reviewer) {
                    Notification::create([
                        'user_id' => $reviewer,
                        'type' => 'comprehensive-exam-receipt',
                        'title' => 'Comprehensive exam receipt submitted',
                        'message' => $r->user()->name.' submitted an exam receipt.',
                        'link' => '/coordinator/compre-payment',
                    ]);
                }
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }

        return to_route('payment.index')->with('success', 'Receipt submitted for verification.');
    }

    public function download(Request $r, PaymentSubmission $payment)
    {
        abort_unless($payment->payment_type === 'exam', 404);
        $u = $r->user();
        $student = $payment->student;
        abort_unless($student, 404);
        if ($u->role === 'Student') {
            abort_unless($u->id === (int) $payment->student_id, 403);
        } elseif ($u->role === 'Coordinator') {
            abort_unless(in_array($student->program, $u->allowedProgramNames(), true), 403);
        } else {
            abort_unless(in_array($u->role, ['Administrative Assistant', 'Registrar', 'Dean', 'Super Admin', 'SuperAdmin'], true), 403);
        }
        $disk = str_starts_with((string) $payment->receipt_image, 'comprehensive-receipts/') ? 'local' : 'public';
        abort_unless($payment->receipt_image && Storage::disk($disk)->exists($payment->receipt_image), 404);
        $extension = pathinfo($payment->receipt_image, PATHINFO_EXTENSION);

        return Storage::disk($disk)->download($payment->receipt_image, 'Exam Receipt - '.preg_replace('/[^a-zA-Z0-9_-]/', '-', $payment->or_number).'.'.$extension, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
