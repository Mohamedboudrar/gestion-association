<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogHelper;
use App\Helpers\AuthorizationHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActivityLogResource;
use App\Models\Donation;
use App\Models\Expense;
use App\Models\Member;
use App\Models\Project;
use App\Models\ProjectFundAllocation;
use App\Models\ProjectReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\Request;
use Spatie\Activitylog\Models\Activity;

class ActivityLogController extends Controller
{
    private const DEFAULT_PER_PAGE = 25;

    private const MAX_PER_PAGE = 100;

    public function index(Request $request)
    {
        $this->authorize('viewAny', Activity::class);

        $user = auth()->user();

        $query = Activity::query()->with([
            'causer',
            'subject' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Donation::class => ['project'],
                    Expense::class => ['project'],
                    ProjectFundAllocation::class => ['project'],
                    ProjectReport::class => ['project'],
                    Member::class => ['user'],
                ]);
            },
        ]);

        if (! AuthorizationHelper::canViewAllActivities($user)) {
            ActivityLogHelper::scopeToAssignedProjects($query, $user);
        }

        ActivityLogHelper::applyFilters($query, $request->only([
            'user_id', 'entity', 'project_id', 'status', 'date_from', 'date_to', 'action',
        ]));

        if ($request->filled('search')) {
            ActivityLogHelper::applySearch($query, (string) $request->query('search'));
        }

        $direction = $request->query('sort') === 'asc' ? 'asc' : 'desc';
        $query->orderBy('activity_log.created_at', $direction)->orderBy('activity_log.id', $direction);

        $perPage = min(self::MAX_PER_PAGE, max(1, (int) $request->query('per_page', self::DEFAULT_PER_PAGE)));

        $paginator = $query->paginate($perPage)->withQueryString();

        // through() transforms each item in place and keeps the paginator's
        // own toArray()/JSON shape (current_page/data/last_page/... at the
        // top level) — the shape every other paginated endpoint in this app
        // already returns, rather than switching to the nested
        // data/links/meta envelope a bare Resource::collection() would produce.
        $paginator->through(fn (Activity $activity) => (new ActivityLogResource($activity))->resolve());

        return response()->json($paginator);
    }

    /**
     * Options for the filter bar's User/Project dropdowns — scoped
     * identically to index(), so a filtered-view user is never offered a
     * user/project they wouldn't be allowed to actually filter by.
     */
    public function filters(Request $request)
    {
        $this->authorize('viewAny', Activity::class);

        $user = auth()->user();

        $activityQuery = Activity::query();

        if (! AuthorizationHelper::canViewAllActivities($user)) {
            ActivityLogHelper::scopeToAssignedProjects($activityQuery, $user);
        }

        $causerIds = (clone $activityQuery)->whereNotNull('causer_id')->distinct()->pluck('causer_id');
        $users = User::whereIn('id', $causerIds)->orderBy('name')->get(['id', 'name']);

        if (AuthorizationHelper::canViewAllActivities($user)) {
            $projects = Project::orderBy('name')->get(['id', 'name']);
        } else {
            $member = $user->member;
            $projects = $member ? $member->projects()->orderBy('name')->get(['projects.id', 'projects.name']) : collect();
        }

        return response()->json([
            'users' => $users,
            'projects' => $projects->map(fn ($project) => ['id' => $project->id, 'name' => $project->name])->values(),
            'entities' => collect(ActivityLogHelper::ENTITIES)->map(fn ($meta, $key) => [
                'key' => $key,
                'label' => $meta['label'],
                'statuses' => collect($meta['status_labels'])->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            ])->values(),
        ]);
    }

    public function show(Activity $activity)
    {
        $this->authorize('view', $activity);

        $activity->load([
            'causer',
            'subject' => function (MorphTo $morphTo) {
                $morphTo->morphWith([
                    Donation::class => ['project', 'member.user'],
                    Expense::class => ['project'],
                    ProjectFundAllocation::class => ['project'],
                    ProjectReport::class => ['project'],
                    Member::class => ['user'],
                ]);
            },
        ]);

        $resource = new ActivityLogResource($activity);
        $resource->withDetails = true;

        return response()->json(['data' => $resource->resolve()]);
    }
}
