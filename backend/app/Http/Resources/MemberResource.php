<?php

namespace App\Http\Resources;

use App\Helpers\AuthorizationHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'user' => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ],

            'phone' => $this->phone,
            'address' => $this->address,

            'is_bureau_member' => AuthorizationHelper::isBureauMember($this->user),
            'has_verified_subscription' => AuthorizationHelper::hasVerifiedSubscription($this->resource),
            // Whether this subscriber has been issued a Member Portal passkey
            // yet (never exposes the hash itself — see User::hasPortalAccess()).
            'has_portal_access' => $this->user->hasPortalAccess(),

            'created_at' => $this->created_at,
        ];
    }
}
