<?php

use App\Models\Donation;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Donation workflow: CRUD, validation, and the full status state machine
|--------------------------------------------------------------------------
|
| draft -> pending -> approved
|                  \-> rejected(receipt) -> [replace receipt] -> pending -> approved
|                  \-> rejected(details)  -> permanently read-only
|
| These tests use a committee leader as the primary "authorized" actor
| (create/submit/uploadReceipt authority) and a separate president/treasurer
| as the approval authority, since DonationPolicy::approve/reject explicitly
| forbid a donation's own recorder from deciding it.
*/

function activeProjectWithLeader(): array
{
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);

    return [$project, $leader];
}

it('creates a donation attributed to a member and always forces status to draft, ignoring any status sent', function () {
    [$project, $leader] = activeProjectWithLeader();
    $donorMember = subscriberActor();

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [
        'member_id' => $donorMember->member->id,
        'project_id' => $project->id,
        'amount' => 250.50,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
        'status' => 'approved', // must be ignored
    ]);

    $response->assertCreated()->assertJsonPath('data.status', 'draft');

    $donation = Donation::latest('id')->first();
    expect($donation->status)->toBe('draft');
    expect($donation->recorded_by)->toBe($leader->id);
    expect((float) $donation->amount)->toBe(250.50);
});

it('creates a donation attributed to a free-text donor name when no member_id is given', function () {
    [$project, $leader] = activeProjectWithLeader();

    $response = $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [
        'donor_name' => 'Jane External Donor',
        'project_id' => $project->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
    ]);

    $response->assertCreated()->assertJsonPath('data.donor_name', 'Jane External Donor');
});

it('rejects a donation with neither member_id nor donor_name on both fields', function () {
    [$project, $leader] = activeProjectWithLeader();

    $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [
        'project_id' => $project->id,
        'amount' => 100,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['member_id', 'donor_name']);
});

it('rejects a donation amount of zero', function () {
    [$project, $leader] = activeProjectWithLeader();

    $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [
        'donor_name' => 'Someone',
        'project_id' => $project->id,
        'amount' => 0,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

it('rejects a negative donation amount', function () {
    [$project, $leader] = activeProjectWithLeader();

    $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [
        'donor_name' => 'Someone',
        'project_id' => $project->id,
        'amount' => -50,
        'payment_method' => 'cash',
        'donation_date' => now()->toDateString(),
    ])->assertStatus(422)->assertJsonValidationErrors(['amount']);
});

it('rejects a donation missing required fields', function () {
    [$project, $leader] = activeProjectWithLeader();

    $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['project_id', 'amount', 'payment_method', 'donation_date']);
});

it('updates a draft donation', function () {
    [$project, $leader] = activeProjectWithLeader();
    $donation = Donation::factory()->for($project)->create([
        'recorded_by' => $leader->id,
        'status' => 'draft',
        'amount' => 100,
    ]);

    $this->actingAs($leader, 'sanctum')
        ->putJson("/api/donations/{$donation->id}", ['amount' => 175])
        ->assertOk()
        ->assertJsonPath('data.amount', '175.00');

    expect((float) $donation->fresh()->amount)->toBe(175.0);
});

it('deletes a draft donation and its stored receipt file', function () {
    Storage::fake('public');
    [$project, $leader] = activeProjectWithLeader();
    $path = UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf')->store('donation-receipts', 'public');

    $donation = Donation::factory()->for($project)->create([
        'recorded_by' => $leader->id,
        'status' => 'draft',
        'receipt_file' => $path,
    ]);

    $this->actingAs($leader, 'sanctum')
        ->deleteJson("/api/donations/{$donation->id}")
        ->assertOk();

    expect(Donation::find($donation->id))->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('uploads a receipt for a draft donation', function () {
    Storage::fake('public');
    [$project, $leader] = activeProjectWithLeader();
    $donation = Donation::factory()->for($project)->create([
        'recorded_by' => $leader->id,
        'status' => 'draft',
    ]);

    $file = UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf');

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/receipt", ['receipt' => $file])
        ->assertOk()
        ->assertJsonPath('data.receipt_file', fn ($value) => str_contains($value, 'donation-receipts'));

    Storage::disk('public')->assertExists($donation->fresh()->receipt_file);
});

it('submits a draft donation for approval and notifies the financial oversight roles with the fresh-submission type', function () {
    $president = presidentActor();
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithLeader();

    $donation = Donation::factory()->for($project)->create([
        'recorded_by' => $leader->id,
        'status' => 'draft',
    ]);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect($donation->fresh()->status)->toBe('pending');

    foreach ([$president, $treasurer] as $recipient) {
        expect(
            Notification::where('user_id', $recipient->id)
                ->where('type', 'donation_pending')
                ->where('subject_type', \App\Models\Donation::class)
                ->where('subject_id', $donation->id)
                ->exists()
        )->toBeTrue();
    }

    expect(Notification::where('type', 'donation_resubmitted')->exists())->toBeFalse();
});

it('cannot submit a donation a second time while it is already pending', function () {
    [$project, $leader] = activeProjectWithLeader();
    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $leader->id]);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/submit")
        ->assertStatus(403);

    expect($donation->fresh()->status)->toBe('pending');
});

it('cannot approve a draft donation directly, only pending ones', function () {
    $president = presidentActor();
    [$project, $leader] = activeProjectWithLeader();
    $donation = Donation::factory()->for($project)->create([
        'recorded_by' => $leader->id,
        'status' => 'draft',
    ]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/approve")
        ->assertStatus(403);

    expect($donation->fresh()->status)->toBe('draft');
});

it('approves a pending donation, stamps approver/time, and notifies the recorder', function () {
    $president = presidentActor();
    [$project, $leader] = activeProjectWithLeader();
    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $leader->id]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    $donation->refresh();
    expect($donation->status)->toBe('approved');
    expect($donation->approved_by)->toBe($president->id);
    expect($donation->approved_at)->not->toBeNull();

    expect(
        Notification::where('user_id', $leader->id)
            ->where('type', 'donation_approved')
            ->where('subject_id', $donation->id)
            ->exists()
    )->toBeTrue();
});

it('rejects a pending donation requiring a reason and a rejection_type, and notifies the recorder', function () {
    $president = presidentActor();
    [$project, $leader] = activeProjectWithLeader();
    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $leader->id]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/reject", [
            'reason' => 'Missing receipt details.',
            'rejection_type' => 'receipt',
        ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $donation->refresh();
    expect($donation->status)->toBe('rejected');
    expect($donation->rejected_by)->toBe($president->id);
    expect($donation->rejection_type)->toBe('receipt');
    expect($donation->rejection_reason)->toBe('Missing receipt details.');

    expect(
        Notification::where('user_id', $leader->id)
            ->where('type', 'donation_rejected')
            ->where('subject_id', $donation->id)
            ->exists()
    )->toBeTrue();
});

