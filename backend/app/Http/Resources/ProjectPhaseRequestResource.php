<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectPhaseRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ]),
            'from_phase' => $this->from_phase,
            'to_phase' => $this->to_phase,
            'summary' => $this->summary,
            'notes' => $this->notes,
            'status' => $this->status,
            'requested_by' => $this->requestedBy ? [
                'id' => $this->requestedBy->id,
                'name' => $this->requestedBy->name,
            ] : null,
            'requested_at' => $this->requested_at,
            'reviewed_by' => $this->reviewedBy ? [
                'id' => $this->reviewedBy->id,
                'name' => $this->reviewedBy->name,
            ] : null,
            'reviewed_at' => $this->reviewed_at,
            'rejection_reason' => $this->rejection_reason,
            'proofs' => $this->proofs->map(fn ($proof) => [
                'id' => $proof->id,
                'original_name' => $proof->original_name,
                'mime_type' => $proof->mime_type,
                'size' => $proof->size,
                'url' => asset('storage/'.$proof->file_path),
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
