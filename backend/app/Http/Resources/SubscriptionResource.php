<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'member' => [
                'id' => $this->member->id,
                'user_id' => $this->member->user_id,
                'name' => $this->member->user->name,
            ],

            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'receipt_number' => $this->receipt_number,
            'receipt_file' => $this->receipt_file,
            'receipt_url' => $this->receipt_file ? asset('storage/'.$this->receipt_file) : null,
            'status' => $this->status,
            'payment_date' => $this->payment_date,
            'expires_at' => $this->expires_at,
            'notes' => $this->notes,
            'verified_at' => $this->verified_at,

            // Additive — only present when the caller eager-loaded `due`.
            // Older/unlinked subscriptions (due_id null) simply report null.
            'due' => $this->whenLoaded('due', fn () => $this->due ? [
                'id' => $this->due->id,
                'year' => $this->due->year,
                'status' => $this->due->status,
                'balance' => (float) $this->due->balance,
            ] : null),
        ];
    }
}
