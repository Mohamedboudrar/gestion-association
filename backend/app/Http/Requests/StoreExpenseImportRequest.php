<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Structural validation only (required fields per row, same rules as
// StoreExpenseRequest) — the budget-ceiling check runs in the controller
// once every row has passed this, since it needs the whole batch's summed
// total, not just one row at a time. See ExpenseController::import().
class StoreExpenseImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'project_id' => 'required|exists:projects,id',
            'expenses' => 'required|array|min:1',
            'expenses.*.supplier_name' => 'required|string|max:255',
            'expenses.*.description' => 'required|string',
            'expenses.*.amount' => 'required|numeric|min:0.01|max:99999999.99',
            'expenses.*.payment_method' => 'required|string|max:100',
            'expenses.*.invoice_number' => 'nullable|string|max:100',
            'expenses.*.expense_date' => 'required|date',
            'expenses.*.notes' => 'nullable|string',
        ];
    }
}
