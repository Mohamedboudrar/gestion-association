<?php

namespace App\Http\Requests;

use App\Helpers\ProjectPhaseWorkflow;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreProjectPhaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'to_phase' => 'required|in:'.implode(',', ProjectPhaseWorkflow::REQUESTABLE),
            'summary' => 'required|string|max:2000',
            'notes' => 'nullable|string|max:2000',
            'proofs' => 'nullable|array',
            'proofs.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('to_phase')) {
                return;
            }

            $project = $this->route('project');

            if (! $project) {
                return;
            }

            if ($project->pendingPhaseRequest()) {
                $validator->errors()->add('to_phase', 'This project already has a pending phase request.');

                return;
            }

            if (! ProjectPhaseWorkflow::isValidRequest($project->phase, $this->input('to_phase'))) {
                $validator->errors()->add(
                    'to_phase',
                    "Cannot request phase \"{$this->input('to_phase')}\" from \"{$project->phase}\" — phases advance one step at a time."
                );
            }
        });
    }
}
