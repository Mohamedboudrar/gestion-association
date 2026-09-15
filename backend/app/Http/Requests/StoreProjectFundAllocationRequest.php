<?php

namespace App\Http\Requests;

use App\Helpers\FundsHelper;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectFundAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'allocation_date' => 'required|date',
            'proof_file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('amount')) {
                return;
            }

            $availableFunds = FundsHelper::availableFunds();

            if ((float) $this->input('amount') > $availableFunds) {
                $validator->errors()->add('amount', 'The amount exceeds the available funds.');
            }
        });
    }
}
