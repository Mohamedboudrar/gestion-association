<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectDeletionRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:2000',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('reason')) {
                return;
            }

            $project = $this->route('project');

            if (! $project) {
                return;
            }

            if ($project->pendingDeletionRequest()) {
                $validator->errors()->add('reason', 'This project already has a pending deletion request.');
            }
        });
    }
}
