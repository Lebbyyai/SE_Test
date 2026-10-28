<?php

namespace App\Services;

use App\Models\Adviser;
use App\Models\User;

class AccountProfileName
{
    public function isPlaceholder(?string $first, ?string $last): bool
    {
        $first = strtolower(trim($first ?? ''));
        $last = strtolower(trim($last ?? ''));

        return ($first === 'new' && $last === 'user') || ($first === '' && $last === '');
    }

    public function fromGoogle(User $user, array $profile): void
    {
        if (! $this->isPlaceholder($user->first_name, $user->last_name)) {
            return;
        }
        $first = is_string($profile['given_name'] ?? null) ? trim($profile['given_name']) : '';
        $last = is_string($profile['family_name'] ?? null) ? trim($profile['family_name']) : '';
        if ($first === '' || $last === '' || mb_strlen($first) > 255 || mb_strlen($last) > 255
            || $this->isPlaceholder($first, $last)) {
            return;
        }
        // Google does not provide a separate middle-name claim. Keep a real existing middle name.
        $user->forceFill(['first_name' => $first, 'last_name' => $last])->save();
    }

    public function fromAdviserRegistration(User $user): void
    {
        if ($user->role !== 'Adviser' || ! $this->isPlaceholder($user->first_name, $user->last_name)) {
            return;
        }
        $names = Adviser::whereRaw('LOWER(email) = ?', [strtolower(trim($user->email))])->get()
            ->filter(fn ($row) => filled($row->first_name) && filled($row->last_name)
                && ! $this->isPlaceholder($row->first_name, $row->last_name))
            ->map(fn ($row) => ['first_name' => trim($row->first_name),
                'middle_name' => $row->middle_name, 'last_name' => trim($row->last_name)])
            ->unique(fn ($name) => json_encode($name))->values();
        // Conflicting registrations require the user to confirm their own name in Profile.
        if ($names->count() === 1) {
            $user->forceFill($names->first())->save();
        }
    }
}
