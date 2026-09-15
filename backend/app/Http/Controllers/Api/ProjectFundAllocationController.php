<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ProjectLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectFundAllocationRequest;
use App\Http\Resources\ProjectFundAllocationResource;
use App\Models\Project;
use App\Models\ProjectFundAllocation;
use Illuminate\Support\Facades\DB;

class ProjectFundAllocationController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('viewAny', [ProjectFundAllocation::class, $project]);

        return ProjectFundAllocationResource::collection(
            $project->fundAllocations()
                ->with('recorder')
                ->latest('allocation_date')
                ->latest()
                ->get()
        );
    }

    public function store(StoreProjectFundAllocationRequest $request, Project $project)
    {
        $this->authorize('create', [ProjectFundAllocation::class, $project]);

        $path = $request->file('proof_file')->store('project-fund-allocations', 'public');

        $allocation = DB::transaction(function () use ($request, $project, $path) {
            $allocation = ProjectFundAllocation::create([
                'project_id' => $project->id,
                'amount' => $request->validated('amount'),
                'allocation_date' => $request->validated('allocation_date'),
                'proof_file' => $path,
                'recorded_by' => auth()->id(),
            ]);

            // The first allocation on a committee_ready project advances it
            // to funding_ready — see ProjectLifecycle.
            if ($project->status === ProjectLifecycle::COMMITTEE_READY) {
                $project->update(['status' => ProjectLifecycle::FUNDING_READY]);
            }

            return $allocation;
        });

        return new ProjectFundAllocationResource(
            $allocation->load('recorder')
        );
    }
}
