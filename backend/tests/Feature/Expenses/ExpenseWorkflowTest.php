<?php

use App\Models\Donation;
use App\Models\Expense;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Expense workflow: CRUD, validation, and the full status state machine
|--------------------------------------------------------------------------
|
| draft -> pending -> approved -> paid
|                  \-> rejected(invoice)  -> [replace invoice] -> pending -> approved -> paid
|                  \-> rejected(details)  -> permanently read-only
|
| Mirrors the Donation workflow exactly, with one extra terminal step
| (markPaid) and Expense::FINANCIALLY_COUNTED_STATUSES covering TWO
| statuses (approved AND paid) instead of Donation's one (approved).
*/

function activeProjectWithExpenseLeader(): array
{
    $project = Project::factory()->active()->create(['budget' => 100000]);
    $leader = committeeLeaderActor($project);

    // Plenty of actually-collected funds so the remaining-funds check never
    // interferes with these workflow tests — budget enforcement itself is
    // covered separately in ExpenseBudgetValidationTest.
    Donation::factory()->for($project)->approved()->create(['amount' => 100000]);

    return [$project, $leader];
}

it('creates a draft expense attributed to its creator, ignoring any status sent', function () {
    [$project, $leader] = activeProjectWithExpenseLeader();

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'Office Supplies Co',
        'description' => 'Printer paper and ink',
        'amount' => 150.75,
        'payment_method' => 'bank_transfer',
        'expense_date' => now()->toDateString(),
        'status' => 'approved', // must be ignored
    ]);

    $response->assertCreated()->assertJsonPath('data.status', 'draft');

    $expense = Expense::latest('id')->first();
    expect($expense->status)->toBe('draft');
    expect($expense->created_by)->toBe($leader->id);
    expect((float) $expense->amount)->toBe(150.75);
});

it('updates and deletes a draft expense', function () {
    [$project, $leader] = activeProjectWithExpenseLeader();
    $expense = Expense::factory()->for($project)->create([
        'created_by' => $leader->id,
        'status' => 'draft',
        'amount' => 100,
    ]);

    $this->actingAs($leader, 'sanctum')
        ->putJson("/api/expenses/{$expense->id}", ['supplier_name' => 'New Supplier Name'])
        ->assertOk()
        ->assertJsonPath('data.supplier_name', 'New Supplier Name');

    $this->actingAs($leader, 'sanctum')
        ->deleteJson("/api/expenses/{$expense->id}")
        ->assertOk();

    expect(Expense::find($expense->id))->toBeNull();
});

it('runs the full lifecycle draft -> pending -> approved -> paid, notifying the right people at each step, and lets the approver also mark it paid', function () {
    $treasurer = treasurerActor(); // bureau approval authority
    [$project, $leader] = activeProjectWithExpenseLeader();

    // 1. Create (draft).
    $create = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'Catering Co',
        'description' => 'Event catering',
        'amount' => 1000,
        'payment_method' => 'cash',
        'expense_date' => now()->toDateString(),
    ])->assertCreated();

    $expenseId = $create->json('data.id');

    // 2. Submit (draft -> pending).
    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/expenses/{$expenseId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect(
        Notification::where('user_id', $treasurer->id)
            ->where('type', 'expense_pending')
            ->where('subject_type', Expense::class)
            ->where('subject_id', $expenseId)
            ->exists()
    )->toBeTrue();
    expect(Notification::where('type', 'expense_resubmitted')->exists())->toBeFalse();

    // 3. Approve (pending -> approved), by the treasurer (not the creator).
    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expenseId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $expense = Expense::find($expenseId);
    expect($expense->approved_by)->toBe($treasurer->id);
    expect($expense->approved_at)->not->toBeNull();

    expect(
        Notification::where('user_id', $leader->id)
            ->where('type', 'expense_approved')
            ->where('subject_id', $expenseId)
            ->exists()
    )->toBeTrue();

    // 4. Mark paid (approved -> paid) — by the SAME treasurer who approved
    // it. Unlike approve/reject, markPaid has no self-exclusion rule at
    // all (there's no "creator" concept to exclude here, and the policy
    // never checks approved_by), so the approver acting again is allowed.
    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expenseId}/mark-paid")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid');

    $expense->refresh();
    expect($expense->status)->toBe('paid');
    expect($expense->paid_by)->toBe($treasurer->id);
    expect($expense->paid_at)->not->toBeNull();
});

it('denies self-approval and self-rejection: the creator cannot decide their own expense', function () {
    $project = Project::factory()->active()->create(['budget' => 100000]);
    $president = presidentActor(); // holds APPROVAL_ROLES authority AND is the committee leader here
    assignToCommittee($project, $president, 'leader');

    $expense = Expense::factory()->for($project)->pending()->create(['created_by' => $president->id]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/approve")
        ->assertStatus(403)
        ->assertJson(['message' => 'You cannot approve an expense you created.']);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/reject", [
            'reason' => 'n/a',
            'rejection_type' => 'details',
        ])
        ->assertStatus(403)
        ->assertJson(['message' => 'You cannot reject an expense you created.']);

    expect($expense->fresh()->status)->toBe('pending');
});

it('cannot approve a draft expense directly, only a pending one', function () {
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithExpenseLeader();
    $expense = Expense::factory()->for($project)->create(['created_by' => $leader->id, 'status' => 'draft']);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/approve")
        ->assertStatus(403);

    expect($expense->fresh()->status)->toBe('draft');
});

