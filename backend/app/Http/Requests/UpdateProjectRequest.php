<?php

namespace App\Http\Requests;

use App\Helpers\ProjectLifecycle;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'description' => 'sometimes|nullable|string',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|nullable|date|after_or_equal:start_date',
            'budget' => 'sometimes|numeric|min:0|max:99999999.99',
            'status' => 'sometimes|in:draft,committee_ready,funding_ready,active,completed,cancelled',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'manager_id' => 'sometimes|nullable|exists:users,id',
        ];
    }

    // The only status change this generic endpoint allows is cancellation —
    // committee_ready/funding_ready are automatic, active/completed each have
    // their own dedicated action (start()/close()). See ProjectLifecycle.
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('status') || $validator->errors()->has('status')) {
                return;
            }

            $project = $this->route('project');

            if (! $project || ProjectLifecycle::canManuallySetStatus($project->status, $this->input('status'))) {
                return;
            }

            $validator->errors()->add(
                'status',
                "Cannot change project status from {$project->status} to {$this->input('status')} directly."
            );
        });
    }
}
