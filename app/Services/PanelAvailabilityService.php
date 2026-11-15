<?php

namespace App\Services;

use App\Models\ComprehensiveSession;
use App\Models\DefenseRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

class PanelAvailabilityService
{
    public function forSlot(DefenseRequest $current, array $options, string $date, string $start, string $end): array
    {
        $conflicts = app(DefenseConflictService::class);
        $overlapping = $conflicts->overlapping($current, $date, $start, $end)->pluck('id')->all();
        $bookings = [];
        $daily = DefenseRequest::whereDate('scheduled_date', $date)->whereNotNull('scheduled_time')
            ->whereIn('workflow_state', ['scheduled', 'completed', 'panels-assigned', 'coordinator-approved'])
            ->where(fn ($q) => $q->whereNull('status')->orWhereRaw('LOWER(status) NOT IN (?, ?, ?)', ['cancelled', 'canceled', 'rejected']))
            ->orderBy('scheduled_time')->get();
        foreach ($daily as $defense) {
            $time = substr($defense->scheduled_time, 0, 5);
            $until = $defense->scheduled_end_time ? substr($defense->scheduled_end_time, 0, 5) : CarbonImmutable::parse($date.' '.$time, 'Asia/Manila')->addHour()->format('H:i');
            // A person holding multiple roles still has only one defense booking.
            foreach (array_keys($conflicts->people($defense)) as $identity) {
                $bookings[$identity][] = [
                    'start' => $time, 'end' => $until,
                    'overlaps' => in_array($defense->id, $overlapping, true),
                    'current' => $defense->id === $current->id,
                ];
            }
        }

        if (Schema::hasTable('comprehensive_sessions')) {
            foreach (ComprehensiveSession::where('exam_date', $date)->get() as $session) {
                foreach ($session->faculty as $faculty) {
                    if ($faculty['status'] === 'declined') {
                        continue;
                    }
                    $identity = $conflicts->userIdentity($faculty['user_id']);
                    $bookings[$identity][] = ['start' => substr($session->start_time, 0, 5), 'end' => substr($session->end_time, 0, 5), 'overlaps' => substr($session->start_time, 0, 5) < $end && substr($session->end_time, 0, 5) > $start, 'current' => false, 'kind' => 'comprehensive'];
                }
            }
        }
        $describe = function (string $identity) use ($bookings) {
            $all = $bookings[$identity] ?? [];

            return [
                'identity' => $identity,
                'daily_defenses' => count(array_filter($all, fn ($booking) => ($booking['kind'] ?? 'defense') === 'defense')),
                'is_available' => ! collect($all)->contains('overlaps', true),
                'bookings' => array_values(array_filter($all, fn ($booking) => ! $booking['current'])),
            ];
        };

        $proposal = app(DefenseSchedulingService::class)->proposalPanel($current);
        $proposalPanelAvailable = $proposal ? collect(DefenseConflictService::PANEL_FIELDS)->every(
            fn ($field) => ! $proposal->$field || $describe($conflicts->identity($proposal->$field))['is_available']
        ) : null;

        return [
            'date' => $date, 'time' => $start, 'end_time' => $end,
            'proposal_panel_available' => $proposalPanelAvailable,
            'options' => array_map(function ($option) use ($conflicts, $describe) {
                $identity = ! empty($option['email']) ? 'email:'.strtolower($option['email'])
                    : $conflicts->identity(preg_replace('/^panelist-/', '', $option['id']));

                return $option + $describe($identity);
            }, $options),
            'adviser' => $current->adviser_user_id ? $describe($conflicts->userIdentity($current->adviser_user_id)) : null,
        ];
    }
}
