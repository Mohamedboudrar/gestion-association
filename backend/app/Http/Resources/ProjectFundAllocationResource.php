<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectFundAllocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'amount' => (float) $this->amount,
            'allocation_date' => $this->allocation_date,
            'proof_file_url' => $this->proof_file ? asset('storage/'.$this->proof_file) : null,
            'recorded_by' => $this->recorder?->name,
            'created_at' => $this->created_at,
        ];
    }
}
