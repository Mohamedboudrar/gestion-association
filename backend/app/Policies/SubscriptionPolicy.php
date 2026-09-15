<?php

namespace App\Policies;

use App\Helpers\AuthorizationHelper;
use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('subscriptions.view');
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return $subscription->member?->user_id === $user->id
            || AuthorizationHelper::isBureauMember($user);
    }

    public function create(User $user): bool
    {
        return $user->can('subscriptions.create');
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.update');
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return $user->can('subscriptions.delete');
    }

    public function verify(User $user, Subscription $subscription): bool
    {
        return $user->hasAnyRole(['president', 'tresorier', 'vice-tresorier']);
    }

    public function uploadReceipt(User $user, Subscription $subscription): bool
    {
        return $this->verify($user, $subscription)
            || $subscription->member?->user_id === $user->id;
    }
}
