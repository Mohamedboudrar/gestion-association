<?php

namespace App\Http\Resources;

use App\Helpers\ProjectPhaseWorkflow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $allocated = (float) $this->fundAllocations()->sum('amount');
        $collected = (float) $this->donations()->financiallyCounted()->sum('amount') + $allocated;
        $expenses = (float) $this->expenses()->financiallyCounted()->sum('amount');
        $remaining = $collected - $expenses;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'budget' => $this->budget,
            'status' => $this->status,
            'phase' => $this->phase,
            'pending_phase_request_id' => $this->pendingPhaseRequest()?->id,
            'pending_deletion_request_id' => $this->pendingDeletionRequest()?->id,
            'progress_percentage' => ProjectPhaseWorkflow::progressPercentage($this->phase),
            'latitude' => $this->latitude !== null ? (float) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (float) $this->longitude : null,

            'collected' => $collected,
            'allocated' => $allocated,
            'expenses' => $expenses,
            'remaining' => $remaining,

            'manager' => $this->manager ? [
                'id' => $this->manager->id,
                'name' => $this->manager->name,
                'email' => $this->manager->email,
            ] : null,

            'members_count' => $this->members()->count(),

            'members' => $this->members->map(fn ($member) => [
                'id' => $member->id,
                'user_id' => $member->user_id,
                'name' => $member->user?->name,
                'committee_role' => $member->pivot->committee_role,
            ]),

            'created_at' => $this->created_at,
        ];
    }
}
