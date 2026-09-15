<?php

namespace App\Http\Requests;

use App\Helpers\ExpenseBudgetValidator;
use App\Models\Project;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateExpenseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => 'sometimes|exists:projects,id',
            'supplier_name' => 'sometimes|string|max:255',
            'description' => 'sometimes|string',
            'amount' => 'sometimes|numeric|min:0.01|max:99999999.99',
            'payment_method' => 'sometimes|string|max:100',
            'invoice_number' => 'nullable|string|max:100',
            'invoice_path' => 'nullable|string',
            'expense_date' => 'sometimes|date',
            'notes' => 'nullable|string',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('amount') || $validator->errors()->has('amount')) {
                return;
            }

            $expense = $this->route('expense');
            $project = $this->has('project_id') ? Project::find($this->input('project_id')) : $expense?->project;

            if (! $project) {
                return;
            }

            $collected = (float) $project->donations()->financiallyCounted()->sum('amount')
                + (float) $project->fundAllocations()->sum('amount');
            $otherExpenses = (float) $project->expenses()
                ->where('id', '!=', $expense?->id)
                ->financiallyCounted()
                ->sum('amount');
            $remaining = $collected - $otherExpenses;

            if ((float) $this->input('amount') > $remaining) {
                $validator->errors()->add('amount', 'The amount exceeds the project\'s remaining funds.');
            }

            // Separate from the remaining-funds check above: recalculate the
            // budget-ceiling total with this expense's own prior amount
            // excluded (its new amount replaces it, not adds to it).
            $breakdown = ExpenseBudgetValidator::breakdown($project, (float) $this->input('amount'), $expense?->id);

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
