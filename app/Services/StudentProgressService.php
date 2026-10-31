<?php

namespace App\Services;

use App\Models\DefenseRequest;
use App\Models\DefenseServiceRecord;
use App\Models\LegacyGradesData;
use App\Models\LegacyScrapedData;
use App\Models\StudentProgressRecord;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class StudentProgressService
{
    public function stage(?string $type): ?string
    {
        return match (strtolower(preg_replace('/[\s-]+/', '', $type ?? ''))) {
            'proposal' => 'Proposal', 'prefinal' => 'Prefinal', 'final' => 'Final', default => null,
        };
    }

    public function status(DefenseRequest $request, bool $held = false): string
    {
        return match ($request->workflow_state) {
            'submitted', 'adviser-review' => 'Awaiting adviser review',
            'adviser-approved', 'coordinator-review' => 'Awaiting coordinator review',
            'adviser-corrections', 'coordinator-corrections' => 'Returned for corrections',
            'adviser-rejected' => 'Rejected by adviser', 'coordinator-rejected', 'rejected' => 'Rejected',
            'cancelled', 'canceled', 'withdrawn' => 'Cancelled',
            'coordinator-approved' => 'Coordinator approved', 'panels-assigned' => 'Panel assigned',
            'scheduled' => $held ? 'Defense held (confirmed)' : 'Defense scheduled',
            'completed' => $held ? 'Defense held (confirmed)' : 'Completion not confirmed',
            default => 'Status not available',
        };
    }

    public function current(Collection $requests): ?DefenseRequest
    {
        return $requests->first(fn ($row) => ! in_array(strtolower($row->status ?? ''), ['cancelled', 'canceled', 'rejected'], true)
            && ! in_array($row->workflow_state, ['adviser-rejected', 'coordinator-rejected', 'rejected', 'cancelled', 'canceled', 'withdrawn'], true))
            ?? $requests->first();
    }

    public function summaries(Collection $students): array
    {
        $ids = $students->modelKeys();
        $requests = DefenseRequest::whereIn('submitted_by', $ids)->latest('created_at')->orderByDesc('id')->get()->groupBy('submitted_by');
        $records = StudentProgressRecord::whereIn('student_id', $ids)->get()->keyBy('student_id');
        $held = $this->confirmedServices($requests->flatten())->keys()->all();

        return $students->mapWithKeys(function ($student) use ($requests, $records, $held) {
            $current = $this->current($requests->get($student->id, collect()));
            $record = $records->get($student->id);

            return [$student->id => [
                'stage' => $this->stage($current?->defense_type),
                'status' => $current ? $this->status($current, in_array($current->id, $held, true)) : 'No defense request',
                'enrollment_year' => $record?->enrollment_year, 'completion_year' => $record?->completion_year,
            ]];
        })->all();
    }

    public function detail(User $student, User $adviser): array
    {
        $requests = DefenseRequest::where('submitted_by', $student->id)->latest('created_at')->orderByDesc('id')->get();
        $services = $this->confirmedServices($requests);
        $record = StudentProgressRecord::with('recorder')->where('student_id', $student->id)->first();
        $current = $this->current($requests);
        $defenses = $requests->map(function ($row) use ($services, $adviser) {
            $service = $services->get($row->id);
            $history = $row->getRawOriginal('workflow_history');
            $history = is_string($history) ? json_decode($history, true) : $history;

            return [
                'id' => $row->id, 'title' => $row->thesis_title, 'stage' => $this->stage($row->defense_type),
                'status' => $this->status($row, $service !== null), 'submitted_at' => $row->submitted_at?->toIso8601String() ?? $row->created_at?->toIso8601String(),
                'date' => $row->scheduled_date?->format('Y-m-d'), 'start' => $row->scheduled_time, 'end' => $row->scheduled_end_time,
                'held_on' => $service?->completed_on?->format('Y-m-d'),
                'details_url' => (int) $row->adviser_user_id === (int) $adviser->id ? '/adviser/defense-requirements/'.$row->id.'/details' : null,
                'history' => collect(is_array($history) ? $history : [])->filter(fn ($entry) => is_array($entry))->map(fn ($entry) => [
                    'event' => $entry['event'] ?? $entry['action'] ?? 'Update',
                    'at' => $entry['at'] ?? $entry['created_at'] ?? $entry['timestamp'] ?? null,
                ])->values(),
            ];
        })->values();

        return [
            'student' => ['id' => $student->id, 'name' => $student->name, 'school_id' => $student->school_id ?: $student->student_number,
                'email' => $student->email, 'program' => $student->program],
            'stage' => $this->stage($current?->defense_type),
            'status' => $current ? $this->status($current, $services->has($current->id)) : 'No defense request',
            'record' => $record ? [
                'enrollment_year' => $record->enrollment_year, 'completion_year' => $record->completion_year,
                'semester' => $record->semester, 'subjects' => $record->subjects ?? [], 'record_reference' => $record->record_reference,
                'recorded_by' => $record->recorder?->name ?? 'Former staff member', 'recorded_at' => $record->recorded_at->toIso8601String(),
                'version' => $record->version,
                'history' => collect($record->history ?? [])->map(fn ($entry) => collect($entry)->only(['at', 'actor_name', 'record_reference', 'enrollment_year', 'completion_year', 'semester'])->all())->values(),
            ] : null,
            'defenses' => $defenses,
            'portal' => $this->portalSubjects($student),
        ];
    }

    private function confirmedServices(Collection $requests): Collection
    {
        $services = DefenseServiceRecord::whereIn('defense_request_id', $requests->pluck('id'))->get()->keyBy('defense_request_id');

        return $requests->filter(function ($request) use ($services) {
            $service = $services->get($request->id);
            if (! $service || $request->workflow_state !== 'completed' || in_array(strtolower($request->status ?? ''), ['cancelled', 'canceled', 'rejected'], true)) {
                return false;
            }
            try {
                return hash_equals($service->snapshot_hash, app(DefenseServiceConfirmation::class)->snapshot($request));
            } catch (ValidationException) {
                return false;
            }
        })->mapWithKeys(fn ($request) => [$request->id => $services->get($request->id)]);
    }

    private function portalSubjects(User $student): array
    {
        $missing = ['semester' => null, 'synced_at' => null, 'subjects' => []];
        // Only read cached school data. Viewing progress never logs into a school account.
        $cached = Cache::get('scraped_data_user_'.$student->id);
        if (is_array($cached) && ! empty($cached['success']) && ! empty($cached['current_semester_id'])) {
            $semester = $cached['all_semester_grades'][$cached['current_semester_id']] ?? null;
            if (is_array($semester) && is_array($semester['grades'] ?? null)) {
                return ['semester' => $semester['semester_label'] ?? null, 'synced_at' => $semester['timestamp'] ?? $cached['timestamp'] ?? null,
                    'subjects' => collect($semester['grades'])->map(fn ($row) => ['code' => $row['code'] ?? $row['course_code'] ?? null,
                        'title' => $row['title'] ?? $row['course_title'] ?? null, 'section' => $row['section'] ?? null])->values()->all()];
            }
        }
        $snapshot = LegacyScrapedData::where('user_id', $student->id)->where('scraping_success', true)->latest('scraped_at')->first();
        if (! $snapshot?->current_semester_id) {
            return $missing;
        }
        $subjects = LegacyGradesData::where('user_id', $student->id)->where('legacy_scraped_data_id', $snapshot->id)
            ->where('semester_id', $snapshot->current_semester_id)->orderBy('course_code')->get();

        return ['semester' => $subjects->first()?->semester_label, 'synced_at' => $snapshot->scraped_at?->toIso8601String(),
            'subjects' => $subjects->map(fn ($row) => ['code' => $row->course_code, 'title' => $row->course_title, 'section' => $row->section])->all()];
    }
}
