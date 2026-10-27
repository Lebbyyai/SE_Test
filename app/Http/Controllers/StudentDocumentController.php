<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class StudentDocumentController extends Controller
{
    public function index()
    {
        $user = Auth::user();

        // Get all defense requests by this student
        $requests = DefenseRequest::where('submitted_by', $user->id)->orderByDesc('created_at')->get();

        // List of file fields to check
        $fileFields = [
            'manuscript_proposal' => 'Manuscript Proposal',
            'similarity_index' => 'Similarity Index',
            'rec_endorsement' => 'REC Endorsement',
            'proof_of_payment' => 'Proof of Payment',
            'avisee_adviser_attachment' => 'Avisee-Adviser Attachment',
            'ai_detection_certificate' => 'AI Detection Certificate',
            'endorsement_form' => 'Endorsement Form',
        ];

        $files = [];
        foreach ($requests as $req) {
            foreach ($fileFields as $field => $label) {
                $path = $req->$field;
                if ($path) {
                    $path = ltrim(preg_replace('#^/?storage/#', '', $path), '/');
                    $disk = Storage::disk('public');
                    $exists = $disk->exists($path);
                    $size = $exists ? $disk->size($path) : null;
                    $files[] = [
                        'id' => $req->id,
                        'defense_title' => $req->thesis_title,
                        'defense_type' => $req->defense_type,
                        'field' => $field,
                        'label' => $label,
                        'filename' => basename($path),
                        'path' => $path,
                        'url' => $disk->url($path),
                        'size' => $size,
                        'submitted_at' => $req->created_at?->toIso8601String(),
                    ];
                }
            }
        }

        return inertia('student/documents/Index', [
            'files' => $files,
            'auth' => [
                'user' => [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'school_id' => $user->school_id,
                ],
            ],
        ]);
    }
}
