<?php

namespace App\Http\Controllers;

use App\Models\DefenseRequest;
use App\Models\Panelist;
use App\Services\DefenseConflictService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DefenseScheduleController extends Controller
{
    /**
     * Display the defense schedule calendar view
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Ensure user is authorized
        $coordinatorRoles = ['Coordinator', 'Administrative Assistant', 'Dean'];
        if (! in_array($user->role, $coordinatorRoles)) {
            abort(403, 'Unauthorized access to defense schedule');
        }

        // Get all scheduled defenses
        $scheduledDefenses = DefenseRequest::with(['user', 'panelsAssignedBy', 'scheduleSetBy'])
            ->whereNotNull('scheduled_date')
            ->whereNotNull('scheduled_time')
            ->whereIn('workflow_state', ['scheduled', 'completed'])
            ->orderBy('scheduled_date', 'asc')
            ->orderBy('scheduled_time', 'asc')
            ->get()
            ->map(function ($defense) {
                return [
                    'id' => $defense->id,
                    'student_name' => $defense->first_name.' '.$defense->last_name,
                    'school_id' => $defense->school_id,
                    'program' => $defense->program,
                    'thesis_title' => $defense->thesis_title,
                    'defense_type' => $defense->defense_type,
                    'scheduled_date' => $defense->scheduled_date->format('Y-m-d'),
                    'scheduled_time' => substr((string) $defense->scheduled_time, 0, 5),
                    'defense_mode' => $defense->defense_mode,
                    'defense_venue' => $defense->defense_venue,
                    'panels_list' => $defense->panels_list,
                    'workflow_state' => $defense->workflow_state,
                    'scheduling_notes' => $defense->scheduling_notes,
                ];
            });

        return Inertia::render('coordinator/schedule/DefenseScheduleCalendar', [
            'scheduledDefenses' => $scheduledDefenses,
            'user' => $user,
        ]);
    }

    /**
     * API endpoint for calendar data
     */
    public function calendar(Request $request)
    {
        $defenses = DefenseRequest::whereNotNull('scheduled_date')
            ->whereNotNull('scheduled_time')
            ->whereIn('workflow_state', ['scheduled', 'completed'])
            ->get()
            ->map(function ($defense) {
                return [
                    'id' => $defense->id,
                    'title' => $defense->thesis_title,
                    'student_name' => $defense->first_name.' '.$defense->last_name,
                    'school_id' => $defense->school_id,
                    'program' => $defense->program,
                    'defense_type' => $defense->defense_type,
                    'date_of_defense' => $defense->scheduled_date->format('Y-m-d H:i:s'),
                    'scheduled_date' => $defense->scheduled_date->format('Y-m-d'),
                    'scheduled_time' => $defense->scheduled_time->format('H:i'),
                    'defense_mode' => $defense->defense_mode,
                    'defense_venue' => $defense->defense_venue,
                    'status' => ucfirst($defense->workflow_state),
                    'panels' => $defense->panels_list,
                    'scheduling_notes' => $defense->scheduling_notes,
                ];
            });

        return response()->json($defenses);
    }

    /**
     * Get conflicts for a specific date and time
     */
    public function checkConflicts(Request $request)
    {
        abort_unless(in_array($request->user()?->role, ['Coordinator', 'Administrative Assistant', 'Dean'], true), 403);
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i', 'end_time' => 'nullable|date_format:H:i|after:time', 'panels' => 'present|array', 'venue' => 'nullable|string', 'exclude_id' => 'nullable|integer']);
        $dr = new DefenseRequest;
        $dr->id = $data['exclude_id'] ?? 0;
        $dr->scheduled_date = $data['date'];
        $dr->scheduled_time = $data['time'];
        $dr->scheduled_end_time = $data['end_time'] ?? Carbon::parse($data['time'])->addHour()->format('H:i');
        $dr->defense_venue = $data['venue'] ?? null;
        foreach (DefenseConflictService::PANEL_FIELDS as $i => $field) {
            $dr->{$field} = $data['panels'][$i] ?? null;
        }
        $service = app(DefenseConflictService::class);
        $conflicts = $service->findPanelSchedulingConflicts($dr, [], $data['date'], $data['time'], $dr->scheduled_end_time);
        if ($service->hasVenueConflict($dr, $dr->defense_venue, $data['date'], $data['time'], $dr->scheduled_end_time)) {
            $conflicts[] = ['type' => 'venue', 'message' => 'The venue has an overlapping booking.'];
        }

        return response()->json(['has_conflicts' => count($conflicts) > 0, 'conflicts' => $conflicts]);
    }

    public function availablePanelists(Request $request)
    {
        abort_unless(in_array($request->user()?->role, ['Coordinator', 'Administrative Assistant', 'Dean'], true), 403);
        $data = $request->validate(['date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i', 'end_time' => 'nullable|date_format:H:i|after:time', 'exclude_id' => 'nullable|integer']);
        $dr = new DefenseRequest;
        $dr->id = $data['exclude_id'] ?? 0;
        $end = $data['end_time'] ?? Carbon::parse($data['time'])->addHour()->format('H:i');
        $service = app(DefenseConflictService::class);
        $busy = [];
        foreach ($service->overlapping($dr, $data['date'], $data['time'], $end) as $other) {
            $busy = array_merge($busy, array_keys($service->people($other)));
        }

        return response()->json(Panelist::orderBy('name')->get()->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'email' => $p->email, 'status' => $p->status, 'is_available' => ! in_array($service->identity($p->id), $busy, true)]));
    }
}
