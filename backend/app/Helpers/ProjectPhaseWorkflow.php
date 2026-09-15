<?php

namespace App\Helpers;

/**
 * Single source of truth for the project execution-phase state machine:
 *
 *   planning -> preparation -> in_progress -> finishing -> completed
 *
 * Unlike ProjectLifecycle (the administrative status), phases only ever move
 * one step forward, and only through a leader-submitted request that a
 * president/vice-president approves (see ProjectPhaseRequest). "completed" is
 * never reachable through a phase request — it is set only as a side effect
 * of the existing project-close flow (ProjectController::close()).
 */
class ProjectPhaseWorkflow
{
    public const PLANNING = 'planning';

    public const PREPARATION = 'preparation';

    public const IN_PROGRESS = 'in_progress';

    public const FINISHING = 'finishing';

    public const COMPLETED = 'completed';

    public const ORDER = [
        self::PLANNING,
        self::PREPARATION,
        self::IN_PROGRESS,
        self::FINISHING,
        self::COMPLETED,
    ];

    // "completed" is deliberately excluded — reachable only via project close().
    public const REQUESTABLE = [
        self::PREPARATION,
        self::IN_PROGRESS,
        self::FINISHING,
    ];

    // Single source of truth for phase -> progress percentage. Project progress
    // is never stored or manually set — it is always derived from `phase` at
    // read time, here, and nowhere else.
    public const PROGRESS_BY_PHASE = [
        self::PLANNING => 0,
        self::PREPARATION => 25,
        self::IN_PROGRESS => 50,
        self::FINISHING => 75,
        self::COMPLETED => 100,
    ];

    public static function progressPercentage(string $phase): int
    {
        return self::PROGRESS_BY_PHASE[$phase] ?? 0;
    }

    public static function nextPhase(string $current): ?string
    {
        $index = array_search($current, self::ORDER, true);

        if ($index === false) {
            return null;
        }

        return self::ORDER[$index + 1] ?? null;
    }

    // A phase request is only valid if it targets the exact next phase in the
    // sequence — no skipping ahead, no moving backward, and never "completed".
    public static function isValidRequest(string $from, string $to): bool
    {
        return in_array($to, self::REQUESTABLE, true) && self::nextPhase($from) === $to;
    }
}
