<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectPhaseRequestProof;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Single place that actually deletes a project — used by both the
 * president's direct delete (ProjectController::destroy) and an approved
 * ProjectDeletionRequest (ProjectDeletionRequestController::approve), so the
 * "no orphan records" and audit-logging behavior can't drift between the
 * two paths.
 *
 * Every table with a project_id foreign key is already onDelete('cascade')
 * at the DB level (member_project, donations, expenses,
 * project_fund_allocations, project_reports, committee_assignments,
 * project_phase_requests -> project_phase_request_proofs), so $project->delete()
 * alone leaves no orphaned rows. What cascade does NOT clean up is the actual
 * files those rows point to on the public disk — deleteRelatedFiles() below
 * walks every one of those paths before the row (and therefore the path)
 * disappears.
 */
class ProjectDeletionService
{
    public function delete(Project $project, ?string $reason = null): void
    {
        DB::transaction(function () use ($project, $reason) {
            $this->deleteRelatedFiles($project);

            activity()
                ->causedBy(auth()->user())
                ->performedOn($project)
                ->withProperties(array_filter([
                    'project_name' => $project->name,
                    'budget' => (float) $project->budget,
                    'reason' => $reason,
                ]))
                ->log('Project deleted.');

            $project->delete();
        });
    }

    private function deleteRelatedFiles(Project $project): void
    {
        $disk = Storage::disk('public');

        foreach ($project->expenses()->whereNotNull('invoice_path')->pluck('invoice_path') as $path) {
            $disk->delete($path);
        }

        foreach ($project->donations()->whereNotNull('receipt_file')->pluck('receipt_file') as $path) {
            $disk->delete($path);
        }

        foreach ($project->fundAllocations()->whereNotNull('proof_file')->pluck('proof_file') as $path) {
            $disk->delete($path);
        }

        foreach ($project->reports()->whereNotNull('file_path')->pluck('file_path') as $path) {
            $disk->delete($path);
        }

        $phaseRequestIds = $project->phaseRequests()->pluck('id');

        foreach (
            ProjectPhaseRequestProof::whereIn('project_phase_request_id', $phaseRequestIds)->pluck('file_path') as $path
        ) {
            $disk->delete($path);
        }
    }
}
