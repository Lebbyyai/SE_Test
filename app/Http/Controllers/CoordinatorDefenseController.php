<?php

namespace App\Http\Controllers;

use App\Mail\DefensePanelInvitation;
use App\Mail\DefenseScheduled;
use App\Mail\DefenseScheduledAdviser;
use App\Mail\DefenseScheduledStudent;
use App\Models\DefenseRequest;
use App\Models\Notification;
use App\Models\Panelist;
use App\Models\User;
use App\Services\DefenseNotificationService;
use App\Services\DefenseSchedulingService;
use App\Services\PanelAvailabilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;

class CoordinatorDefenseController extends Controller
{
    private array $roles = ['Coordinator', 'Administrative Assistant', 'Dean'];

    private function authorizeRole(): void
    {
        $u = Auth::user();
        if (! $u || ! in_array($u->role, $this->roles)) {
            abort(403);
        }
    }

    /**
     * Dashboard
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $coordinatorRoles = ['Coordinator', 'Administrative Assistant', 'Dean'];
        if (! in_array($user->role, $coordinatorRoles)) {
            abort(403, 'Unauthorized access to coordinator dashboard');
        }

        $defenseRequests = DefenseRequest::with(['user', 'adviserUser', 'panelsAssignedBy', 'scheduleSetBy'])
            ->whereIn('workflow_state', ['adviser-approved', 'coordinator-review', 'coordinator-corrections', 'coordinator-approved', 'coordinator-rejected', 'panels-assigned', 'scheduled', 'completed'])
            ->where('coordinator_user_id', $user->id) // <-- Only show requests for this coordinator
            ->orderBy('adviser_reviewed_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
//            ->each(fn($r)=>$r->ensureStatusWorkflowSync(true))   // REMOVE: was promoting states too early
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'student_name' => $r->first_name.' '.$r->last_name,
                    'school_id' => $r->school_id,
                    'program' => $r->program,
                    'thesis_title' => $r->thesis_title,
                    'defense_type' => $r->defense_type,
                    'adviser' => $r->defense_adviser,
                    'workflow_state' => $r->workflow_state,
                    'workflow_state_display' => $r->workflow_state_display,
                    'scheduling_status' => $r->scheduling_status,
                    'formatted_schedule' => $r->formatted_schedule,
                    'panels_list' => $r->panels_list,
                    'defense_chairperson' => $r->defense_chairperson,
                    'defense_panelist1' => $r->defense_panelist1,
                    'defense_panelist2' => $r->defense_panelist2,
                    'defense_panelist3' => $r->defense_panelist3,
                    'defense_panelist4' => $r->defense_panelist4,
                    'scheduled_date' => $r->scheduled_date?->format('Y-m-d'),
                    'scheduled_time' => $r->scheduled_time?->format('H:i'),
                    'scheduled_end_time' => $r->scheduled_end_time?->format('H:i'),
                    'defense_duration_minutes' => $r->defense_duration_minutes,
                    'formatted_time_range' => $r->formatted_time_range,
                    'defense_mode' => $r->defense_mode,
                    'defense_venue' => $r->defense_venue,
                    'scheduling_notes' => $r->scheduling_notes,
                    'panels_assigned_at' => $r->panels_assigned_at?->format('M d, Y g:i A'),
                    'schedule_set_at' => $r->schedule_set_at?->format('M d, Y g:i A'),
                    'adviser_notified_at' => $r->adviser_notified_at?->format('M d, Y g:i A'),
                    'student_notified_at' => $r->student_notified_at?->format('M d, Y g:i A'),
                    'panels_notified_at' => $r->panels_notified_at?->format('M d, Y g:i A'),
                    'submitted_at' => $r->submitted_at?->format('M d, Y g:i A'),
                    'adviser_reviewed_at' => $r->adviser_reviewed_at?->format('M d, Y g:i A'),
                ];
            });

        $adviserMembers = User::where('role', 'Adviser')
            ->select('id', 'first_name', 'last_name', 'email')
            ->orderBy('last_name')
            ->get()
            ->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->first_name.' '.$f->last_name,
                'email' => $f->email ?? '',
                'type' => 'adviser',
            ]);

        $panelists = Panelist::where('status', 'Available')
            ->select('id', 'name', 'email', 'status')
            ->orderBy('name')
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'email' => $p->email,
                'type' => 'panelist',
            ]);

        $availablePanelMembers = $adviserMembers->concat($panelists)->values();

        $stats = [
            'pending_panels' => DefenseRequest::where('scheduling_status', 'pending-panels')
                ->whereIn('workflow_state', ['adviser-approved', 'coordinator-review', 'coordinator-approved'])
                ->count(),
            'panels_assigned' => DefenseRequest::where('scheduling_status', 'panels-assigned')->count(),
            'scheduled' => DefenseRequest::where('scheduling_status', 'scheduled')->count(),
            'completed' => DefenseRequest::where('scheduling_status', 'completed')->count(),
        ];

        return Inertia::render('coordinator/defense-management/CoordinatorDefenseDashboard', [
            'defenseRequests' => $defenseRequests,
            'adviserMembers' => $adviserMembers,
            'panelists' => $panelists,
            'availablePanelMembers' => $availablePanelMembers,
            'stats' => $stats,
            'user' => $user,
        ]);
    }

    public function assignPanels(Request $request, DefenseRequest $defenseRequest)
    {
        return $this->assignPanelsJson($request, $defenseRequest);
    }

    public function scheduleDefense(Request $request, DefenseRequest $defenseRequest)
    {
        return $this->scheduleDefenseJson($request, $defenseRequest);
    }

    public function sendNotifications(Request $request, DefenseRequest $defenseRequest)
    {
        $validated = $request->validate([
            'notify_parties' => 'required|array',
            'notify_parties.*' => 'in:adviser,student,panels',
        ]);

        try {
            $service = new DefenseNotificationService;
            $notified = $service->sendSchedulingNotifications($defenseRequest, $validated['notify_parties']);
            $defenseRequest->notifyParties($validated['notify_parties']);

            return back()->with('success', 'Notifications sent: '.implode(', ', $notified));
        } catch (\Throwable $e) {
            Log::error('Notification send failed', [
                'defense_request_id' => $defenseRequest->id,
                'error' => $e->getMessage(),
            ]);

            return back()->withErrors(['error' => 'Failed to send notifications.']);
        }
    }

    public function updateDefense(Request $request, DefenseRequest $defenseRequest)
    {
        if ($request->filled('scheduled_date')) {
            return $this->scheduleDefenseJson($request, $defenseRequest);
        }

        return $this->assignPanelsJson($request, $defenseRequest);
    }

    public function show(DefenseRequest $defenseRequest)
    {
        $defenseRequest->load(['user', 'adviserUser', 'panelsAssignedBy', 'scheduleSetBy']);

        return response()->json([
            'id' => $defenseRequest->id,
            'student_name' => $defenseRequest->first_name.' '.$defenseRequest->last_name,
            'school_id' => $defenseRequest->school_id,
            'program' => $defenseRequest->program,
            'thesis_title' => $defenseRequest->thesis_title,
            'defense_type' => $defenseRequest->defense_type,
            'adviser' => $defenseRequest->defense_adviser,
            'defense_chairperson' => $defenseRequest->defense_chairperson,
            'defense_panelist1' => $defenseRequest->defense_panelist1,
            'defense_panelist2' => $defenseRequest->defense_panelist2,
            'defense_panelist3' => $defenseRequest->defense_panelist3,
            'defense_panelist4' => $defenseRequest->defense_panelist4,
            'scheduled_date' => $defenseRequest->scheduled_date?->format('Y-m-d'),
            'scheduled_time' => $defenseRequest->scheduled_time ? substr((string) $defenseRequest->scheduled_time, 0, 5) : null,
            'defense_mode' => $defenseRequest->defense_mode,
            'defense_venue' => $defenseRequest->defense_venue,
            'scheduling_notes' => $defenseRequest->scheduling_notes,
            'workflow_history' => $defenseRequest->workflow_history,
        ]);
    }

    private function createSchedulingNotifications(DefenseRequest $defenseRequest, string $scheduleDate, string $timeRange, array $validated)
    {
        if ($defenseRequest->submitted_by) {
            // DefenseRequestObserver creates the student's in-app schedule notice.

            // Send email notification to student
            $student = User::find($defenseRequest->submitted_by);
            if ($student && $student->email) {
                Mail::to($student->email)
                    ->queue(new DefenseScheduled($defenseRequest, $student));
            }
        }

        $panelMembers = array_filter([
            $defenseRequest->defense_chairperson,
            $defenseRequest->defense_panelist1,
            $defenseRequest->defense_panelist2,
            $defenseRequest->defense_panelist3,
            $defenseRequest->defense_panelist4,
        ]);

        foreach ($panelMembers as $panelMemberName) {
            $panelUser = User::where(function ($q) use ($panelMemberName) {
                $parts = preg_split('/\s+/', trim($panelMemberName));
                if (count($parts) >= 2) {
                    $q->where('first_name', 'LIKE', '%'.$parts[0].'%')
                        ->where('last_name', 'LIKE', '%'.end($parts).'%');
                } else {
                    $q->where('first_name', 'LIKE', '%'.$panelMemberName.'%')
                        ->orWhere('last_name', 'LIKE', '%'.$panelMemberName.'%');
                }
            })->first();

            if ($panelUser) {
                Notification::create([
                    'user_id' => $panelUser->id,
                    'type' => 'defense-request',
                    'title' => 'Panel Assignment',
                    'message' => "Assigned to {$defenseRequest->first_name} {$defenseRequest->last_name}'s defense: {$scheduleDate} {$timeRange} at {$validated['defense_venue']}",
                    'link' => '/defense-requests',
                ]);
            }
        }

        if ($defenseRequest->adviser_user_id && ! in_array($defenseRequest->adviser_user_id, $panelMembers)) {
            Notification::create([
                'user_id' => $defenseRequest->adviser_user_id,
                'type' => 'defense-request',
                'title' => "Student's Defense Scheduled",
                'message' => "Defense for {$defenseRequest->first_name} {$defenseRequest->last_name}: {$scheduleDate} {$timeRange}.",
                'link' => '/defense-requests',
            ]);
        }
    }

    public function getRequestsForApproval(Request $request)
    {
        $user = Auth::user();
        $coordinatorRoles = ['Coordinator', 'Administrative Assistant', 'Dean'];
        if (! in_array($user->role, $coordinatorRoles)) {
            abort(403, 'Unauthorized access');
        }

        $defenseRequests = DefenseRequest::with(['user', 'adviserUser'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($r) {
                return [
                    'id' => $r->id,
                    'first_name' => $r->first_name,
                    'middle_name' => $r->middle_name,
                    'last_name' => $r->last_name,
                    'program' => $r->program,
                    'thesis_title' => $r->thesis_title,
                    'date_of_defense' => $r->scheduled_date?->format('Y-m-d H:i:s'),
                    'defense_type' => $r->defense_type,
                    'mode_defense' => $r->defense_mode,
                    'status' => $this->mapToReactStatus($r->workflow_state),
                    'priority' => $r->priority ?? 'Medium',
                    'last_status_updated_by' => $r->lastStatusUpdatedBy?->name,
                    'last_status_updated_at' => $r->last_status_updated_at?->format('Y-m-d H:i:s'),
                ];
            });

        return response()->json($defenseRequests);
    }

    private function mapToReactStatus($workflowState)
    {
        $mapping = [
            'adviser-approved' => 'Pending',
            'coordinator-review' => 'Pending',
            'coordinator-approved' => 'Approved',
            'panels-assigned' => 'Approved',
            'scheduled' => 'Approved',
            'rejected' => 'Rejected',
        ];

        return $mapping[$workflowState] ?? 'Pending';
    }

    private function normalizeDefenseRequestStatus(DefenseRequest $r): string
    {
        $raw = $r->status ?? '';
        if (in_array($raw, ['Approved', 'Rejected', 'Pending'])) {
            return $raw;
        }

        $lc = strtolower($raw);
        if (str_contains($lc, 'approved')) {
            return 'Approved';
        }
        if (str_contains($lc, 'rejected')) {
            return 'Rejected';
        }

        return match ($r->workflow_state) {
            'submitted','adviser-review','adviser-approved','coordinator-review','needs-info' => 'Pending',
            'coordinator-approved','panels-assigned','scheduled','completed' => 'Approved',
            'adviser-rejected','coordinator-rejected','rejected' => 'Rejected',
            default => 'Pending'
        };
    }

    /**
     * Coordinator approves a defense request (new)
     * Validates required fields and sends emails to all parties
     */
    public function approve(Request $request, DefenseRequest $defenseRequest)
    {
        if (in_array($defenseRequest->workflow_state, ['adviser-corrections', 'coordinator-corrections'], true)) {
            return response()->json(['error' => 'Wait for the student to resubmit corrections before continuing this request.'], 422);
        }

        $this->authorizeRole();

        // Allow approval if adviser-approved OR coordinator-review OR still submitted legacy
        if (! in_array($defenseRequest->workflow_state, ['adviser-approved', 'coordinator-review', 'submitted', null])) {
            return back()->withErrors(['error' => 'Cannot approve in current state.']);
        }

        // Validate required fields before approval
        $missingFields = [];

        // Check schedule fields
        if (empty($defenseRequest->scheduled_date)) {
            $missingFields[] = 'Defense Date';
        }
        if (empty($defenseRequest->scheduled_time)) {
            $missingFields[] = 'Defense Start Time';
        }
        if (empty($defenseRequest->scheduled_end_time)) {
            $missingFields[] = 'Defense End Time';
        }
        if (empty($defenseRequest->defense_mode)) {
            $missingFields[] = 'Defense Mode (Face-to-face/Online)';
        }
        if (empty($defenseRequest->defense_venue)) {
            $missingFields[] = 'Defense Venue';
        }

        // Check if panels are assigned
        $panels = $defenseRequest->panelists()->get();
        if ($panels->isEmpty()) {
            $missingFields[] = 'Panel Members (at least one panel member must be assigned)';
        }

        // If there are missing fields, return error
        if (! empty($missingFields)) {
            $errorMessage = 'Cannot approve defense request. The following required fields are missing:';

            return back()->withErrors([
                'error' => $errorMessage,
                'missing_fields' => $missingFields,
            ]);
        }

        try {
            DB::beginTransaction();

            // Approve the defense request
            $comment = $request->input('comment');
            $defenseRequest->approveByCoordinator($comment, Auth::id());

            // Send emails to all parties
            $this->sendDefenseNotificationEmails($defenseRequest);

            DB::commit();

            return back()->with('success', 'Defense request approved successfully! Notification emails have been sent to all parties.');

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to approve defense request and send emails', [
                'error' => $e->getMessage(),
                'defense_request_id' => $defenseRequest->id,
            ]);

