<?php

namespace App\Http\Controllers\Api;

use App\Helpers\AuthorizationHelper;
use App\Helpers\ExpenseBudgetValidator;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreExpenseImportRequest;
use App\Http\Requests\StoreExpenseRequest;
use App\Http\Requests\UpdateExpenseRequest;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Expense::class);

        $query = Expense::with(['project', 'creator', 'approver', 'rejecter', 'payer']);

        if (! AuthorizationHelper::isFinancialOversightRole(auth()->user())) {
            $member = auth()->user()->member;
            $query->whereIn('project_id', $member ? $member->projects()->pluck('projects.id') : []);
        }

        if ($request->filled('project_id')) {
            $query->where('project_id', $request->query('project_id'));
        }

        return ExpenseResource::collection(
            $query->latest('expense_date')
                ->latest()
                ->get()
        );
    }

    public function store(StoreExpenseRequest $request)
    {
        $project = Project::findOrFail($request->validated('project_id'));
        $this->authorize('create', [Expense::class, $project]);

        $expense = Expense::create([
            ...$request->validated(),
            'created_by' => auth()->id(),
            'status' => 'draft',
        ]);

        return new ExpenseResource(
            $expense->load(['project', 'creator', 'approver', 'rejecter', 'payer'])
        );
    }

    // Validates the whole batch's cumulative budget impact BEFORE creating
    // any row — an all-or-nothing import. No expense (draft or otherwise) is
    // ever persisted if the batch would exceed the project's budget.
    public function import(StoreExpenseImportRequest $request)
    {
        $project = Project::findOrFail($request->validated('project_id'));
        $this->authorize('create', [Expense::class, $project]);

        $rows = $request->validated('expenses');
        $importTotal = round(array_sum(array_map(fn ($row) => (float) $row['amount'], $rows)), 2);

        $breakdown = ExpenseBudgetValidator::breakdown($project, $importTotal);

        if (! $breakdown['within_budget']) {
            activity()
                ->causedBy(auth()->user())
                ->performedOn($project)
                ->withProperties($breakdown)
                ->log('Expense import rejected because budget exceeded.');

            return response()->json([
                'message' => __('messages.expense.import_exceeds_budget'),
                'budget' => $breakdown,
            ], 422);
        }

        $expenses = DB::transaction(fn () => collect($rows)->map(fn (array $row) => Expense::create([
            ...$row,
            'project_id' => $project->id,
            'created_by' => auth()->id(),
            'status' => 'draft',
        ])));

        // collect()->map() returns a plain Support\Collection (not Eloquent's),
        // which has no load() — load each model individually instead.
        $expenses->each->load(['project', 'creator', 'approver', 'rejecter', 'payer']);

        return ExpenseResource::collection($expenses);
    }

    public function show(Expense $expense)
    {
        $this->authorize('view', $expense);

        return new ExpenseResource(
            $expense->load(['project', 'creator', 'approver', 'rejecter', 'payer'])
        );
    }

    public function update(UpdateExpenseRequest $request, Expense $expense)
    {
        $this->authorize('update', $expense);

        $expense->update($request->validated());

        return new ExpenseResource(
            $expense->load(['project', 'creator', 'approver', 'rejecter', 'payer'])
        );
    }

    public function destroy(Expense $expense)
    {
        $this->authorize('delete', $expense);

        if ($expense->invoice_path) {
            Storage::disk('public')->delete($expense->invoice_path);
        }

        $expense->delete();

        return response()->json([
            'message' => __('messages.expense.deleted'),
        ]);
    }

    public function uploadInvoice(Request $request, Expense $expense)
    {
        $this->authorize('uploadInvoice', $expense);

        $request->validate([
            'invoice' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120',
        ]);

        if ($expense->invoice_path) {
            Storage::disk('public')->delete($expense->invoice_path);
        }

        $path = $request->file('invoice')->store('expense-invoices', 'public');

        $expense->update([
            'invoice_path' => $path,
        ]);

        return new ExpenseResource(
            $expense->fresh()->load(['project', 'creator', 'approver', 'rejecter', 'payer'])
        );
    }

    public function submit(Expense $expense)
    {
        $this->authorize('submit', $expense);

        // Distinguishes a fresh submission from a "Submit Again" after an
        // invoice-issue rejection — same status transition, but the
        // approval roles get a differently-worded, differently-typed
        // notification for each (see NOTIFICATION TYPES: "submitted" vs
        // "resubmitted").
        $isResubmission = $expense->status === 'rejected';

        $expense->update([
            'status' => 'pending',
        ]);

        $expense->load(['project', 'creator', 'approver', 'rejecter', 'payer']);

        Notification::notifyRoles(
            AuthorizationHelper::FINANCIAL_OVERSIGHT_ROLES,
            $isResubmission ? 'expense_resubmitted' : 'expense_pending',
            __($isResubmission ? 'notifications.expense.resubmitted_title' : 'notifications.expense.pending_title'),
            __($isResubmission ? 'notifications.expense.resubmitted_message' : 'notifications.expense.submitted_message', [
                'actor' => $expense->creator?->name ?? __('notifications.people.committee_member'),
                'id' => $expense->id,
                'project' => $expense->project?->name ?? __('notifications.people.a_project'),
            ]),
            $expense,
        );

        return new ExpenseResource($expense);
    }

    public function approve(Expense $expense)
    {
        $this->authorize('approve', $expense);

        $expense->update([
            'status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        $expense->load(['project', 'creator', 'approver', 'rejecter', 'payer']);

        if ($expense->created_by) {
            Notification::notifyUsers(
                [$expense->created_by],
                'expense_approved',
                __('notifications.expense.approved_title'),
                __('notifications.expense.approved_message', [
                    'id' => $expense->id,
                    'project' => $expense->project?->name ?? __('notifications.people.a_project'),
                ]),
                $expense,
            );
        }

        return new ExpenseResource($expense);
    }

    public function reject(Request $request, Expense $expense)
    {
        $this->authorize('reject', $expense);

        $validated = $request->validate([
            'reason' => 'required|string|max:500',
            'rejection_type' => 'required|in:invoice,details',
        ]);

        $expense->update([
            'status' => 'rejected',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'rejection_reason' => $validated['reason'],
            'rejection_type' => $validated['rejection_type'],
        ]);

        $expense->load(['project', 'creator', 'approver', 'rejecter', 'payer']);

        if ($expense->created_by) {
            Notification::notifyUsers(
                [$expense->created_by],
                'expense_rejected',
                __('notifications.expense.rejected_title'),
                __('notifications.expense.rejected_message', [
                    'id' => $expense->id,
                    'project' => $expense->project?->name ?? __('notifications.people.a_project'),
                    'reason' => $validated['reason'],
                ]),
                $expense,
            );
        }

        return new ExpenseResource($expense);
    }

    public function markPaid(Expense $expense)
    {
        $this->authorize('markPaid', $expense);

        $expense->update([
            'status' => 'paid',
            'paid_by' => auth()->id(),
            'paid_at' => now(),
        ]);

        return new ExpenseResource(
            $expense->fresh()->load(['project', 'creator', 'approver', 'rejecter', 'payer'])
        );
    }
}
