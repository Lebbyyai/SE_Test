<?php

namespace App\Observers;

use App\Models\DefenseRequest;
use App\Services\DefenseActivityNotifications;
use App\Services\DefenseConflictService;
use App\Services\HonorariumAccess;
use App\Services\HonorariumLedgerService;

class DefenseRequestObserver
{
    public function created(DefenseRequest $request): void
    {
        $service = app(DefenseActivityNotifications::class);
        if (in_array($request->workflow_state, ['submitted', 'adviser-review'])) {
            $service->submitted($request);
        }
        if ($request->scheduled_date) {
            $service->scheduled($request);
        }
    }

    public function updated(DefenseRequest $request): void
    {
        app(HonorariumLedgerService::class)->invalidate($request);
        $service = app(DefenseActivityNotifications::class);
        if ($request->wasChanged(DefenseConflictService::PANEL_FIELDS) && $request->user) {
            $names = app(HonorariumAccess::class)->panelNames($request);
            $service->sendOnce($request, $request->user, 'panel-update|'.hash('sha256', json_encode([$names, $request->workflow_history])), 'Defense Panel Updated', 'The panel assignments for “'.$request->thesis_title.'” were updated. Open your request to check the latest committee.', '/defense-requirements');
        }
        if ($request->wasChanged('workflow_state')) {
            $service->changed($request, $request->getOriginal('workflow_state'));
        }
        if ($request->wasChanged(['scheduled_date', 'scheduled_time', 'scheduled_end_time', 'defense_venue'])) {
            $service->clearUnreadReminders($request);
            $service->scheduled($request);
        }
        if ($request->wasChanged(['adviser_user_id', 'coordinator_user_id']) && in_array($request->workflow_state, ['submitted', 'adviser-review'])) {
            $service->submitted($request);
        }
        if ($request->wasChanged('status') && in_array($request->status, ['Cancelled', 'Completed', 'Rejected'])) {
            $service->clearUnreadReminders($request);
        }
        if ($request->wasChanged('workflow_state') && in_array($request->workflow_state, ['cancelled', 'completed', 'coordinator-rejected', 'adviser-rejected', 'rejected'])) {
            $service->clearUnreadReminders($request);
        }
    }
}