            return back()->withErrors([
                'error' => 'Failed to approve defense request: '.$e->getMessage(),
            ]);
        }
    }

    /**
     * Send notification emails to all parties involved in the defense
     */
    private function sendDefenseNotificationEmails(DefenseRequest $defenseRequest)
    {
        $emailsSent = [];
        $emailsFailed = [];

        // 1. Send email to student
        $student = $defenseRequest->user;
        try {
            if ($student && $student->email) {
                Mail::to($student->email)->send(new DefenseScheduledStudent($defenseRequest));
                $emailsSent[] = "Student: {$student->email}";
                Log::info('Defense notification sent to student', [
                    'defense_request_id' => $defenseRequest->id,
                    'student_email' => $student->email,
                ]);
            }
        } catch (\Exception $e) {
            $studentEmail = $student ? $student->email : 'N/A';
            $emailsFailed[] = "Student: {$studentEmail}";
            Log::error('Failed to send defense notification to student', [
                'defense_request_id' => $defenseRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 2. Send email to adviser
        $adviser = $defenseRequest->adviserUser;
        try {
            if ($adviser && $adviser->email) {
                Mail::to($adviser->email)->send(new DefenseScheduledAdviser($defenseRequest));
                $emailsSent[] = "Adviser: {$adviser->email}";
                Log::info('Defense notification sent to adviser', [
                    'defense_request_id' => $defenseRequest->id,
                    'adviser_email' => $adviser->email,
                ]);
            }
        } catch (\Exception $e) {
            $adviserEmail = $adviser ? $adviser->email : 'N/A';
            $emailsFailed[] = "Adviser: {$adviserEmail}";
            Log::error('Failed to send defense notification to adviser', [
                'defense_request_id' => $defenseRequest->id,
                'error' => $e->getMessage(),
            ]);
        }

        // 3. Send emails to panel members
        $panels = $defenseRequest->panelists()->get();
        foreach ($panels as $panel) {
            try {
                if ($panel->email) {
                    $role = isset($panel->pivot->role) ? $panel->pivot->role : 'Panel Member';
                    Mail::to($panel->email)->send(new DefensePanelInvitation(
                        $defenseRequest,
                        $panel,
                        $role
                    ));
                    $emailsSent[] = "Panel ({$role}): {$panel->email}";
                    Log::info('Defense panel invitation sent', [
                        'defense_request_id' => $defenseRequest->id,
                        'panel_email' => $panel->email,
                        'role' => $role,
                    ]);
                }
            } catch (\Exception $e) {
                $panelEmail = isset($panel->email) ? $panel->email : 'N/A';
                $emailsFailed[] = "Panel: {$panelEmail}";
                Log::error('Failed to send defense panel invitation', [
                    'defense_request_id' => $defenseRequest->id,
                    'panel_email' => $panelEmail,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Log summary
        Log::info('Defense notification emails summary', [
            'defense_request_id' => $defenseRequest->id,
            'emails_sent_count' => count($emailsSent),
            'emails_failed_count' => count($emailsFailed),
            'emails_sent' => $emailsSent,
            'emails_failed' => $emailsFailed,
        ]);
    }

    /**
     * JSON for show-all-requests.tsx
     */
    public function allDefenseRequests(Request $request)
    {
        $user = Auth::user();
        if (! $user || ! in_array($user->role, ['Coordinator', 'Administrative Assistant', 'Dean'])) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $visibleStates = [
            'adviser-approved',
            'coordinator-review',
            'coordinator-approved',
            'panels-assigned',
            'scheduled',
            'completed',
        ];

        $items = DefenseRequest::with(['user', 'adviserUser', 'panelsAssignedBy', 'scheduleSetBy', 'lastStatusUpdater'])
            ->whereIn('workflow_state', $visibleStates)
            ->whereNotIn('workflow_state', ['submitted', 'adviser-review']) // defensive guard
            ->orderBy('adviser_reviewed_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($r) => $this->mapDefenseRequestForList($r));

        return response()->json($items->values());
    }

    private function mapDefenseRequestForList(DefenseRequest $r): array
    {
        $status = $this->normalizeDefenseRequestStatus($r);

        $adviserName = $r->defense_adviser ?: '—';
        $submittedAt = $r->adviser_reviewed_at
            ? (is_object($r->adviser_reviewed_at)
                ? $r->adviser_reviewed_at->format('Y-m-d H:i:s')
                : date('Y-m-d H:i:s', strtotime($r->adviser_reviewed_at)))
            : '—';

        $adviser = $r->adviserUser;

        return [
            'adviser_profile' => $adviser ? ['id' => $adviser->id, 'name' => $adviser->name, 'email' => $adviser->email] : null,
            'adviser_status' => $r->adviser_status,
            'coordinator_status' => $r->coordinator_status,
            'id' => $r->id,
            'first_name' => $r->first_name,
            'middle_name' => $r->middle_name,
            'last_name' => $r->last_name,
            'program' => $r->program,
            'thesis_title' => $r->thesis_title,
            'date_of_defense' => $r->scheduled_date?->format('Y-m-d') ?? '',
            'mode_defense' => $r->defense_mode ?? '',
            'defense_type' => $r->defense_type,
            'status' => $status,
            'workflow_state' => $r->workflow_state,
            'priority' => $r->priority,
            'adviser' => $adviserName,
            'submitted_at' => $submittedAt,
            'defense_chairperson' => $r->defense_chairperson,
            'defense_panelist1' => $r->defense_panelist1,
            'defense_panelist2' => $r->defense_panelist2,
            'defense_panelist3' => $r->defense_panelist3,
            'defense_panelist4' => $r->defense_panelist4,
            'scheduled_date' => $r->scheduled_date?->format('Y-m-d'),
            'scheduled_time' => $r->scheduled_time?->format('H:i'),
            'scheduled_end_time' => $r->scheduled_end_time?->format('H:i'),
            'defense_mode' => $r->defense_mode,
            'defense_venue' => $r->defense_venue,
            'scheduling_notes' => $r->scheduling_notes,
            'last_status_updated_by' => $r->lastStatusUpdater?->name,
            'last_status_updated_at' => $r->last_status_updated_at?->format('Y-m-d H:i:s'),
            'panelists' => collect([
                $r->defense_chairperson ?? null,
                $r->defense_panelist1 ?? null,
                $r->defense_panelist2 ?? null,
                $r->defense_panelist3 ?? null,
                $r->defense_panelist4 ?? null,
            ])->filter()->values()->all(),
        ];
    }

    /**
     * Adviser + available panelists
     */
    public function panelMembersAll(Request $request)
    {
        $this->authorizeRole();

        return app(PanelistController::class)->allCombined();
    }

    public function panelAvailability(Request $request, DefenseRequest $defenseRequest)
    {
        app(DefenseSchedulingService::class)->authorize($defenseRequest, $request->user());
        $data = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:time',
        ]);
        $options = app(PanelistController::class)->allCombined()->getData(true);

        return response()->json(app(PanelAvailabilityService::class)->forSlot(
            $defenseRequest, $options, $data['date'], $data['time'], $data['end_time']
        ))->header('Cache-Control', 'private, no-store');
    }

    public function availablePanelMembersJson(Request $request)
    {
        $user = Auth::user();
        if (! $user || ! in_array($user->role, ['Coordinator', 'Administrative Assistant', 'Dean'])) {
            return response()->json(['data' => [], 'next_page' => null], 403);
        }

        $q = trim($request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));
        $offset = ($page - 1) * $perPage;

        // Adviser sources
        $adviserQuery = User::whereIn('role', ['Adviser'])
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name', 'LIKE', "%{$q}%")
                        ->orWhere('last_name', 'LIKE', "%{$q}%")
                        ->orWhereRaw("CONCAT(first_name,' ',last_name) LIKE ?", ["%{$q}%"]);
                });
            });

        $adviserTotal = (clone $adviserQuery)->count();

        $adviser = $adviserQuery
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->skip($offset)
            ->take($perPage)
            ->get()
            ->map(fn ($f) => [
                'id' => 'adviser-'.$f->id,
                'name' => trim($f->first_name.' '.($f->middle_name ? $f->middle_name.' ' : '').$f->last_name),
                'email' => $f->email,
                'type' => 'Adviser',
            ]);

        // Panelists
        $panelQuery = Panelist::where('status', 'Available')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('name', 'LIKE', "%{$q}%")
                        ->orWhere('email', 'LIKE', "%{$q}%");
                });
            });

        $panelTotal = (clone $panelQuery)->count();

        $panelists = $panelQuery
            ->orderBy('name')
            ->skip($offset)
            ->take($perPage)
            ->get()
            ->map(fn ($p) => [
                'id' => 'panelist-'.$p->id,
                'name' => $p->name,
                'email' => $p->email,
                'type' => 'Panelist',
            ]);

        // Merge (adviser + panelists) for this page
        $merged = $adviser->concat($panelists)->values();

        // Decide next page: if BOTH sources exhausted for this page size
        $maxTotal = $adviserTotal + $panelTotal;
        $fetchedSoFar = $offset + $merged->count();
        $nextPage = $fetchedSoFar < $maxTotal ? $page + 1 : null;

        return response()->json([
            'data' => $merged,
            'next_page' => $nextPage,
            'total' => $maxTotal,
            'page' => $page,
        ]);
    }

    /**
     * JSON: assign panels
     */
    public function assignPanelsJson(Request $request, DefenseRequest $defenseRequest)
    {
        if (in_array($defenseRequest->workflow_state, ['adviser-corrections', 'coordinator-corrections'], true)) {
            return response()->json(['error' => 'Wait for the student to resubmit corrections before continuing this request.'], 422);
        }
        $service = app(DefenseSchedulingService::class);
        $service->authorize($defenseRequest, $request->user());
        $data = $request->validate(['defense_chairperson' => 'required|string|max:255', 'defense_panelist1' => 'required|string|max:255', 'defense_panelist2' => 'nullable|string|max:255', 'defense_panelist3' => 'nullable|string|max:255', 'defense_panelist4' => 'nullable|string|max:255', 'availability_date' => 'nullable|required_with:availability_time,availability_end_time|date_format:Y-m-d', 'availability_time' => 'nullable|required_with:availability_date,availability_end_time|date_format:H:i', 'availability_end_time' => 'nullable|required_with:availability_date,availability_time|date_format:H:i|after:availability_time']);
        $updated = $service->persist($defenseRequest, $data, $request->user(), false);

        return $request->expectsJson() ? response()->json(['ok' => true, 'request' => $updated]) : back()->with('success', 'Panel assignments saved.');
    }

    public function scheduleDefenseJson(Request $request, DefenseRequest $defenseRequest)
    {
        if (in_array($defenseRequest->workflow_state, ['adviser-corrections', 'coordinator-corrections'], true)) {
            return response()->json(['error' => 'Wait for the student to resubmit corrections before continuing this request.'], 422);
        }
        $service = app(DefenseSchedulingService::class);
        $service->authorize($defenseRequest, $request->user());
        $data = $request->validate(['scheduled_date' => 'required|date_format:Y-m-d', 'scheduled_time' => 'required|date_format:H:i', 'scheduled_end_time' => 'required|date_format:H:i|after:scheduled_time', 'defense_mode' => 'required|in:face-to-face,online', 'defense_venue' => 'required|string|max:255', 'scheduling_notes' => 'nullable|string|max:1000', 'defense_chairperson' => 'sometimes|required|string|max:255', 'defense_panelist1' => 'sometimes|required|string|max:255', 'defense_panelist2' => 'nullable|string|max:255', 'defense_panelist3' => 'nullable|string|max:255', 'defense_panelist4' => 'nullable|string|max:255']);
        $updated = $service->persist($defenseRequest, $data, $request->user(), true);

        return $request->expectsJson() ? response()->json(['ok' => true, 'request' => $updated]) : back()->with('success', 'Defense schedule saved.');
    }

    private function mapForDetails(DefenseRequest $r): array
    {
        $r->attemptAutoComplete(); // lazy auto-complete for coordinator view

        $adviser = $r->adviserUser;

        return [
            'attachment_names' => $r->attachment_names,
            'adviser_profile' => $adviser ? ['id' => $adviser->id, 'name' => $adviser->name, 'email' => $adviser->email] : null,
            'adviser_status' => $r->adviser_status,
            'coordinator_status' => $r->coordinator_status,
            'id' => $r->id,
            'first_name' => $r->first_name,
            'middle_name' => $r->middle_name,
            'last_name' => $r->last_name,
            'school_id' => $r->school_id,
            'program' => $r->program,
            'thesis_title' => $r->thesis_title,
            'defense_type' => $r->defense_type,
            'defense_adviser' => $r->defense_adviser,
            'defense_chairperson' => $r->defense_chairperson,
            'defense_panelist1' => $r->defense_panelist1,
            'defense_panelist2' => $r->defense_panelist2,
            'defense_panelist3' => $r->defense_panelist3,
            'defense_panelist4' => $r->defense_panelist4,
            'scheduled_date' => $r->scheduled_date,
            'scheduled_time' => $r->scheduled_time,
            'scheduled_end_time' => $r->scheduled_end_time,
            'defense_mode' => $r->defense_mode,
            'defense_venue' => $r->defense_venue,
            'scheduling_notes' => $r->scheduling_notes ?? null,
            'workflow_state' => $r->workflow_state,
            'status' => $r->status,
            'priority' => $r->priority,
            'workflow_history' => $r->workflow_history,
            'last_status_updated_at' => $r->last_status_updated_at?->toIso8601String(),
            'last_status_updated_by' => $r->last_status_updated_by,
        ];
    }

    public function updateStatus(Request $request, DefenseRequest $defenseRequest)
    {
        if (in_array($defenseRequest->workflow_state, ['adviser-corrections', 'coordinator-corrections'], true)) {
            return response()->json(['error' => 'Wait for the student to resubmit corrections before reviewing this request.'], 422);
        }

        $this->authorizeRole();

        $validated = $request->validate([
            'status' => 'required|in:Pending,Approved,Rejected',
        ]);
        $user = Auth::user();

        $origState = $defenseRequest->workflow_state;
        $newStatus = $validated['status'];

        DB::beginTransaction();
        try {
            if ($newStatus === 'Approved') {
                // Only allow approval from certain states
                if (! in_array($origState, ['adviser-approved', 'coordinator-review', 'submitted', null])) {
                    return response()->json(['error' => 'Cannot approve in current state.'], 422);
                }
                $defenseRequest->approveByCoordinator(null, $user->id);
                // approveByCoordinator should already add a workflow entry
            } elseif ($newStatus === 'Rejected') {
                // Allow rejection from any state except already rejected
                if ($origState === 'rejected') {
                    return response()->json(['error' => 'Already rejected.'], 422);
                }
                $defenseRequest->status = 'Rejected';
                $defenseRequest->workflow_state = 'rejected';
                $defenseRequest->last_status_updated_at = now();
                $defenseRequest->last_status_updated_by = $user->id;
                $defenseRequest->addWorkflowEntry(
                    'rejected',
                    'Request rejected by coordinator',
                    $user->id,
                    $origState,
                    'rejected'
                );
                $defenseRequest->save();
            } elseif ($newStatus === 'Pending') {
                // "Retrieve" action: set back to pending
                $defenseRequest->status = 'Pending';
                $defenseRequest->workflow_state = 'coordinator-review';
                $defenseRequest->last_status_updated_at = now();
                $defenseRequest->last_status_updated_by = $user->id;
                $defenseRequest->addWorkflowEntry(
                    'retrieved',
                    'Request retrieved (set to pending) by coordinator',
                    $user->id,
                    $origState,
                    'coordinator-review'
                );
                $defenseRequest->save();
            }

            DB::commit();

            return response()->json([
                'ok' => true,
                'request' => $this->mapForDetails($defenseRequest),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('updateStatus error', [
                'id' => $defenseRequest->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json(['error' => 'Failed to update status.'], 500);
        }
    }
}
