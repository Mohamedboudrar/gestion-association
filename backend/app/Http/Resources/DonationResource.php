<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DonationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'donor_name' => $this->member?->user?->name ?? $this->donor_name,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'receipt_number' => $this->receipt_number,
            'receipt_file' => $this->receipt_file,
            'receipt_url' => $this->receipt_file ? asset('storage/' . $this->receipt_file) : null,
            'donation_date' => $this->donation_date,
            'notes' => $this->notes,
            'status' => $this->status,
            'member' => $this->member ? [
                'id' => $this->member->id,
                'name' => $this->member->user?->name,
            ] : null,
            'project' => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null,
            'recorded_by' => $this->recorder ? [
                'id' => $this->recorder->id,
                'name' => $this->recorder->name,
            ] : null,
            'approved_by' => $this->approver ? [
                'id' => $this->approver->id,
                'name' => $this->approver->name,
            ] : null,
            'approved_at' => $this->approved_at,
            'rejected_by' => $this->rejecter ? [
                'id' => $this->rejecter->id,
                'name' => $this->rejecter->name,
            ] : null,
            'rejected_at' => $this->rejected_at,
            'rejection_reason' => $this->rejection_reason,
            'rejection_type' => $this->rejection_type,
            'created_at' => $this->created_at,
        ];
    }
}
