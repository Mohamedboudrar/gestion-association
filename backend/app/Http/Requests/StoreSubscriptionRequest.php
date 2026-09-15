<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'member_id' => 'required|exists:members,id',
            // Optional: when omitted, SubscriptionController::store() auto-links
            // this payment to the payer's due for the payment's year (creating
            // it if needed) — see DuesService::getOrCreateForMemberYear(). An
            // explicit due_id is still accepted for callers that already know it.
            'due_id' => 'nullable|exists:dues,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|string|max:100',
            'receipt_number' => 'nullable|string|max:100',
            'receipt_file' => 'nullable|string',
            'payment_date' => 'required|date',
            'expires_at' => 'required|date|after:payment_date',
            'notes' => 'nullable|string',
        ];
    }
}