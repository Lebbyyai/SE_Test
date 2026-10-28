<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DefenseCorrectionController extends Controller
{
    public function requestCorrections(Request $request, DefenseRequest $defenseRequest)
    {
        $data = $request->validate([
            'stage' => 'required|in:adviser,coordinator',
            'notes' => 'required|string|max:5000',
        ]);
        $updated = DB::transaction(function () use ($request, $defenseRequest, $data) {
            $dr = DefenseRequest::lockForUpdate()->findOrFail($defenseRequest->id);
            $stage = $data['stage'];
            $user = $request->user();
            $assignedId = $stage === 'adviser' ? $dr->adviser_user_id : $dr->coordinator_user_id;
            abort_unless($user->role === ($stage === 'adviser' ? 'Adviser' : 'Coordinator') && (int) $assignedId === (int) $user->id, 403);
            $allowed = $stage === 'adviser' ? ['submitted', 'adviser-review'] : ['adviser-approved', 'coordinator-review'];
            abort_unless(in_array($dr->workflow_state, $allowed, true), 422, 'This request is no longer awaiting your review. Refresh the page.');
            $from = $dr->workflow_state;
            $dr->correction_stage = $stage;
            $dr->correction_notes = $data['notes'];
            $dr->correction_requested_by = $user->id;
            $dr->correction_requested_at = now();
            $dr->correction_count = ($dr->correction_count ?? 0) + 1;
            $dr->workflow_state = $stage.'-corrections';
            $dr->status = 'Pending';
            $dr->{$stage.'_status'} = 'Pending';
            $dr->last_status_updated_at = now();
            $dr->last_status_updated_by = $user->id;
            $dr->ensureSubmittedHistory()->addWorkflowEntry('corrections-requested', $data['notes'], $user->id, $from, $dr->workflow_state);
            $dr->save();

            return $dr;
        });

        return response()->json(['message' => 'The student has been asked to make corrections.', 'request' => $updated]);
    }

    public function resubmit(Request $request, DefenseRequest $defenseRequest)
    {
        abort_unless($request->user()->role === 'Student' && (int) $defenseRequest->submitted_by === (int) $request->user()->id, 403);
        $max = config('upload.max_file_size_kb', 204800);
        $fields = ['manuscript_proposal', 'similarity_index', 'rec_endorsement', 'proof_of_payment', 'avisee_adviser_attachment'];
        $rules = ['student_response' => 'required|string|max:5000', 'thesis_title' => 'sometimes|required|string|max:255'];
        foreach ($fields as $field) {
            $mimes = $field === 'manuscript_proposal' ? 'pdf,doc,docx' : 'pdf,doc,docx,jpg,jpeg,png';
            $rules[$field] = "nullable|file|mimes:{$mimes}|max:{$max}";
        }
        $data = $request->validate($rules);
        $stored = [];
        try {
            $updated = DB::transaction(function () use ($request, $defenseRequest, $data, $fields, &$stored) {
                $dr = DefenseRequest::lockForUpdate()->findOrFail($defenseRequest->id);
                abort_unless(in_array($dr->workflow_state, ['adviser-corrections', 'coordinator-corrections'], true), 422, 'Only requests returned for corrections can be resubmitted.');
                $from = $dr->workflow_state;
                $stage = $from === 'adviser-corrections' ? 'adviser' : 'coordinator';
                $changes = [];
                foreach ($fields as $field) {
                    if (! $request->hasFile($field)) {
                        continue;
                    }
                    $path = $request->file($field)->store('defense_requirements', 'public');
                    if (! $path) {
                        throw new \RuntimeException('Unable to store the replacement file.');
                    }
                    $stored[] = $path;
                    $previousName = $dr->attachment_names[$field] ?? null;
                    $name = DefenseRequest::cleanAttachmentName($request->file($field)->getClientOriginalName());
                    $dr->attachment_original_names = array_merge($dr->attachment_original_names ?? [], [$field => $name]);
                    $changes[$field] = ['previous' => $dr->{$field}, 'current' => $path, 'previous_name' => $previousName, 'current_name' => $name];
                    $dr->{$field} = $path;
                }
                $oldTitle = $dr->thesis_title;
                if (isset($data['thesis_title'])) {
                    $dr->thesis_title = $data['thesis_title'];
                }
                $dr->workflow_state = $stage === 'adviser' ? 'adviser-review' : 'adviser-approved';
                $dr->status = 'Pending';
                $dr->{$stage.'_status'} = 'Pending';
                $dr->last_status_updated_at = now();
                $dr->last_status_updated_by = $request->user()->id;
                $dr->addWorkflowEntry('resubmitted', $data['student_response'], $request->user()->id, $from, $dr->workflow_state);
                $history = $dr->workflow_history;
                $last = count($history) - 1;
                $history[$last]['attachments'] = $changes;
                if ($oldTitle !== $dr->thesis_title) {
                    $history[$last]['thesis_title'] = ['previous' => $oldTitle, 'current' => $dr->thesis_title];
                }
                $dr->workflow_history = $history;
                $dr->save();

                return $dr;
            });
        } catch (\Throwable $e) {
            // Keep prior versions; remove only files created by this failed attempt.
            foreach ($stored as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $e;
        }

        return response()->json(['message' => 'Your corrections have been submitted for review.', 'request' => $updated]);
    }
}
