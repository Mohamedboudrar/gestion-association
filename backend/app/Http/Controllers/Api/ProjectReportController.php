<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectReportResource;
use App\Models\Project;
use App\Models\ProjectReport;
use Illuminate\Support\Facades\Storage;

class ProjectReportController extends Controller
{
    public function index(Project $project)
    {
        $this->authorize('viewAny', [ProjectReport::class, $project]);

        return ProjectReportResource::collection(
            $project->reports()
                ->with('generator')
                ->latest()
                ->get()
        );
    }

    public function download(ProjectReport $projectReport)
    {
        $this->authorize('view', $projectReport);

        return Storage::disk('public')->download(
            $projectReport->file_path,
            "project-{$projectReport->project_id}-closure-report.pdf"
        );
    }
}
