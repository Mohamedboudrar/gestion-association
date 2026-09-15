<?php

namespace App\Http\Requests;

use App\Helpers\ExpenseBudgetValidator;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'supplier_name' => 'required|string|max:255',
            'description' => 'required|string',
            'amount' => 'required|numeric|min:0.01|max:99999999.99',
            'payment_method' => 'required|string|max:100',
            'invoice_number' => 'nullable|string|max:100',
            'invoice_path' => 'nullable|string',
            'expense_date' => 'required|date',
            'notes' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('amount') || $validator->errors()->has('project_id')) {
                return;
            }

            $project = Project::find($this->input('project_id'));

            if (! $project) {
                return;
            }

            $collected = (float) $project->donations()->financiallyCounted()->sum('amount')
                + (float) $project->fundAllocations()->sum('amount');
            $remaining = $collected - (float) $project->expenses()->financiallyCounted()->sum('amount');

            if ((float) $this->input('amount') > $remaining) {
                $validator->errors()->add('amount', 'The amount exceeds the project\'s remaining funds.');
            }

            // Separate from the remaining-funds check above: a budget is a
            // spending ceiling regardless of whether the money has actually
            // been collected yet — see ExpenseBudgetValidator.
            $breakdown = ExpenseBudgetValidator::breakdown($project, (float) $this->input('amount'));

            if (! $breakdown['within_budget']) {
                $validator->errors()->add('amount', ExpenseBudgetValidator::errorMessage($breakdown));

                activity()
                    ->causedBy(auth()->user())
                    ->performedOn($project)
                    ->withProperties($breakdown)
                    ->log('Manual expense rejected because budget exceeded.');
            }
        });
    }
}
