<?php

namespace App\Services;

use App\Models\DefenseRequest;
use App\Models\Panelist;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class DefenseConflictService
{
    public const PANEL_FIELDS = ['defense_chairperson', 'defense_panelist1', 'defense_panelist2', 'defense_panelist3', 'defense_panelist4'];

    public function identity($value): string
    {
        if (! $value) {
            return '';
        }
        $p = is_numeric($value) ? Panelist::find($value) : Panelist::whereRaw('LOWER(name) = ?', [strtolower(trim((string) $value))])->first();

        return $p && $p->email ? 'email:'.strtolower($p->email) : 'name:'.strtolower(trim($p?->name ?? (string) $value));
    }

    public function userIdentity($id): string
    {
        $u = $id ? User::find($id) : null;

        return $u?->email ? 'email:'.strtolower($u->email) : 'user:'.$id;
    }

    public function validateAssignmentBasic(DefenseRequest $dr, array $panels): array
    {
        $seen = [];
        $errors = [];
        foreach (self::PANEL_FIELDS as $field) {
            $key = $this->identity($panels[$field] ?? null);
            if (! $key) {
                continue;
            }if (isset($seen[$key])) {
                $errors[$field] = 'Each panel position must be assigned to a different person.';
            }$seen[$key] = true;
        }

        return $errors;
    }

    public function overlapping(DefenseRequest $current, string $date, string $start, string $end)
    {
        $from = CarbonImmutable::parse($date.' '.$start, 'Asia/Manila');
        $to = CarbonImmutable::parse($date.' '.$end, 'Asia/Manila');

        return DefenseRequest::where('id', '!=', $current->id ?? 0)->whereDate('scheduled_date', $date)->whereNotNull('scheduled_time')
            ->whereIn('workflow_state', ['scheduled', 'panels-assigned', 'coordinator-approved'])->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['Cancelled', 'Rejected', 'Completed']))->get()->filter(function ($other) use ($date, $from, $to) {
                $a = CarbonImmutable::parse($date.' '.$other->scheduled_time, 'Asia/Manila');
                $b = $other->scheduled_end_time ? CarbonImmutable::parse($date.' '.$other->scheduled_end_time, 'Asia/Manila') : $a->addHour();

                return $from < $b && $to > $a;
            });
    }

    public function people(DefenseRequest $dr, array $panels = []): array
    {
        $out = [];
        foreach (self::PANEL_FIELDS as $field) {
            $value = $panels[$field] ?? $dr->{$field};
            if ($value) {
                $out[$this->identity($value)] = $field === 'defense_chairperson' ? 'Chairperson' : 'Panelist';
            }
        }
        if ($dr->adviser_user_id) {
            $out[$this->userIdentity($dr->adviser_user_id)] = 'Adviser';
        }

        return $out;
    }

    public function findPanelSchedulingConflicts(DefenseRequest $current, array $panels, ?string $date, ?string $startTime, ?string $endTime): array
    {
        if (! $date || ! $startTime || ! $endTime) {
            return [];
        }
        $selected = $this->people($current, $panels);
        $found = [];
        foreach ($this->overlapping($current, $date, $startTime, $endTime) as $other) {
            foreach (array_intersect_key($selected, $this->people($other)) as $person => $role) {
                $found[] = ['person' => 'A selected panelist or adviser', 'current_role' => $role, 'conflicting_role' => $this->people($other)[$person], 'defense_id' => $other->id, 'student_name' => trim($other->first_name.' '.$other->last_name), 'time_range' => $other->scheduled_time.' – '.$other->scheduled_end_time];
            }
        }

        return $found;
    }

    public function hasVenueConflict(DefenseRequest $current, ?string $venue, ?string $date, ?string $start, ?string $end): bool
    {
        if (! $venue || ! $date || ! $start || ! $end || $current->defense_mode === 'online') {
            return false;
        }

        return $this->overlapping($current, $date, $start, $end)->contains(fn ($r) => $r->defense_mode !== 'online' && strtolower(trim((string) $r->defense_venue)) === strtolower(trim($venue)));
    }

    public function assertNoConflict(DefenseRequest $dr): void
    {
        $errors = $this->validateAssignmentBasic($dr, $dr->getAttributes());
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
        if (! $dr->scheduled_date || ! $dr->scheduled_time) {
            return;
        }
        $date = $dr->scheduled_date->format('Y-m-d');
        $end = $dr->scheduled_end_time ?: CarbonImmutable::parse($dr->scheduled_time)->addHour()->format('H:i:s');
        if ($end <= $dr->scheduled_time) {
            throw ValidationException::withMessages(['scheduled_end_time' => 'End time must be after start time.']);
        }
        if ($this->findPanelSchedulingConflicts($dr, [], $date, $dr->scheduled_time, $end)) {
            throw ValidationException::withMessages(['scheduled_time' => 'A selected panelist or adviser has an overlapping defense.']);
        }
        if ($this->hasVenueConflict($dr, $dr->defense_venue, $date, $dr->scheduled_time, $end)) {
            throw ValidationException::withMessages(['defense_venue' => 'The venue is already booked during this time.']);
        }
        if ($dr->submitted_by && $this->overlapping($dr, $date, $dr->scheduled_time, $end)->contains(fn ($r) => (int) $r->submitted_by === (int) $dr->submitted_by)) {
            throw ValidationException::withMessages(['scheduled_time' => 'This student already has a defense during this time.']);
        }
    }
}
