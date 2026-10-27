<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\DocumentTemplate;
use App\Services\DocumentGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DocumentTemplateController extends Controller
{
    private function authorizeManagement(): void
    {
        abort_unless(in_array(auth()->user()?->role, ['Dean', 'Super Admin']), 403);
    }

    public function index()
    {
        $list = DocumentTemplate::orderBy('created_at', 'desc')->get();

        return response()->json($list);
    }

    public function show(DocumentTemplate $template)
    {
        return response()->json($template);
    }

    public function store(Request $r)
    {
        $this->authorizeManagement();
        Log::info('DocumentTemplateController@store called', ['user_id' => $r->user()->id]);
        $data = $r->validate([
            'name' => 'required|string',
            'file' => 'required|file|mimes:pdf|max:10240',
        ]);
        $code = \Str::slug($data['name']).'-'.uniqid();
        $path = $r->file('file')->store('templates', 'public');
        $tpl = DocumentTemplate::create([
            'name' => $data['name'],
            'code' => $code,
            'file_path' => $path,
            'page_count' => 1,
            'created_by' => $r->user()->id,
        ]);

        return response()->json($tpl);
    }

    public function updateFields(Request $request, DocumentTemplate $template)
    {
        $this->authorizeManagement();
        $template->fields = $request->input('fields', []);
        $template->fields_meta = $request->input('fields_meta', []);
        $template->save();

        return response()->json(['ok' => true]);
    }

    public function destroy(DocumentTemplate $template)
    {
        $this->authorizeManagement();
        Storage::disk('public')->delete($template->file_path);
        $template->delete();

        return response()->json(['ok' => true]);
    }

    public function generate(Request $request)
    {
        $request->validate([
            'template_id' => 'required|integer|exists:document_templates,id',
            'defense_request_id' => 'required|integer|exists:defense_requests,id',
            'fields' => 'array',
        ]);

        $template = DocumentTemplate::findOrFail($request->template_id);
        $defenseRequest = DefenseRequest::findOrFail($request->defense_request_id);

        $user = $request->user();
        abort_unless($user && (in_array($user->role, ['Dean', 'Super Admin'], true)
            || ($user->role === 'Adviser' && (int) $defenseRequest->adviser_user_id === (int) $user->id)), 403);

        // You may want to merge $request->fields into the defenseRequest data for the generator
        $generator = new DocumentGenerator;
        try {
            $generated = $generator->generate($template, $defenseRequest, $request->fields ?? []);
        } catch (\Throwable $e) {
            \Log::error('Document generation failed: '.$e->getMessage());

            return response()->json([
                'ok' => false,
                'error' => 'Document generation failed: '.$e->getMessage(),
            ], 500);
        }

        // Assume $generated is a GeneratedDocument model with output_path
        $url = $generated->output_path
            ? \Storage::disk('public')->url($generated->output_path)
            : null;

        return response()->json([
            'ok' => true,
            'download_url' => $url,
        ]);
    }
}
