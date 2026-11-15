<?php

namespace App\Http\Controllers;

use App\Models\DefenseAcademicOutcome;
use App\Models\DefenseRequest;
use App\Models\DefenseServiceRecord;
use App\Services\DefenseAcademicProgress;
use App\Services\DefenseRegisterExport;
use App\Services\DefenseServiceConfirmation;
use App\Services\HonorariumAccess;
use App\Services\HonorariumLedgerService;
use App\Services\StudentProgressService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DefenseRegisterController extends Controller
{
    public function data(Request $request): array
    {
        $filters = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'stage' => 'nullable|in:proposal,prefinal,final',
            'panelist' => 'nullable|integer|min:1',
        ]);
        $stage = $filters['stage'] ?? '';
        $from = $filters['from'] ?? now('Asia/Manila')->startOfMonth()->toDateString();
        $to = $filters['to'] ?? CarbonImmutable::parse($from)->endOfMonth()->toDateString();
        if ($to < $from || CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to)) > 92) {
            throw ValidationException::withMessages(['to' => 'Choose a range of no more than 93 days, with the end on or after the start.']);
        }
        $dayAfterRange = CarbonImmutable::parse($to)->addDay()->toDateString();
        $access = app(HonorariumAccess::class);
        $service = app(DefenseServiceConfirmation::class);
        $canReviewPanelAssignments = in_array($access->role($request->user()), ['coordinator', 'dean', 'administrative assistant', 'assistant', 'aa'], true);
        $panelist = isset($filters['panelist']) ? (int) $filters['panelist'] : null;
        abort_if($panelist !== null && ! $canReviewPanelAssignments, 403);
        $requests = $access->scope(DefenseRequest::query(), $request->user())->with('student', 'adviserUser')
            ->where('scheduled_date', '>=', $from)->where('scheduled_date', '<', $dayAfterRange)
            ->whereIn('workflow_state', ['scheduled', 'completed'])
            ->when($stage !== '', fn ($query) => $query->whereRaw("LOWER(REPLACE(REPLACE(TRIM(defense_type), '-', ''), ' ', '')) = ?", [$stage]))
            ->whereRaw("LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'rejected')")->orderBy('scheduled_date')->orderBy('scheduled_time')->orderBy('id')->get();
        $academic = DefenseAcademicOutcome::whereIn('defense_request_id', $requests->pluck('id'))->get()->keyBy('defense_request_id');
        $records = DefenseServiceRecord::whereIn('defense_request_id', $requests->pluck('id'))->get()->keyBy('defense_request_id');
        $progress = $canReviewPanelAssignments
            ? app(StudentProgressService::class)->summaries(new Collection($requests->pluck('student')->filter()->unique('id')->values()->all()))
            : [];
        $items = $requests->map(function ($dr) use ($records, $service, $request, $academic, $progress) {
            $record = $records->get($dr->id);
            $error = null;
            try {
                $roster = app(HonorariumLedgerService::class)->recipients($dr);
                $valid = $record && $dr->workflow_state === 'completed' && hash_equals($record->snapshot_hash, $service->snapshot($dr));
            } catch (ValidationException $exception) {
                $roster = [];
                $valid = false;
                $error = collect($exception->errors())->flatten()->first();
            }
            $saved = collect($record?->participants ?? [])->keyBy('recipient_key');
            $outcome = app(DefenseAcademicProgress::class)->view($dr, $academic->get($dr->id));
            $studentProgress = $progress[$dr->submitted_by] ?? [];

            return [
                'session_status' => app(DefenseAcademicProgress::class)->sessionStatus($dr),
                'academic_outcome' => $outcome,
                'research_subject' => ($outcome['current'] ?? false) ? $outcome['subject'] : null,
                'subject_enrollment' => $studentProgress['subject_enrollment'] ?? null,
                'current_stage' => $studentProgress['stage'] ?? null,
                'id' => $dr->id, 'student' => $dr->student?->full_name ?: trim($dr->first_name.' '.$dr->last_name),
                'school_id' => $dr->school_id, 'program' => $dr->program, 'title' => $dr->thesis_title,
                'defense_type' => $dr->defense_type, 'date' => $dr->scheduled_date->format('Y-m-d'),
                'start' => substr($dr->scheduled_time ?? '', 0, 5), 'end' => substr($dr->scheduled_end_time ?? '', 0, 5),
                'mode' => $dr->defense_mode, 'venue' => $dr->defense_venue,
                'adviser' => $dr->adviserUser?->full_name ?: $dr->defense_adviser,
                'participants' => collect($roster)->map(fn ($person) => $person + ['affiliation' => $dr->panel_affiliations[$person['recipient_key']] ?? null, 'attendance' => $valid ? ($saved->get($person['recipient_key'])['attendance'] ?? 'pending') : 'pending'])->values(),
                'service_status' => $valid ? 'confirmed' : ($record ? 'needs-reconfirmation' : 'pending'),
                'completed_on' => $valid ? $record->completed_on->format('Y-m-d') : null,
                'has_record' => (bool) $record, 'can_confirm' => $service->canConfirm($request->user(), $dr) && ! $error,
                'roster_error' => $error, 'history' => $service->canConfirm($request->user(), $dr) ? ($record?->history ?? []) : [],
            ];
        });

        $panelists = $canReviewPanelAssignments ? $items->flatMap(fn ($item) => $item['participants'])
            ->filter(fn ($person) => str_starts_with($person['recipient_key'], 'panel:'))
            ->map(fn ($person) => ['id' => (int) explode(':', $person['recipient_key'])[1], 'name' => $person['recipient_name']])
            ->unique('id')->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all() : [];
        if ($panelist !== null) {
            $items = $items->filter(fn ($item) => $item['participants']->contains(
                fn ($person) => str_starts_with($person['recipient_key'], 'panel:'.$panelist.':')
            ));
        }

        return [
            'items' => $items->values()->all(), 'from' => $from, 'to' => $to, 'stage' => $stage,
            'panelist' => $panelist, 'panelists' => $panelists, 'canReviewPanelAssignments' => $canReviewPanelAssignments,
            'today' => now('Asia/Manila')->toDateString(),
        ];
    }

    public function index(Request $request)
    {
        return Inertia::render('defense-register/Index', $this->data($request));
    }

    public function csv(Request $request)
    {
        $data = $this->data($request);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Request ID', 'Date', 'Start (PH)', 'End (PH)', 'Student', 'School ID', 'Program', 'Title', 'Defense stage', 'Mode', 'Venue', 'Adviser', 'Service status', 'Completed on', 'Defense completion', 'Academic result', 'Research subject', 'Next stage', 'Academic outcome current', 'Recorded enrollment', 'Current student stage', 'Panelists']);
            foreach ($data['items'] as $item) {
                $values = array_map(fn ($key) => $item[$key] ?? '', ['id', 'date', 'start', 'end', 'student', 'school_id', 'program', 'title', 'defense_type', 'mode', 'venue', 'adviser', 'service_status', 'completed_on']);
                $outcome = $item['academic_outcome'];
                $values = [...$values, $outcome['completion'] ?? '', $outcome['result'] ?? '', $outcome['subject'] ?? '', $outcome['next_stage'] ?? '', $outcome ? ($outcome['current'] ? 'Yes' : 'Needs reconfirmation') : 'Not recorded'];
                $panelNames = $item['participants']->where('recipient_role', '!=', 'Adviser')->pluck('recipient_name')->implode(', ');
                $values = [...$values, $item['subject_enrollment'], $item['current_stage'], $panelNames];
                fputcsv($out, array_map(fn ($value) => preg_match('/^[\s]*[=+@-]/u', (string) $value) ? "'".$value : $value, $values));
            }
            fclose($out);
        }, 'defense-schedule-'.$data['from'].'-to-'.$data['to'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function export(Request $request, DefenseRegisterExport $export)
    {
        $data = $this->data($request);
        $path = $export->xlsx($data);

        return response()->download($path, 'defense-schedule-'.$data['from'].'-to-'.$data['to'].'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    public function print(Request $request, DefenseRegisterExport $export)
    {
        $data = $this->data($request);

        return response()->view('defense-register-print', $data + $export->matrix($data['items']))->header('Cache-Control', 'private, no-store');
    }

    public function confirm(Request $request, DefenseRequest $defenseRequest, DefenseServiceConfirmation $service)
    {
        abort_unless($service->canConfirm($request->user(), $defenseRequest), 403);
        $data = $request->validate([
            'held' => 'required|accepted', 'completed_on' => 'required|date_format:Y-m-d|before_or_equal:'.now('Asia/Manila')->toDateString(),
            'notes' => 'nullable|string|max:2000', 'participants' => 'required|array|min:1|max:6',
            'participants.*.recipient_key' => 'required|string|distinct', 'participants.*.affiliation' => 'nullable|in:internal,external',
            'participants.*.attendance' => 'required|in:rendered,absent',
        ]);
        $service->confirm($defenseRequest, $request->user(), $data);

        return response()->json(['message' => 'Defense completion and recipient service recorded.']);
    }

    public function classify(Request $request, DefenseRequest $defenseRequest, DefenseServiceConfirmation $service)
    {
        abort_unless($service->canConfirm($request->user(), $defenseRequest), 403);
        $data = $request->validate(['notes' => 'nullable|string|max:2000', 'participants' => 'required|array|min:1|max:5', 'participants.*.recipient_key' => 'required|string|distinct', 'participants.*.affiliation' => 'required|in:internal,external']);
        $service->classify($defenseRequest, $request->user(), $data);

        return response()->json(['message' => 'Panel classifications saved. Any previously verified panel document must be reviewed again.']);
    }
}
