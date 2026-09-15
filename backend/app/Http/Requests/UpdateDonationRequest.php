<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => 'sometimes|nullable|exists:members,id|required_without:donor_name',
            'project_id' => 'sometimes|required|exists:projects,id',
            'donor_name' => 'sometimes|nullable|string|max:255|required_without:member_id',
            'amount' => 'sometimes|numeric|min:0.01|max:99999999.99',
            'payment_method' => 'sometimes|string|max:100',
            'receipt_number' => 'sometimes|nullable|string|max:100',
            'receipt_file' => 'sometimes|nullable|string',
            'donation_date' => 'sometimes|date',
            'notes' => 'sometimes|nullable|string',
        ];
    }
}
