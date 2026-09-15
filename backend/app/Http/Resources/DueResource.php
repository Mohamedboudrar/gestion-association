<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DueResource extends JsonResource
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

            'year' => $this->year,
            'amount_due' => (float) $this->amount_due,
            'amount_paid' => (float) $this->amount_paid,
            'balance' => (float) $this->balance,
            'status' => $this->status,
            'due_date' => $this->due_date?->toDateString(),
            'paid_at' => $this->paid_at,
            'waived_reason' => $this->waived_reason,
            'created_at' => $this->created_at,
        ];
    }
}
