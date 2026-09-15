<?php

namespace App\Helpers;

use App\Models\Donation;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectFundAllocation;
use App\Models\ProjectPhaseRequest;
use App\Models\ProjectReport;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Activitylog\Models\Activity;

/**
 * Single source of truth for the Activity Explorer: which entities are
 * browsable, how they're scoped to a committee member's assigned projects,
 * how filters/search translate into SQL (never an in-memory scan — see
 * ActivityLogController), and how a raw Activity row becomes a readable
 * card (ActivityLogResource). Deliberately reuses the existing
 * activity_log data and each subject's own already-recorded columns —
 * nothing here touches how activity is logged (see the models' own
 * getActivitylogOptions()).
 */
class ActivityLogHelper
{
    /**
     * subject_type FQCN -> display/query metadata. Ordering matters only
     * for entity keys used in the frontend filter dropdown.
     *
     * - name_columns: subject columns matched by full-text search.
     * - project_column: the FK column on the subject's own table pointing
     *   at its project, or null if the subject IS a project or has none.
     * - status_column/status_labels: drives the Status filter + badge.
     */
    public const ENTITIES = [
        'donation' => [
            'class' => Donation::class,
            'table' => 'donations',
            'label' => 'Donation',
            'name_columns' => ['donor_name'],
            'project_column' => 'project_id',
            'status_column' => 'status',
            'status_labels' => ['draft' => 'Draft', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'],
        ],
        'expense' => [
            'class' => Expense::class,
            'table' => 'expenses',
            'label' => 'Expense',
            'name_columns' => ['supplier_name', 'invoice_number'],
            'project_column' => 'project_id',
            'status_column' => 'status',
            'status_labels' => ['draft' => 'Draft', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'paid' => 'Paid'],
        ],
        'project' => [
            'class' => Project::class,
            'table' => 'projects',
            'label' => 'Project',
            'name_columns' => ['name'],
            'project_column' => null,
            'status_column' => 'status',
            'status_labels' => [
                'draft' => 'Draft', 'committee_ready' => 'Committee Ready', 'funding_ready' => 'Funding Ready',
                'active' => 'Active', 'completed' => 'Completed', 'cancelled' => 'Cancelled',
            ],
        ],
        'subscription' => [
            'class' => Subscription::class,
            'table' => 'subscriptions',
            'label' => 'Subscription',
            'name_columns' => ['receipt_number'],
            'project_column' => null,
            'status_column' => 'status',
            'status_labels' => ['pending' => 'Pending', 'verified' => 'Verified', 'rejected' => 'Rejected', 'expired' => 'Expired'],
        ],
        'member' => [
            'class' => Member::class,
            'table' => 'members',
            'label' => 'Subscriber',
            'name_columns' => [],
            'project_column' => null,
            'status_column' => null,
            'status_labels' => [],
        ],
        'fund_allocation' => [
            'class' => ProjectFundAllocation::class,
            'table' => 'project_fund_allocations',
            'label' => 'Fund Allocation',
            'name_columns' => [],
            'project_column' => 'project_id',
            'status_column' => null,
            'status_labels' => [],
        ],
        'report' => [
            'class' => ProjectReport::class,
            'table' => 'project_reports',
            'label' => 'Report',
            'name_columns' => [],
            'project_column' => 'project_id',
            'status_column' => null,
            'status_labels' => [],
        ],
    ];

    // "Domain" actions that aren't a raw Spatie event() value — each maps to
    // (entity key => the status that resulted from it). Applying one of
    // these filters an 'updated' row down to the specific transition whose
    // target status matches AND whose timing lines up with the subject's
    // own updated_at (see applyActionFilter) — otherwise a donation that
    // went draft -> pending -> approved would match "Approved" on both of
    // its 'updated' rows, not just the approval itself.
    private const DOMAIN_ACTIONS = [
        'approved' => ['donation' => 'approved', 'expense' => 'approved'],
        'rejected' => ['donation' => 'rejected', 'expense' => 'rejected', 'subscription' => 'rejected'],
        'submitted' => ['donation' => 'pending', 'expense' => 'pending'],
        'paid' => ['expense' => 'paid'],
        'verified' => ['subscription' => 'verified'],
    ];

    public static function entityKeyFor(?string $subjectType): ?string
    {
        foreach (self::ENTITIES as $key => $meta) {
            if ($meta['class'] === $subjectType) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Restricts $query to activity whose subject belongs to one of the
     * user's assigned projects — the "committee members only see activities
     * related to projects they are assigned to" rule. Only called for users
     * who fail canViewAllActivities(); Member/Subscription activity has no
     * project to relate to and is excluded entirely for a scoped viewer
     * (matches the wording "related to projects", not "related to me").
     */
    public static function scopeToAssignedProjects(Builder $query, User $user): Builder
    {
        $member = $user->member;
        $projectIds = $member ? $member->projects()->pluck('projects.id') : collect();

        return $query->where(function (Builder $q) use ($projectIds) {
            foreach (self::ENTITIES as $meta) {
                if ($meta['class'] === Project::class) {
                    $q->orWhere(function (Builder $sub) use ($projectIds, $meta) {
                        $sub->where('subject_type', $meta['class'])->whereIn('subject_id', $projectIds);
                    });

                    continue;
                }

                if ($meta['project_column'] === null) {
                    continue;
                }

                $q->orWhere(function (Builder $sub) use ($projectIds, $meta) {
                    $sub->where('subject_type', $meta['class'])
                        ->whereIn('subject_id', function ($idQuery) use ($projectIds, $meta) {
                            $idQuery->select('id')->from($meta['table'])->whereIn($meta['project_column'], $projectIds);
                        });
                });
            }
        });
    }

    /**
     * Applies every combinable filter at the database level — user, entity,
     * project, status, date range, action. None of this loads rows into
     * memory to filter; every condition is a WHERE/whereExists clause.
     */
    public static function applyFilters(Builder $query, array $filters): Builder
    {
        if (! empty($filters['user_id'])) {
            $query->where('causer_id', $filters['user_id']);
        }

        if (! empty($filters['entity']) && isset(self::ENTITIES[$filters['entity']])) {
            $query->where('subject_type', self::ENTITIES[$filters['entity']]['class']);
        }

        if (! empty($filters['project_id'])) {
            self::applyProjectFilter($query, (int) $filters['project_id']);
        }

        if (! empty($filters['status'])) {
            self::applyStatusFilter($query, $filters['status'], $filters['entity'] ?? null);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('activity_log.created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('activity_log.created_at', '<=', $filters['date_to']);
        }

        if (! empty($filters['action'])) {
            self::applyActionFilter($query, $filters['action'], $filters['entity'] ?? null);
        }

        return $query;
    }

    private static function applyProjectFilter(Builder $query, int $projectId): void
    {
        $query->where(function (Builder $q) use ($projectId) {
            foreach (self::ENTITIES as $meta) {
                if ($meta['class'] === Project::class) {
                    $q->orWhere(function (Builder $sub) use ($projectId, $meta) {
                        $sub->where('subject_type', $meta['class'])->where('subject_id', $projectId);
                    });

                    continue;
                }

                if ($meta['project_column'] === null) {
                    continue;
                }

                $q->orWhere(function (Builder $sub) use ($projectId, $meta) {
                    $sub->where('subject_type', $meta['class'])
                        ->whereIn('subject_id', function ($idQuery) use ($projectId, $meta) {
                            $idQuery->select('id')->from($meta['table'])->where($meta['project_column'], $projectId);
                        });
                });
            }
        });
    }

    private static function applyStatusFilter(Builder $query, string $status, ?string $entityKey): void
    {
        $query->where(function (Builder $q) use ($status, $entityKey) {
            foreach (self::ENTITIES as $key => $meta) {
                if ($entityKey && $entityKey !== $key) {
                    continue;
                }

                if (! $meta['status_column']) {
                    continue;
                }

                $q->orWhere(function (Builder $sub) use ($status, $meta) {
                    $sub->where('subject_type', $meta['class'])
                        ->whereIn('subject_id', function ($idQuery) use ($status, $meta) {
                            $idQuery->select('id')->from($meta['table'])->where($meta['status_column'], $status);
                        });
                });
            }
        });
    }

    private static function applyActionFilter(Builder $query, string $action, ?string $entityKey): void
    {
        if (in_array($action, ['created', 'updated', 'deleted'], true)) {
            $query->where('event', $action);

            return;
        }

        if ($action === 'generated') {
            $query->where('subject_type', self::ENTITIES['report']['class'])->where('event', 'created');

            return;
        }

        $map = self::DOMAIN_ACTIONS[$action] ?? null;

        if ($entityKey) {
            $map = ($map && isset($map[$entityKey])) ? [$entityKey => $map[$entityKey]] : null;
        }

        if (! $map) {
            // Not a recognized action for the given entity (or at all) —
            // an impossible condition, not "ignore the filter".
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('event', 'updated')->where(function (Builder $q) use ($map) {
            foreach ($map as $key => $statusValue) {
                $meta = self::ENTITIES[$key];

                $q->orWhere(function (Builder $sub) use ($statusValue, $meta) {
                    $sub->where('subject_type', $meta['class'])
                        ->whereExists(function ($existsQuery) use ($statusValue, $meta) {
                            // MySQL-specific (TIMESTAMPDIFF) — matches this
                            // project's DB (see CLAUDE.md). The 5-second
                            // window is how we tell "the update that made
                            // this approved" apart from an earlier 'updated'
                            // row on the same now-approved record (e.g. its
                            // draft -> pending submission).
                            $existsQuery->selectRaw('1')
                                ->from($meta['table'])
                                ->whereColumn($meta['table'].'.id', 'activity_log.subject_id')
                                ->where($meta['table'].'.'.$meta['status_column'], $statusValue)
                                ->whereRaw('ABS(TIMESTAMPDIFF(SECOND, '.$meta['table'].'.updated_at, activity_log.created_at)) <= 5');
                        });
                });
            }
        });
    }

    /**
     * Database-level search across user name, entity name, project name,
     * description, and reference number — combined with every other filter
     * via AND (this method itself only adds the OR'd search clause).
     */
    public static function applySearch(Builder $query, string $search): Builder
    {
        $like = '%'.$search.'%';
        $numericId = preg_match('/\d+/', $search, $m) ? (int) $m[0] : null;

        return $query->where(function (Builder $q) use ($like, $numericId, $search) {
            $q->where('activity_log.description', 'like', $like)
                ->orWhereHas('causer', fn ($cq) => $cq->where('name', 'like', $like));

            if ($numericId !== null) {
                $q->orWhere('subject_id', $numericId);
            }

            foreach (self::ENTITIES as $meta) {
                // Typing the entity's own label ("Donation", "Expense", ...)
                // narrows results to that subject_type, same as picking it
                // from the Entity filter.
                if (stripos($meta['label'], $search) !== false) {
                    $q->orWhere('subject_type', $meta['class']);
                }

                if (empty($meta['name_columns']) && $meta['project_column'] === null) {
                    continue;
                }

                $q->orWhere(function (Builder $sub) use ($like, $meta) {
                    $sub->where('subject_type', $meta['class'])
                        ->whereExists(function ($existsQuery) use ($like, $meta) {
                            $existsQuery->selectRaw('1')
                                ->from($meta['table'])
                                ->whereColumn($meta['table'].'.id', 'activity_log.subject_id')
                                ->where(function ($cond) use ($like, $meta) {
                                    foreach ($meta['name_columns'] as $column) {
                                        $cond->orWhere($meta['table'].'.'.$column, 'like', $like);
                                    }

                                    if ($meta['project_column']) {
                                        $cond->orWhereExists(function ($projectQuery) use ($like, $meta) {
                                            $projectQuery->selectRaw('1')
                                                ->from('projects')
                                                ->whereColumn('projects.id', $meta['table'].'.'.$meta['project_column'])
                                                ->where('projects.name', 'like', $like);
                                        });
                                    }
                                });
                        });
                });
            }
        });
    }

    /**
     * Turns one Activity (with its subject/causer already eager-loaded —
     * no extra query for those) into the readable fields the Activity
     * Explorer displays. properties (old/new attribute diffs) are always
     * empty in this app today — see the "changes" field, which is wired
     * to read them anyway so it starts working automatically if that's
     * ever fixed, without anyone needing to touch this code.
     */
    public static function describe(Activity $activity): array
    {
        $causerName = $activity->causer?->name ?? 'System';
        $entityKey = self::entityKeyFor($activity->subject_type);
        $meta = $entityKey ? self::ENTITIES[$entityKey] : null;
        $subject = $activity->subject;

        if (! $meta) {
            return [
                'entity_key' => null,
                'entity_label' => class_basename($activity->subject_type ?? 'Unknown'),
                'reference' => $activity->subject_id ? '#'.$activity->subject_id : null,
                'action_label' => ucfirst($activity->event ?? 'updated'),
                'message' => "{$causerName} {$activity->event} ".class_basename($activity->subject_type ?? 'record'),
                'status' => null,
                'project' => null,
            ];
        }

        $reference = self::referenceFor($entityKey, $subject);
        $project = self::projectFor($entityKey, $subject);
        $status = self::statusFor($meta, $subject);

        // The most recent 'updated' row for a subject is, by construction,
        // the one that produced its current state (nothing has changed it
        // since) — so only *that* row can safely be labeled with the
        // subject's live status. Earlier 'updated' rows on the same subject
        // stay generic, since we have no diff to know what they actually
        // changed (see the properties note above).
        $isLatestTransition = $subject
            && $activity->created_at
            && abs($activity->created_at->diffInSeconds($subject->updated_at ?? $activity->created_at)) <= 5;

        [$actionLabel, $verb] = self::actionFor($activity->event, $entityKey, $subject, $isLatestTransition);

        return [
            'entity_key' => $entityKey,
            'entity_label' => $meta['label'],
            'reference' => $reference,
            'action_label' => $actionLabel,
            'message' => self::buildMessage($causerName, $verb, $entityKey, $meta, $reference, $project, $activity),
            'status' => $status,
            'project' => $project,
        ];
    }

    private static function referenceFor(string $entityKey, $subject): ?string
    {
        if (! $subject) {
            return null;
        }

        return match ($entityKey) {
            'project' => '"'.$subject->name.'"',
            'member' => $subject->user?->name ?? '#'.$subject->id,
            default => '#'.$subject->id,
        };
    }

    private static function projectFor(string $entityKey, $subject): ?array
    {
        if (! $subject) {
            return null;
        }

        $project = match ($entityKey) {
            'project' => $subject,
            'donation', 'expense', 'fund_allocation', 'report' => $subject->relationLoaded('project') ? $subject->project : $subject->project()->first(),
            default => null,
        };

        return $project ? ['id' => $project->id, 'name' => $project->name] : null;
    }

    private static function statusFor(array $meta, $subject): ?array
    {
        if (! $subject || ! $meta['status_column']) {
            return null;
        }

        $value = $subject->{$meta['status_column']};

        if (! $value) {
            return null;
        }

        return ['value' => $value, 'label' => $meta['status_labels'][$value] ?? ucfirst($value)];
    }

    /**
     * @return array{0: string, 1: string} [action label, verb used in the sentence]
     */
    private static function actionFor(?string $event, string $entityKey, $subject, bool $isLatestTransition): array
    {
        // Manually-logged rows (activity()->log(...) without ->event(...) —
        // e.g. PasskeyService, or this session's Project deletion/budget
        // audit lines) leave `event` null. Treat that the same as an
        // unrecognized/generic update rather than crashing.
        if ($event === null) {
            return ['Updated', 'updated'];
        }

        if ($event === 'created') {
            return match ($entityKey) {
                'report' => ['Generated', 'generated'],
                default => ['Created', 'created'],
            };
        }

        if ($event === 'deleted') {
            return ['Deleted', 'deleted'];
        }

        // event === 'updated'
        if (! $isLatestTransition || ! $subject) {
            return ['Updated', 'updated'];
        }

        return match (true) {
            $entityKey === 'donation' && $subject->status === 'approved' => ['Approved', 'approved'],
            $entityKey === 'donation' && $subject->status === 'rejected' => ['Rejected', 'rejected'],
            $entityKey === 'donation' && $subject->status === 'pending' => ['Submitted', 'submitted'],
            $entityKey === 'expense' && $subject->status === 'approved' => ['Approved', 'approved'],
            $entityKey === 'expense' && $subject->status === 'rejected' => ['Rejected', 'rejected'],
            $entityKey === 'expense' && $subject->status === 'pending' => ['Submitted', 'submitted'],
            $entityKey === 'expense' && $subject->status === 'paid' => ['Marked as paid', 'marked_paid'],
            $entityKey === 'subscription' && $subject->status === 'verified' => ['Verified', 'verified'],
            $entityKey === 'subscription' && $subject->status === 'rejected' => ['Rejected', 'rejected'],
            $entityKey === 'subscription' && $subject->status === 'expired' => ['Expired', 'expired'],
            $entityKey === 'project' && $subject->status === 'active' => ['Activated', 'activated'],
            $entityKey === 'project' && $subject->status === 'completed' => ['Closed', 'closed'],
            $entityKey === 'project' && $subject->status === 'cancelled' => ['Cancelled', 'cancelled'],
            default => ['Updated', 'updated'],
        };
    }

    private static function buildMessage(string $causerName, string $verb, string $entityKey, array $meta, ?string $reference, ?array $project, Activity $activity): string
    {
        // Project phase changes get their own, most-specific sentence —
        // correlated against the ProjectPhaseRequest ledger (its own
        // already-recorded from_phase/to_phase/reviewed_at), not a guess.
        if ($entityKey === 'project' && $activity->event === 'updated') {
            $phaseChange = self::correlatedPhaseChange($activity);

            if ($phaseChange) {
                return "{$causerName} changed {$reference} status from \"{$phaseChange['from']}\" to \"{$phaseChange['to']}\"";
            }
        }

        if ($verb === 'marked_paid') {
            return "{$causerName} marked {$meta['label']} {$reference} as paid";
        }

        if ($entityKey === 'report') {
            return $project
                ? "{$causerName} generated a report for Project \"{$project['name']}\""
                : "{$causerName} generated a project report";
        }

        // $reference is null when the subject itself was later deleted (an
        // orphaned log row) — the description just omits the "#42" part
        // rather than leaving a dangling trailing space.
        return $reference
            ? "{$causerName} {$verb} {$meta['label']} {$reference}"
            : "{$causerName} {$verb} {$meta['label']}";
    }

    private const PHASE_LABELS = [
        'planning' => 'Planning',
        'preparation' => 'Preparation',
        'in_progress' => 'In Progress',
        'finishing' => 'Finishing',
        'completed' => 'Completed',
    ];

    private static function correlatedPhaseChange(Activity $activity): ?array
    {
        if (! $activity->subject_id || ! $activity->created_at) {
            return null;
        }

        $candidates = ProjectPhaseRequest::where('project_id', $activity->subject_id)
            ->where('status', 'approved')
            ->whereNotNull('reviewed_at')
            ->get(['from_phase', 'to_phase', 'reviewed_at']);

        $match = $candidates->first(
            fn ($request) => abs($activity->created_at->diffInSeconds($request->reviewed_at)) <= 5
        );

        if (! $match) {
            return null;
        }

        return [
            'from' => self::PHASE_LABELS[$match->from_phase] ?? ucfirst($match->from_phase),
            'to' => self::PHASE_LABELS[$match->to_phase] ?? ucfirst($match->to_phase),
        ];
    }
}
