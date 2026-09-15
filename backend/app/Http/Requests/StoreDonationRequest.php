<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => 'nullable|exists:members,id|required_without:donor_name',
            'project_id' => 'required|exists:projects,id',
            'donor_name' => 'nullable|string|max:255|required_without:member_id',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payment_method' => 'required|string|max:100',
            'receipt_number' => 'nullable|string|max:100',
            'receipt_file' => 'nullable|string',
            'donation_date' => 'required|date',
            'notes' => 'nullable|string',
        ];
    }
}
