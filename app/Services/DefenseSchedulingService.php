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
    public function authorize(DefenseRequest $dr, ?User $user): void
    {
        abort_unless($user && in_array($user->role, ['Coordinator', 'Administrative Assistant', 'Dean'], true), 403);
        if ($user->role === 'Coordinator') {
            abort_unless((int) $dr->coordinator_user_id === (int) $user->id, 403);
        }
    }

    public function persist(DefenseRequest $request, array $data, User $user, bool $schedule): DefenseRequest
    {
        return DB::transaction(function () use ($request, $data, $user, $schedule) {
            if (Schema::hasTable('defense_schedule_mutex')) {
                DB::table('defense_schedule_mutex')->where('id', 1)->lockForUpdate()->first();
            }
            $dr = DefenseRequest::lockForUpdate()->findOrFail($request->id);
            $this->authorize($dr, $user);
            if (! in_array($dr->workflow_state, $schedule ? ['coordinator-approved', 'panels-assigned', 'scheduled'] : ['adviser-approved', 'coordinator-review', 'coordinator-approved', 'panels-assigned', 'scheduled'], true)) {
                throw ValidationException::withMessages(['workflow' => 'This request is not ready for this action.']);
            }
            $from = $dr->workflow_state;
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
