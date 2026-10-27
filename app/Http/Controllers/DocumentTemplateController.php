<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\DocumentTemplate;
use App\Services\DocumentGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use setasign\Fpdi\Fpdi;

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
        try {
            $parser = new Fpdi;
            $pageCount = $parser->setSourceFile($r->file('file')->getRealPath());
        } catch (\Throwable $error) {
            throw ValidationException::withMessages(['file' => 'Upload a readable, unencrypted PDF template.']);
        }
        $code = \Str::slug($data['name']).'-'.uniqid();
        $path = $r->file('file')->store('templates', 'public');
        $tpl = DocumentTemplate::create([
            'name' => $data['name'],
            'code' => $code,
            'file_path' => $path,
            'page_count' => $pageCount,
            'created_by' => $r->user()->id,
        ]);

        return response()->json($tpl);
    }

    public function updateFields(Request $request, DocumentTemplate $template)
    {
        $this->authorizeManagement();
        $data = $request->validate([
            'fields' => 'present|array|max:300',
            'fields.*.key' => 'required|string|max:120|regex:/^[a-zA-Z0-9_.]+$/',
            'fields.*.type' => 'required|in:text,multiline,signature',
            'fields.*.page' => 'required|integer|min:1|max:1000',
            'fields.*.x' => 'required|numeric|min:0|max:20000',
            'fields.*.y' => 'required|numeric|min:0|max:20000',
            'fields.*.width' => 'required|numeric|gt:0|max:20000',
            'fields.*.height' => 'required|numeric|gt:0|max:20000',
            'fields.*.font_size' => 'sometimes|numeric|between:4,72',
            'fields_meta' => 'sometimes|array',
            'fields_meta.canvas_width' => 'required_with:fields_meta|numeric|gt:0|max:20000',
            'fields_meta.canvas_height' => 'required_with:fields_meta|numeric|gt:0|max:20000',
        ]);
        $template->fields = $data['fields'];
        $template->fields_meta = $data['fields_meta'] ?? [];

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