it('rejects a rejection with an invalid rejection_type value', function () {
    $president = presidentActor();
    [$project, $leader] = activeProjectWithLeader();
    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $leader->id]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/reject", [
            'reason' => 'Bad data.',
            'rejection_type' => 'other',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['rejection_type']);
});

it('rejects a rejection missing the reason', function () {
    $president = presidentActor();
    [$project, $leader] = activeProjectWithLeader();
    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $leader->id]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/reject", [
            'rejection_type' => 'details',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['reason']);
});

it('runs the full receipt-rejection recovery path: create, submit, reject as receipt issue, replace receipt, resubmit, approve', function () {
    Storage::fake('public');
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithLeader();

    // 1. Create (draft).
    $create = $this->actingAs($leader, 'sanctum')->postJson('/api/donations', [
        'donor_name' => 'Recovery Donor',
        'project_id' => $project->id,
        'amount' => 500,
        'payment_method' => 'bank_transfer',
        'donation_date' => now()->toDateString(),
    ])->assertCreated();

    $donationId = $create->json('data.id');

    // 2. Submit (draft -> pending), fresh-submission notification type.
    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donationId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect(
        Notification::where('type', 'donation_pending')->where('subject_id', $donationId)->exists()
    )->toBeTrue();

    // 3. Reject as a receipt issue.
    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/donations/{$donationId}/reject", [
            'reason' => 'Receipt is unreadable.',
            'rejection_type' => 'receipt',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');

    // 4. Replace the receipt while rejected (allowed only for a receipt-type rejection).
    $file = UploadedFile::fake()->create('receipt-v2.pdf', 10, 'application/pdf');
    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donationId}/receipt", ['receipt' => $file])
        ->assertOk();

    // 5. Resubmit ("Submit Again"): rejected(receipt) -> pending, resubmission notification type.
    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donationId}/submit")
        ->assertOk()
        ->assertJsonPath('data.status', 'pending');

    expect(
        Notification::where('type', 'donation_resubmitted')->where('subject_id', $donationId)->exists()
    )->toBeTrue();

    // 6. Approve.
    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/donations/{$donationId}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'approved');

    expect(Donation::find($donationId)->status)->toBe('approved');
});

it('permanently locks a details-rejected donation: update, delete, receipt upload, and submit all stay denied', function () {
    Storage::fake('public');
    $treasurer = treasurerActor();
    [$project, $leader] = activeProjectWithLeader();

    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $leader->id]);

    $this->actingAs($treasurer, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/reject", [
            'reason' => 'Wrong donor entirely.',
            'rejection_type' => 'details',
        ])->assertOk();

    $donation->refresh();
    expect($donation->status)->toBe('rejected');
    expect($donation->rejection_type)->toBe('details');

    $this->actingAs($leader, 'sanctum')
        ->putJson("/api/donations/{$donation->id}", ['amount' => 999])
        ->assertStatus(403);

    $this->actingAs($leader, 'sanctum')
        ->deleteJson("/api/donations/{$donation->id}")
        ->assertStatus(403);

    $file = UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf');
    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/receipt", ['receipt' => $file])
        ->assertStatus(403);

    $this->actingAs($leader, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/submit")
        ->assertStatus(403);

    // Nothing about the row changed as a side effect of any denied attempt.
    expect($donation->fresh()->status)->toBe('rejected');
    expect((float) $donation->fresh()->amount)->not->toBe(999.0);
});

it('denies self-approval: a leader who both recorded and holds approval authority cannot approve their own donation', function () {
    // President is both an APPROVAL_ROLES member and, here, the project's
    // committee leader who personally recorded the donation — isolating the
    // "recorded_by === user.id" check from the "does this role approve at
    // all" check (a plain committee leader without APPROVAL_ROLES would be
    // denied for the role reason alone, which is a different rule).
    $project = Project::factory()->active()->create();
    $president = presidentActor();
    assignToCommittee($project, $president, 'leader');

    $donation = Donation::factory()->for($project)->pending()->create(['recorded_by' => $president->id]);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/approve")
        ->assertStatus(403);

    $this->actingAs($president, 'sanctum')
        ->postJson("/api/donations/{$donation->id}/reject", [
            'reason' => 'n/a',
            'rejection_type' => 'details',
        ])
        ->assertStatus(403);

    expect($donation->fresh()->status)->toBe('pending');
});
