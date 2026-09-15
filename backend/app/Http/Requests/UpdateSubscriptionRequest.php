<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => 'sometimes|exists:members,id',
            'amount' => 'sometimes|numeric|min:0',
            'payment_method' => 'sometimes|string|max:100',
            'receipt_number' => 'sometimes|string|max:100',
            'receipt_file' => 'sometimes|string',
            'payment_date' => 'sometimes|date',
            'expires_at' => 'sometimes|date|after:payment_date',
            'notes' => 'sometimes|string',
        ];
    }
}
