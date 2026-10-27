<?php

namespace App\Http\Controllers\AA;

use App\Http\Controllers\Controller;
use App\Models\AaPaymentBatch;
use App\Models\AaPaymentVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class PaymentVerificationController extends Controller
{
    private function authorizeStaff(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['Administrative Assistant', 'Dean', 'Super Admin'], true), 403);
    }

    private function authorizeVerification(AaPaymentVerification $verification): void
    {
        if (auth()->user()->role === 'Administrative Assistant') {
            abort_if($verification->assigned_to && (int) $verification->assigned_to !== (int) auth()->id(), 403);
        }
    }

    private function validatedSelection(Request $request): array
    {
        return $request->validate([
            'verification_ids' => 'required|array|min:1',
            'verification_ids.*' => 'required|integer|distinct|exists:aa_payment_verifications,id',
        ])['verification_ids'];
    }

    public function index()
    {
        $this->authorizeStaff();
        $verifications = AaPaymentVerification::with('defenseRequest')->latest();
        $batches = AaPaymentBatch::latest();
        if (auth()->user()->role === 'Administrative Assistant') {
            $verifications->where(fn ($q) => $q->whereNull('assigned_to')->orWhere('assigned_to', auth()->id()));
            $batches->where('created_by', auth()->id());
        }

        return Inertia::render('assistant/payment-verifications/Index', [
            'verifications' => $verifications->get(),
            'batches' => $batches->get(['id', 'name', 'status']),
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $this->authorizeStaff();
        $data = $request->validate(['status' => 'required|in:pending,verified,rejected', 'remarks' => 'nullable|string|max:5000']);

        return DB::transaction(function () use ($data, $id) {
            $verification = AaPaymentVerification::lockForUpdate()->findOrFail($id);
            $this->authorizeVerification($verification);
            $verification->fill($data);
            if (! $verification->assigned_to && auth()->user()->role === 'Administrative Assistant') {
                $verification->assigned_to = auth()->id();
            }
            $verification->save();

            return response()->json(['success' => true, 'status' => $verification->status]);
        });
    }

    public function addToBatch(Request $request)
    {
        $this->authorizeStaff();
        $data = $request->validate(['name' => 'required|string|max:120']);
        $ids = $this->validatedSelection($request);
        $batch = DB::transaction(function () use ($ids, $data) {
            $rows = AaPaymentVerification::whereIn('id', $ids)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->authorizeVerification($row);
                abort_if($row->batch_id, 422, 'A selected verification already belongs to a batch.');
            }
            $batch = AaPaymentBatch::create(['name' => $data['name'], 'created_by' => auth()->id(), 'status' => 'pending']);
            foreach ($rows as $row) {
                $row->batch_id = $batch->id;
                if (! $row->assigned_to && auth()->user()->role === 'Administrative Assistant') {
                    $row->assigned_to = auth()->id();
                }
                $row->save();
            }

            return $batch;
        });

        return $request->expectsJson() ? response()->json(['success' => true, 'batch' => $batch], 201) : back()->with('success', 'Batch created and requests added.');
    }

    public function exportBatch($batchId)
    {
        $this->authorizeStaff();
        $batch = AaPaymentBatch::with('verifications')->findOrFail($batchId);
        if (auth()->user()->role === 'Administrative Assistant') {
            abort_unless((int) $batch->created_by === (int) auth()->id(), 403);
        }
        foreach ($batch->verifications as $row) {
            $this->authorizeVerification($row);
        }

        return response()->streamDownload(function () use ($batch) {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, ['ID', 'Defense Request', 'Status', 'Remarks'], ',', '"', '');
            foreach ($batch->verifications as $row) {
                $remarks = (string) $row->remarks;
                if (preg_match('/^[\\s]*[=+@-]|^[\\t\\r\\n]/', $remarks)) {
                    $remarks = "'".$remarks;
                }
                fputcsv($stream, [$row->id, $row->defense_request_id, $row->status, $remarks], ',', '"', '');
            }
            fclose($stream);
        }, 'batch_'.$batch->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function bulkUpdateStatus(Request $request)
    {
        $this->authorizeStaff();
        $data = $request->validate(['status' => 'required|in:pending,verified,rejected']);
        $ids = $this->validatedSelection($request);

        return DB::transaction(function () use ($ids, $data) {
            $rows = AaPaymentVerification::whereIn('id', $ids)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $this->authorizeVerification($row);
            }
            foreach ($rows as $row) {
                $row->status = $data['status'];
                if (! $row->assigned_to && auth()->user()->role === 'Administrative Assistant') {
                    $row->assigned_to = auth()->id();
                }
                $row->save();
            }

            return response()->json(['success' => true, 'updated_count' => $rows->count()]);
        });
    }
}
