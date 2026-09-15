<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Models\Due;
use App\Models\User;

class DuePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('dues.view');
    }

    public function view(User $user, Due $due): bool
    {
        return $due->member?->user_id === $user->id || AuthorizationHelper::isBureauMember($user);
    }

    // Same authority as SubscriptionPolicy::verify — président/trésorier/
    // vice-trésorier, the association's financial approval tier. Follows
    // that policy's existing precedent of a hardcoded role check for this
    // kind of "special action" rather than a consulted permission string.
    public function waive(User $user, Due $due): bool
    {
        return $user->hasAnyRole(['president', 'tresorier', 'vice-tresorier']);
    }
}
