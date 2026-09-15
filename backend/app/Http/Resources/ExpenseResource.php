<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'supplier_name' => $this->supplier_name,
            'description' => $this->description,
            'amount' => $this->amount,
            'payment_method' => $this->payment_method,
            'invoice_number' => $this->invoice_number,
            'invoice_file' => $this->invoice_path,
            'invoice_url' => $this->invoice_path ? asset('storage/'.$this->invoice_path) : null,
            'expense_date' => $this->expense_date,
            'notes' => $this->notes,
            'status' => $this->status,
            'project' => $this->project ? [
                'id' => $this->project->id,
                'name' => $this->project->name,
            ] : null,
            'created_by' => $this->creator ? [
                'id' => $this->creator->id,
                'name' => $this->creator->name,
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
            'paid_by' => $this->payer ? [
                'id' => $this->payer->id,
                'name' => $this->payer->name,
            ] : null,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
