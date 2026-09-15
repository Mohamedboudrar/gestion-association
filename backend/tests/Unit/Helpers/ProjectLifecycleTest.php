<?php

use App\Helpers\ProjectLifecycle;
use App\Models\Project;

it('allows a no-op status change to the same status', function () {
    expect(ProjectLifecycle::canManuallySetStatus('active', 'active'))->toBeTrue();
});

it('allows cancelling from any non-terminal status', function () {
    foreach (['draft', 'committee_ready', 'funding_ready', 'active'] as $status) {
        expect(ProjectLifecycle::canManuallySetStatus($status, 'cancelled'))->toBeTrue();
    }
});

it('never allows cancelling a completed project', function () {
    expect(ProjectLifecycle::canManuallySetStatus('completed', 'cancelled'))->toBeFalse();
});

it('still allows the same-status no-op even for a terminal status', function () {
    // $from === $to is always a no-op allowed case, checked before the
    // terminal check — this isn't "re-cancelling", just an idempotent write.
    expect(ProjectLifecycle::canManuallySetStatus('cancelled', 'cancelled'))->toBeTrue();
    expect(ProjectLifecycle::canManuallySetStatus('completed', 'completed'))->toBeTrue();
});

it('never allows manually setting any forward-lifecycle status via the generic update endpoint', function () {
    // draft -> committee_ready/funding_ready/active/completed only ever
    // happen as side effects of other actions (assigning a committee,
    // allocating funds, starting, closing) — never a direct status write.
    expect(ProjectLifecycle::canManuallySetStatus('draft', 'committee_ready'))->toBeFalse();
    expect(ProjectLifecycle::canManuallySetStatus('draft', 'active'))->toBeFalse();
    expect(ProjectLifecycle::canManuallySetStatus('committee_ready', 'funding_ready'))->toBeFalse();
    expect(ProjectLifecycle::canManuallySetStatus('funding_ready', 'active'))->toBeFalse();
    expect(ProjectLifecycle::canManuallySetStatus('active', 'completed'))->toBeFalse();
});

it('identifies completed and cancelled as the only terminal statuses', function () {
    expect(ProjectLifecycle::isTerminal('completed'))->toBeTrue();
    expect(ProjectLifecycle::isTerminal('cancelled'))->toBeTrue();
    expect(ProjectLifecycle::isTerminal('draft'))->toBeFalse();
    expect(ProjectLifecycle::isTerminal('committee_ready'))->toBeFalse();
    expect(ProjectLifecycle::isTerminal('funding_ready'))->toBeFalse();
    expect(ProjectLifecycle::isTerminal('active'))->toBeFalse();
});

it('only allows fund allocations once a project has at least a committee assigned', function () {
    $statusExpectations = [
        'draft' => false,
        'committee_ready' => true,
        'funding_ready' => true,
        'active' => true,
        'completed' => false,
        'cancelled' => false,
    ];

    foreach ($statusExpectations as $status => $expected) {
        $project = Project::factory()->make(['status' => $status]);

        expect(ProjectLifecycle::canReceiveAllocation($project))->toBe($expected);
    }
});
