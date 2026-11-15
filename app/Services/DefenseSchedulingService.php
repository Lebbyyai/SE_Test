<?php

namespace App\Services;

use App\Models\DefenseRequest;
use App\Models\Panelist;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DefenseSchedulingService
{
    public const TIME_SLOTS = ['08:00' => '10:00', '10:00' => '12:00', '13:00' => '15:00', '15:00' => '17:00'];

    private function assertTimeSlot(?string $start, ?string $end): void
    {
        if (! isset(self::TIME_SLOTS[$start ?? '']) || self::TIME_SLOTS[$start] !== $end) {
            throw ValidationException::withMessages(['scheduled_time' => 'Choose a required defense slot: 8:00–10:00 AM, 10:00 AM–12:00 PM, 1:00–3:00 PM, or 3:00–5:00 PM (Philippine time).']);
        }
    }

    public function authorize(DefenseRequest $dr, ?User $user): void
    {
        abort_unless($user && $user->role === 'Coordinator' && (int) $dr->coordinator_user_id === (int) $user->id, 403);
    }

    public function locksPanel(DefenseRequest $request): bool
    {
        return in_array(strtolower((string) $request->defense_type), ['prefinal', 'pre-final', 'final'], true);
    }

    public function proposalPanel(DefenseRequest $request, bool $lock = false): ?DefenseRequest
    {
        if (! $this->locksPanel($request) || ! $request->submitted_by || ! filled($request->program)) {
            return null;
        }

        $query = DefenseRequest::where('submitted_by', $request->submitted_by)
            ->where('program', $request->program)
            ->whereRaw('LOWER(defense_type) = ?', ['proposal'])
            ->where('id', '<', $request->id)
            ->latest('id');

        $proposal = ($lock ? $query->lockForUpdate() : $query)->first();

        return $proposal && $proposal->defense_chairperson && $proposal->defense_panelist1
            && app(DefenseAcademicProgress::class)->eligible($proposal) ? $proposal : null;
    }

    public function persist(DefenseRequest $request, array $data, User $user, bool $schedule): DefenseRequest
    {
        return DB::transaction(function () use ($request, $data, $user, $schedule) {
            if (Schema::hasTable('comprehensive_workflow_locks')) {
                app(ComprehensiveWorkflowService::class)->lock();
            }
            if (Schema::hasTable('defense_schedule_mutex')) {
                DB::table('defense_schedule_mutex')->where('id', 1)->lockForUpdate()->first();
            }
            $dr = DefenseRequest::lockForUpdate()->findOrFail($request->id);
            $this->authorize($dr, $user);
            if (! in_array($dr->workflow_state, $schedule ? ['coordinator-approved', 'panels-assigned', 'scheduled'] : ['adviser-approved', 'coordinator-review', 'coordinator-approved', 'panels-assigned', 'scheduled'], true)) {
                throw ValidationException::withMessages(['workflow' => 'This request is not ready for this action.']);
            }
            if ($schedule) {
                $this->assertTimeSlot($data['scheduled_time'] ?? null, $data['scheduled_end_time'] ?? null);
            }
            if (! empty($data['availability_date'])) {
                $this->assertTimeSlot($data['availability_time'] ?? null, $data['availability_end_time'] ?? null);
            }
            $from = $dr->workflow_state;
            if ($this->locksPanel($dr)) {
                if (! $schedule || array_intersect(DefenseConflictService::PANEL_FIELDS, array_keys($data))) {
                    throw ValidationException::withMessages(['panelists' => 'This stage uses the Proposal Defense panel. Only the schedule can be changed.']);
                }
                $proposal = $this->proposalPanel($dr, lock: true);
                if (! $proposal) {
                    throw ValidationException::withMessages(['panelists' => 'A completed and passed Proposal Defense with an assigned chairperson and panel member is required before scheduling.']);
                }

                $dr->fill($proposal->only(DefenseConflictService::PANEL_FIELDS));
                if ($dr->isDirty(DefenseConflictService::PANEL_FIELDS) || ! $dr->panels_assigned_at) {
                    $dr->panels_assigned_at = now();
                    $dr->panels_assigned_by = $user->id;
                    $dr->addWorkflowEntry('panels-assigned', 'Panel carried over from Proposal Defense #'.$proposal->id.'.', $user->id, $from, $from);
                }
            }
            foreach (DefenseConflictService::PANEL_FIELDS as $field) {
                if (array_key_exists($field, $data) && $data[$field]) {
                    $value = (string) $data[$field];
                    if (preg_match('/^adviser-(\\d+)$/', $value, $match)) {
                        $u = User::whereKey($match[1])->whereIn('role', ['Adviser', 'Coordinator', 'Dean'])->first();
                        if (! $u) {
                            throw ValidationException::withMessages([$field => 'Select a registered adviser or panelist.']);
                        }
                        $panel = Panelist::firstOrCreate(['email' => $u->email], ['name' => $u->full_name, 'role' => 'Panel Member', 'status' => 'Not Assigned']);
                    } else {
                        $id = preg_replace('/^panelist-/', '', $value);
                        $matches = is_numeric($id) ? Panelist::whereKey($id)->get() : Panelist::where('name', $value)->get();
                        if ($matches->count() !== 1) {
                            throw ValidationException::withMessages([$field => 'Select an identifiable registered panelist.']);
                        }
                        $panel = $matches->first();
                    }
                    $data[$field] = (string) $panel->id;
                }
            }
            $availability = array_intersect_key($data, array_flip(['availability_date', 'availability_time', 'availability_end_time']));
            $data = array_diff_key($data, $availability);
            $dr->fill($data);
            if (! empty($availability['availability_date'])) {
                $proposed = clone $dr;
                $proposed->scheduled_date = $availability['availability_date'];
                $proposed->scheduled_time = $availability['availability_time'];
                $proposed->scheduled_end_time = $availability['availability_end_time'];
                // Validate the draft slot inside the same scheduling lock; do not save it yet.
                app(DefenseConflictService::class)->assertNoConflict($proposed);
            }
            app(DefenseConflictService::class)->assertNoConflict($dr);
            if ($schedule) {
                if (! $dr->defense_chairperson || ! $dr->defense_panelist1) {
                    throw ValidationException::withMessages(['panelists' => 'Assign a chairperson and at least one panel member before scheduling.']);
                }
                if (CarbonImmutable::parse($dr->scheduled_date->format('Y-m-d').' '.$dr->scheduled_time, 'Asia/Manila') <= CarbonImmutable::now('Asia/Manila')) {
                    throw ValidationException::withMessages(['scheduled_date' => 'Choose a future date and time in Philippine time.']);
                }
                $dr->workflow_state = 'scheduled';
                $dr->schedule_set_by = $user->id;
            } else {
                $dr->panels_assigned_at = now();
                $dr->panels_assigned_by = $user->id;
                if ($from !== 'scheduled') {
                    $dr->workflow_state = 'panels-assigned';
                }
            }
            $dr->last_status_updated_at = now();
            $dr->last_status_updated_by = $user->id;
            $dr->addWorkflowEntry($schedule ? ($from === 'scheduled' ? 'rescheduled' : 'scheduled') : 'panels-updated', $schedule ? ($data['scheduling_notes'] ?? null) : 'Panel assignments updated.', $user->id, $from, $dr->workflow_state);
            $dr->save();

            return $dr;
        });
    }
}
