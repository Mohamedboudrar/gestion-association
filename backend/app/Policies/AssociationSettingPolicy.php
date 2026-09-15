<?php

namespace App\Policies;

use App\Models\AssociationSetting;
use App\Models\User;

class AssociationSettingPolicy
{
    // No view() gate: GET /api/settings is a public route (see
    // routes/api.php) — the login page and member-portal login page render
    // this branding before any session exists, so there's no "guest" case
    // to deny here.

    // Only the president may change association-wide settings.
    public function update(User $user, AssociationSetting $setting): bool
    {
        return $user->hasRole('president');
    }
}
