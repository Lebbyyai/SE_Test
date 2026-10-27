<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\FinanceDocumentSubmission;
use App\Models\HonorariumActivity;
use App\Models\Notification;
use App\Models\User;
use App\Services\FinanceDocumentWorkflowService;
use App\Services\HonorariumAccess;
use App\Services\HonorariumLedgerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FinanceDocumentWorkflowController extends Controller
{
    public function index(Request $request, FinanceDocumentWorkflowService $workflow, HonorariumAccess $access)
    {
        $user = $request->user();
        $query = $access->scope(DefenseRequest::query(), $user);
        if ($access->canSubmit($user) || $access->canReview($user)) {
            $workflow->unlockDueDocuments();
        }
        $requests = $query->with('student')->whereNotNull('proof_of_payment')->latest('updated_at')->limit(500)->get();
        $docs = FinanceDocumentSubmission::whereIn('defense_request_id', $requests->pluck('id'))->get()->groupBy('defense_request_id');
        $today = now('Asia/Manila')->toDateString();
        $items = $requests->map(function ($r) use ($docs, $access, $today) {
            $byType = $docs->get($r->id, collect())->keyBy('document_type');

            return [
                'id' => $r->id, 'student_name' => $r->student?->full_name ?: trim($r->first_name.' '.$r->last_name),
                'student_number' => $r->student?->school_id ?: $r->school_id, 'program' => $r->program, 'defense_type' => $r->defense_type,
                'workflow_state' => $r->workflow_state, 'receipt_url' => $access->receiptUrl($r->proof_of_payment), 'panel_names' => $access->panelNames($r),
                'can_send' => in_array($r->workflow_state, ['coordinator-approved', 'panels-assigned', 'scheduled', 'completed'], true) && ! in_array($r->status, ['Cancelled', 'Rejected'], true),
                'document1' => $byType->get('payment_receipt') ? $this->documentData($byType->get('payment_receipt')) : ['id' => null, 'status' => 'ready', 'ready_on' => $today],
                'document2' => $byType->get('panel_breakdown') ? $this->documentData($byType->get('panel_breakdown')) : ['id' => null, 'status' => 'not_created'],
            ];
        });
        $activities = HonorariumActivity::whereIn('defense_request_id', $requests->pluck('id'))->with('user:id,first_name,last_name')->latest('occurred_at')->limit(500)->get()->map(fn ($a) => [
            'id' => $a->id, 'date' => $a->occurred_at->timezone('Asia/Manila')->toDateString(), 'occurred_at' => $a->occurred_at->timezone('Asia/Manila')->toDateTimeString(),
            'title' => $a->title, 'description' => $a->description, 'activity_type' => $a->activity_type, 'actor' => $a->user?->full_name ?: 'System', 'defense_request_id' => $a->defense_request_id,
        ]);

        return Inertia::render('honorarium/documents/Index', ['role' => $access->role($user), 'canSubmit' => $access->canSubmit($user), 'canReview' => $access->canReview($user), 'items' => $items, 'activities' => $activities, 'today' => $today]);
    }

    private function saved(Request $request, string $message)
    {
        return $request->expectsJson() ? response()->json(['message' => $message]) : back()->with('success', $message);
    }

    private function documentData(FinanceDocumentSubmission $doc): array
    {
        return ['id' => $doc->id, 'status' => $doc->status, 'ready_on' => $doc->ready_on?->format('Y-m-d'), 'submitted_at' => $doc->submitted_at?->timezone('Asia/Manila')->toDateTimeString(), 'finance_notes' => $doc->finance_notes, 'payload' => $doc->payload];
    }

    private function notice(DefenseRequest $dr, string $title, string $message, array $roles): void
    {
        foreach (User::whereIn('role', $roles)->get(['id']) as $recipient) {
            Notification::create(['user_id' => $recipient->id, 'defense_request_id' => $dr->id, 'type' => 'honorarium', 'title' => $title, 'message' => $message, 'link' => '/honorarium-documents', 'read' => false]);
        }
    }

    private function activity(DefenseRequest $dr, FinanceDocumentSubmission $doc, string $type, string $title, string $description): void
    {
        HonorariumActivity::create(['defense_request_id' => $dr->id, 'user_id' => auth()->id(), 'finance_document_submission_id' => $doc->id, 'activity_type' => $type, 'title' => $title, 'description' => $description, 'occurred_at' => now()]);
    }

    public function submitFirst(Request $request, DefenseRequest $defenseRequest)
    {
        $access = app(HonorariumAccess::class);
        abort_unless($access->canSubmit($request->user()), 403);
        DB::transaction(function () use ($defenseRequest, $access) {
            $dr = DefenseRequest::lockForUpdate()->findOrFail($defenseRequest->id);
            $access->assertReady($dr);
            $existing = FinanceDocumentSubmission::where('defense_request_id', $dr->id)->where('document_type', 'payment_receipt')->first();
            abort_if($existing && in_array($existing->status, ['submitted', 'verified'], true), 422, 'The receipt has already been sent to Finance.');
            $first = FinanceDocumentSubmission::updateOrCreate(['defense_request_id' => $dr->id, 'document_type' => 'payment_receipt'], ['status' => 'submitted', 'submitted_by' => auth()->id(), 'payload' => ['receipt_path' => $dr->proof_of_payment], 'finance_notes' => null, 'ready_on' => now('Asia/Manila')->toDateString(), 'submitted_at' => now(), 'reviewed_by' => null, 'reviewed_at' => null]);
            FinanceDocumentSubmission::firstOrCreate(['defense_request_id' => $dr->id, 'document_type' => 'panel_breakdown'], ['status' => 'locked', 'payload' => ['panel_names' => $access->panelNames($dr)], 'ready_on' => now('Asia/Manila')->addDay()->toDateString()]);
            $this->activity($dr, $first, 'document_submitted', 'Payment receipt sent to Finance', 'The AA submitted the student receipt to Finance.');
            $this->notice($dr, 'Payment Receipt for Review', 'A defense receipt is awaiting Finance verification.', ['Finance', 'Finance Office', 'Finance Officer']);
        });

        return $this->saved($request, 'Receipt sent to Finance. The panel breakdown unlocks on the next Philippine calendar day.');
    }

    public function submitSecond(Request $request, DefenseRequest $defenseRequest, FinanceDocumentWorkflowService $workflow)
    {
        $access = app(HonorariumAccess::class);
        abort_unless($access->canSubmit($request->user()), 403);
        $workflow->unlockDueDocuments();
        DB::transaction(function () use ($defenseRequest, $access) {
            $dr = DefenseRequest::lockForUpdate()->findOrFail($defenseRequest->id);
            $access->assertReady($dr);
            $receipt = FinanceDocumentSubmission::where('defense_request_id', $dr->id)->where('document_type', 'payment_receipt')->first();
            abort_unless($receipt?->status === 'verified', 422, 'Finance must verify the student receipt before the panel breakdown is sent.');
            $doc = FinanceDocumentSubmission::where('defense_request_id', $dr->id)->where('document_type', 'panel_breakdown')->firstOrFail();
            abort_unless(in_array($doc->status, ['ready', 'rejected'], true), 422, 'The panel breakdown is not ready or has already been sent.');
            $names = $access->panelNames($dr);
            abort_unless(count($names) > 0, 422, 'Assign panelists before sending the breakdown.');
            $details = collect(app(HonorariumLedgerService::class)->recipients($dr))->where('recipient_role', '!=', 'Adviser')->map(fn ($person) => ['name' => $person['recipient_name'], 'role' => $person['recipient_role'], 'affiliation' => $dr->panel_affiliations[$person['recipient_key']] ?? 'unclassified'])->values()->all();
            $doc->update(['status' => 'submitted', 'payload' => ['panel_names' => $names, 'panel_affiliations' => $dr->panel_affiliations ?? [], 'panel_details' => $details], 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'finance_notes' => null, 'reviewed_by' => null, 'reviewed_at' => null]);
            $this->activity($dr, $doc, 'document_submitted', 'Panel breakdown sent to Finance', 'Current panel names: '.implode(', ', $names));
            $this->notice($dr, 'Panel Breakdown for Review', 'A defense panel breakdown is awaiting Finance verification.', ['Finance', 'Finance Office', 'Finance Officer']);
        });

        return $this->saved($request, 'Panel breakdown sent to Finance.');
    }

    public function review(Request $request, FinanceDocumentSubmission $submission)
    {
        $access = app(HonorariumAccess::class);
        abort_unless($access->canReview($request->user()), 403);
        $data = $request->validate(['status' => 'required|in:verified,rejected', 'finance_notes' => 'nullable|string|max:2000|required_if:status,rejected']);
        DB::transaction(function () use ($submission, $access, $data) {
            $dr = DefenseRequest::lockForUpdate()->findOrFail($submission->defense_request_id);
            $access->assertReady($dr);
            $doc = FinanceDocumentSubmission::lockForUpdate()->findOrFail($submission->id);
            abort_unless($doc->status === 'submitted', 422, 'Only submitted documents can be reviewed.');
            if ($data['status'] === 'verified') {
                if ($doc->document_type === 'payment_receipt') {
                    abort_unless(($doc->payload['receipt_path'] ?? null) === $dr->proof_of_payment, 422, 'The receipt changed. Reject this document so the AA can send the replacement.');
                } else {
                    abort_unless(($doc->payload['panel_names'] ?? []) === $access->panelNames($dr), 422, 'Panel assignments changed. Reject this document so the AA can send the current panel.');
                    abort_unless(($doc->payload['panel_affiliations'] ?? []) === ($dr->panel_affiliations ?? []), 422, 'Panel classifications changed. Request an updated breakdown.');
                }
            }
            $doc->update(['status' => $data['status'], 'finance_notes' => $data['finance_notes'] ?? null, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
            $this->activity($dr, $doc, 'finance_review', 'Finance reviewed '.str_replace('_', ' ', $doc->document_type), 'Finance marked the document '.$data['status'].'. '.($data['finance_notes'] ?? ''));
            $this->notice($dr, 'Finance Review Updated', 'Finance marked a defense document '.$data['status'].'.', ['Administrative Assistant', 'Dean']);
        });

        return $this->saved($request, 'Finance review saved.');
    }
}
