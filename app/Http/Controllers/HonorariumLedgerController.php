<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\HonorariumDisbursement;
use App\Services\HonorariumAccess;
use App\Services\HonorariumLedgerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class HonorariumLedgerController extends Controller
{
    public function index(Request $request, HonorariumAccess $access)
    {
        $user = $request->user();
        $ids = $access->scope(DefenseRequest::query(), $user)->pluck('id');
        $staff = $access->canSubmit($user) || $access->canReview($user);
        $rows = HonorariumDisbursement::with('defenseRequest:id,thesis_title,program,workflow_state')->whereIn('defense_request_id', $ids)->when(! $staff && $access->role($user) !== 'coordinator', fn ($q) => $q->whereRaw('LOWER(recipient_email) = ?', [strtolower($user->email)]))->latest('id')->limit(1000)->get();

        return Inertia::render('honorarium/ledger/Index', ['records' => $rows, 'requests' => $access->canSubmit($user) ? DefenseRequest::whereIn('id', $ids)->whereIn('workflow_state', ['coordinator-approved', 'panels-assigned', 'scheduled', 'completed'])->get(['id', 'thesis_title']) : [], 'canPrepare' => $access->canSubmit($user), 'canRecord' => $access->canReview($user), 'today' => now('Asia/Manila')->toDateString()]);
    }

    public function prepare(Request $request, DefenseRequest $defenseRequest, HonorariumAccess $access, HonorariumLedgerService $ledger)
    {
        abort_unless($access->canSubmit($request->user()), 403);
        $data = $request->validate(['school_year' => ['required', 'regex:/^20\d{2}-20\d{2}$/'], 'semester' => 'required|in:1st,2nd,Summer']);
        [$start,$end] = explode('-', $data['school_year']);
        if ((int) $end !== (int) $start + 1) {
            throw ValidationException::withMessages(['school_year' => 'Use consecutive school years, for example 2026-2027.']);
        }
        $ledger->prepare($defenseRequest, $request->user(), $data);

        return response()->json(['message' => 'Honorarium records prepared for Finance approval.']);
    }

    public function update(Request $request, HonorariumDisbursement $disbursement, HonorariumAccess $access, HonorariumLedgerService $ledger)
    {
        abort_unless($access->canReview($request->user()), 403);
        $data = $request->validate(['status' => 'required|in:approved,disbursed,claimed', 'event_date' => 'nullable|required_if:status,disbursed,claimed|date_format:Y-m-d|before_or_equal:'.now('Asia/Manila')->toDateString(), 'payment_reference' => 'nullable|required_if:status,disbursed|string|max:150', 'notes' => 'nullable|string|max:2000']);
        $ledger->transition($disbursement, $request->user(), $data);

        return response()->json(['message' => 'Honorarium status recorded.']);
    }
}
