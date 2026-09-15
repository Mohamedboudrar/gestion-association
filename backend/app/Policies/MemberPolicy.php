<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Models\Member;
use App\Models\User;

class MemberPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('members.view');
    }

    public function view(User $user, Member $member): bool
    {
        if (! $user->can('members.view')) {
            return false;
        }

        // Bureau roles see the full directory; a plain abonne can only open
        // their own record ("Members: Own profile only").
        return AuthorizationHelper::isBureauMember($user) || $member->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $user->can('members.create');
    }

    public function update(User $user, Member $member): bool
    {
        return $user->can('members.update') || $member->user_id === $user->id;
    }

    public function delete(User $user, Member $member): bool
    {
        return $user->can('members.delete');
    }
}