it('cannot mark an expense paid unless it is currently approved', function () {
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithExpenseLeader();

    $draft = Expense::factory()->for($project)->create(['created_by' => $leader->id, 'status' => 'draft']);
    $pending = Expense::factory()->for($project)->pending()->create(['created_by' => $leader->id]);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$draft->id}/mark-paid")
        ->assertStatus(403)
        ->assertJson(['message' => 'Only approved expenses can be marked as paid.']);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$pending->id}/mark-paid")
        ->assertStatus(403);
});

it('rejects a pending expense requiring a reason and a rejection_type, and notifies the creator', function () {
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithExpenseLeader();
    $expense = Expense::factory()->for($project)->pending()->create(['created_by' => $leader->id]);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/reject", [
            'reason' => 'Invoice does not match the amount.',
            'rejection_type' => 'invoice',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $expense->refresh();
    expect($expense->rejected_by)->toBe($treasurer->id);
    expect($expense->rejection_type)->toBe('invoice');
    expect($expense->rejection_reason)->toBe('Invoice does not match the amount.');

    expect(
        Notification::where('user_id', $leader->id)
            ->where('type', 'expense_rejected')
            ->where('subject_id', $expense->id)
            ->exists()
    )->toBeTrue();
});

it('rejects a rejection missing the reason or with an invalid rejection_type', function () {
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithExpenseLeader();
    $expense = Expense::factory()->for($project)->pending()->create(['created_by' => $leader->id]);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/reject", ['rejection_type' => 'details'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/reject", ['reason' => 'x', 'rejection_type' => 'other'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rejection_type']);
});

it('runs the full invoice-rejection recovery path: create, submit, reject as invoice issue, replace invoice, resubmit, approve', function () {
    Storage::fake('public');
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithExpenseLeader();

    $create = $this->actingAs($leader, 'sanctum')->postJson('/api/expenses', [
        'project_id' => $project->id,
        'supplier_name' => 'Recovery Supplier',
        'description' => 'Recovery test expense',
        'amount' => 500,
        'payment_method' => 'bank_transfer',
        'expense_date' => now()->toDateString(),
    ])->assertCreated();

    $expenseId = $create->json('data.id');

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/expenses/{$expenseId}/submit")
        ->assertOk()->assertJsonPath('data.status', 'pending');

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expenseId}/reject", [
            'reason' => 'Invoice image is unreadable.',
            'rejection_type' => 'invoice',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

    // Replace the invoice while rejected — allowed only for an invoice-type rejection.
    $file = UploadedFile::fake()->create('invoice-v2.pdf', 10, 'application/pdf');
    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/expenses/{$expenseId}/invoice", ['invoice' => $file])
        ->assertOk();

    Storage::disk('public')->assertExists(Expense::find($expenseId)->invoice_path);

    // "Submit Again": rejected(invoice) -> pending, resubmission notification type.
    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/expenses/{$expenseId}/submit")
        ->assertOk()->assertJsonPath('data.status', 'pending');

    expect(
        Notification::where('type', 'expense_resubmitted')->where('subject_id', $expenseId)->exists()
    )->toBeTrue();

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expenseId}/approve")
        ->assertOk()->assertJsonPath('data.status', 'approved');

    expect(Expense::find($expenseId)->status)->toBe('approved');
});

it('permanently locks a details-rejected expense: update, delete, invoice upload, and submit all stay denied', function () {
    Storage::fake('public');
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithExpenseLeader();

    $expense = Expense::factory()->for($project)->pending()->create(['created_by' => $leader->id]);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/reject", [
            'reason' => 'Entirely wrong supplier.',
            'rejection_type' => 'details',
        ])->assertOk();

    $expense->refresh();
    expect($expense->status)->toBe('rejected');
    expect($expense->rejection_type)->toBe('details');

    $this->actingAs($leader, 'sanctum')
        ->putJson("/api/expenses/{$expense->id}", ['amount' => 999])
        ->assertStatus(403);

    $this->actingAs($leader, 'sanctum')
        ->deleteJson("/api/expenses/{$expense->id}")
        ->assertStatus(403);

    $file = UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf');
    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/invoice", ['invoice' => $file])
        ->assertStatus(403);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/expenses/{$expense->id}/submit")
        ->assertStatus(403);

    expect($expense->fresh()->status)->toBe('rejected');
    expect((float) $expense->fresh()->amount)->not->toBe(999.0);
});

it("counts both approved AND paid expenses (but not draft/pending/rejected) toward the project's expenses/remaining figures", function () {
    $president = presidentActor();
    [$project, $leader] = activeProjectWithExpenseLeader();

    Expense::factory()->for($project)->create(['created_by' => $leader->id, 'status' => 'draft', 'amount' => 100]);
    Expense::factory()->for($project)->pending()->create(['created_by' => $leader->id, 'amount' => 100]);
    Expense::factory()->for($project)->approved()->create(['created_by' => $leader->id, 'amount' => 200]);
    Expense::factory()->for($project)->paid()->create(['created_by' => $leader->id, 'amount' => 150]);
    Expense::factory()->for($project)->rejected()->create(['created_by' => $leader->id, 'amount' => 100]);

    $response = $this->actingAs($president, 'sanctum')->getJson("/api/projects/{$project->id}");

    $response->assertOk();
    // Only approved (200) + paid (150) = 350 count toward the figure.
    expect((float) $response->json('data.expenses'))->toBe(350.0);
    expect((float) $response->json('data.remaining'))->toBe((float) $response->json('data.collected') - 350.0);
});
