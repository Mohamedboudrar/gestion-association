<?php

use App\Helpers\ProjectPhaseWorkflow;

it('maps every phase to its exact progress percentage, never derived from money', function () {
    expect(ProjectPhaseWorkflow::progressPercentage('planning'))->toBe(0);
    expect(ProjectPhaseWorkflow::progressPercentage('preparation'))->toBe(25);
    expect(ProjectPhaseWorkflow::progressPercentage('in_progress'))->toBe(50);
    expect(ProjectPhaseWorkflow::progressPercentage('finishing'))->toBe(75);
    expect(ProjectPhaseWorkflow::progressPercentage('completed'))->toBe(100);
});

it('returns 0 for an unrecognized phase rather than erroring', function () {
    expect(ProjectPhaseWorkflow::progressPercentage('not-a-real-phase'))->toBe(0);
});

it('walks the phase sequence one step forward at a time', function () {
    expect(ProjectPhaseWorkflow::nextPhase('planning'))->toBe('preparation');
    expect(ProjectPhaseWorkflow::nextPhase('preparation'))->toBe('in_progress');
    expect(ProjectPhaseWorkflow::nextPhase('in_progress'))->toBe('finishing');
    expect(ProjectPhaseWorkflow::nextPhase('finishing'))->toBe('completed');
});

it('has no next phase once completed', function () {
    expect(ProjectPhaseWorkflow::nextPhase('completed'))->toBeNull();
});

it('validates a phase request only if it targets the exact next sequential phase', function () {
    expect(ProjectPhaseWorkflow::isValidRequest('planning', 'preparation'))->toBeTrue();
    expect(ProjectPhaseWorkflow::isValidRequest('preparation', 'in_progress'))->toBeTrue();
});

it('rejects a phase request that skips ahead', function () {
    expect(ProjectPhaseWorkflow::isValidRequest('planning', 'in_progress'))->toBeFalse();
    expect(ProjectPhaseWorkflow::isValidRequest('planning', 'finishing'))->toBeFalse();
});

it('rejects a phase request that moves backward', function () {
    expect(ProjectPhaseWorkflow::isValidRequest('in_progress', 'preparation'))->toBeFalse();
});

it('never allows requesting completed directly — that phase is only reachable via project close', function () {
    expect(ProjectPhaseWorkflow::isValidRequest('finishing', 'completed'))->toBeFalse();
    expect(in_array('completed', ProjectPhaseWorkflow::REQUESTABLE, true))->toBeFalse();
});
