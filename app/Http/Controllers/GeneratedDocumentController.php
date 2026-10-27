<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Services\DocumentGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class GeneratedDocumentController extends Controller
{
    public function show(GeneratedDocument $doc)
    {
        $this->authorizeRequest($doc->defenseRequest);
        abort_unless(Storage::disk('public')->exists($doc->output_path), 404);

        return Storage::disk('public')->download($doc->output_path);
    }

    // Manual trigger for a single defense request (debug)
    public function generateNow(Request $r, DefenseRequest $defenseRequest, DocumentGenerator $gen)
    {
        $this->authorizeRequest($defenseRequest, true);
        $templates = DocumentTemplate::all();
        $out = [];
        foreach ($templates as $tpl) {
            $out[] = $gen->generate($tpl, $defenseRequest);
        }

        return response()->json(['generated' => $out]);
    }

    // List docs for a defense request
    public function listForRequest(DefenseRequest $defenseRequest)
    {
        $this->authorizeRequest($defenseRequest);

        return response()->json($defenseRequest->generatedDocuments()->with('template')->get());
    }

    public function generateDocument(Request $request)
    {
        abort_unless($request->user(), 401);
        $request->validate([
            'template_id' => 'required|integer|exists:document_templates,id',
            'defense_request_id' => 'required|integer|exists:defense_requests,id',
            'fields' => 'array',
        ]);

        $tpl = DocumentTemplate::findOrFail($request->template_id);
        $defenseRequest = DefenseRequest::findOrFail($request->defense_request_id);

        $this->authorizeRequest($defenseRequest, true);
        $generator = new DocumentGenerator;
        $generated = $generator->generate($tpl, $defenseRequest, $request->fields ?? []);

        $pdfPath = storage_path('app/public/'.$generated->output_path);

        return response()->download($pdfPath, 'endorsement_form.pdf')->deleteFileAfterSend(false);
    }

    private function authorizeRequest(DefenseRequest $defenseRequest, bool $generate = false): void
    {
        $user = auth()->user();
        abort_unless($user, 401);
        $allowed = in_array($user->role, ['Dean', 'Super Admin'], true)
            || ($user->role === 'Adviser' && (int) $defenseRequest->adviser_user_id === (int) $user->id);
        if (! $generate) {
            $allowed = $allowed || ($user->role === 'Student' && (int) $defenseRequest->submitted_by === (int) $user->id)
                || ($user->role === 'Coordinator' && (int) $defenseRequest->coordinator_user_id === (int) $user->id)
                || $user->role === 'Administrative Assistant';
        }
        abort_unless($allowed, 403);
    }
}
