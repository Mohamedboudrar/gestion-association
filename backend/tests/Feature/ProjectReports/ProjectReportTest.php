<?php

use App\Models\CommitteeAssignment;
use App\Models\Project;
use App\Models\ProjectReport;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Project closure reports: viewAny/view authorization, including the
| CommitteeAssignment-history fallback that must survive the committee
| being dissolved when the project closes (ProjectController::close()).
|--------------------------------------------------------------------------
*/

beforeEach(function () {
    Storage::fake('public');
});

// Mirrors ProjectController::close()'s dissolveCommittee(): closes the
// CommitteeAssignment history row with removed_at/action=dissolved, then
// detaches the live member_project pivot row entirely.
function dissolveCommitteeFor(Project $project): void
{
    CommitteeAssignment::where('project_id', $project->id)
        ->whereNull('removed_at')
        ->update([
            'removed_at' => now(),
            'reason' => 'Project completed.',
            'action' => 'dissolved',
        ]);

    $project->members()->detach();
}

// --- viewAny (GET /projects/{project}/reports) ---

it('allows any live committee role to list a project\'s closure reports', function (string $role) {
    $project = Project::factory()->completed()->create();
    $user = match ($role) {
        'leader' => committeeLeaderActor($project),
        'treasurer' => committeeTreasurerActor($project),
        'member' => committeeMemberActor($project),
    };
    ProjectReport::factory()->for($project)->create();

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/projects/{$project->id}/reports")
        ->assertOk();
})->with(['leader', 'treasurer', 'member']);

it('allows every bureau role to list closure reports regardless of committee membership', function (string $actor) {
    $project = Project::factory()->completed()->create();
    ProjectReport::factory()->for($project)->create();

    $user = match ($actor) {
        'president' => presidentActor(),
        'vice-president' => vicePresidentActor(),
        'tresorier' => treasurerActor(),
        'vice-tresorier' => viceTreasurerActor(),
        'secretaire-general' => secretaireGeneralActor(),
        'vice-secretaire-general' => viceSecretaireGeneralActor(),
        'conseiller' => conseillerActor(),
    };

    $this->actingAs($user, 'sanctum')
        ->getJson("/api/projects/{$project->id}/reports")
        ->assertOk();
})->with(['president', 'vice-president', 'tresorier', 'vice-tresorier', 'secretaire-general', 'vice-secretaire-general', 'conseiller']);

it('forbids a subscriber with no committee history from listing closure reports', function () {
    $project = Project::factory()->completed()->create();
    ProjectReport::factory()->for($project)->create();
    $subscriber = subscriberActor();

    $this->actingAs($subscriber, 'sanctum')
        ->getJson("/api/projects/{$project->id}/reports")
        ->assertStatus(403);
});

// --- the regression: CommitteeAssignment history survives committee dissolution ---

it('lets a former committee leader keep access to the closure report after the committee is dissolved on project close', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $report = ProjectReport::factory()->for($project)->create();

    Storage::disk('public')->put($report->file_path, 'fake pdf content');

    // Sanity check: access works while the committee is still live.
    $this->actingAs($leader, 'sanctum')
        ->getJson("/api/projects/{$project->id}/reports")
        ->assertOk();

    // Simulate what ProjectController::close() does: detach the live
    // member_project pivot row but keep the CommitteeAssignment history
    // row (closed with removed_at, not deleted).
    dissolveCommitteeFor($project);

    expect($leader->fresh()->committeeRoleFor($project->fresh()))->toBeNull();
    $this->assertDatabaseMissing('member_project', [
        'project_id' => $project->id,
        'member_id' => $leader->member->id,
    ]);
    $this->assertDatabaseHas('committee_assignments', [
        'project_id' => $project->id,
        'member_id' => $leader->member->id,
        'action' => 'dissolved',
    ]);

    // The same user must still be able to access the report list...
    $this->actingAs($leader, 'sanctum')
        ->getJson("/api/projects/{$project->id}/reports")
        ->assertOk();

    // ...and the report download itself.
    $this->actingAs($leader, 'sanctum')
        ->getJson("/api/project-reports/{$report->id}/download")
        ->assertOk();
});

it('forbids a subscriber who was never on the committee, even after dissolution, from accessing the report', function () {
    $project = Project::factory()->active()->create();
    $leader = committeeLeaderActor($project);
    $report = ProjectReport::factory()->for($project)->create();
    Storage::disk('public')->put($report->file_path, 'fake pdf content');

    dissolveCommitteeFor($project);

    $neverAssigned = subscriberActor();

    $this->assertDatabaseMissing('committee_assignments', [
        'project_id' => $project->id,
        'member_id' => $neverAssigned->member->id,
    ]);

    $this->actingAs($neverAssigned, 'sanctum')
        ->getJson("/api/projects/{$project->id}/reports")
        ->assertStatus(403);

    $this->actingAs($neverAssigned, 'sanctum')
        ->getJson("/api/project-reports/{$report->id}/download")
        ->assertStatus(403);
});

// --- download ---

it('downloads a closure report with the expected filename pattern', function () {
    $project = Project::factory()->completed()->create();
    $president = presidentActor();
    $report = ProjectReport::factory()->for($project)->create();

    Storage::disk('public')->put($report->file_path, 'fake pdf content');

    $response = $this->actingAs($president, 'sanctum')
        ->getJson("/api/project-reports/{$report->id}/download");

    $response->assertOk();
    $response->assertHeader(
        'content-disposition',
        "attachment; filename=project-{$project->id}-closure-report.pdf"
    );
});

it('forbids a committee member of a different project from downloading this report', function () {
    $project = Project::factory()->completed()->create();
    $report = ProjectReport::factory()->for($project)->create();
    Storage::disk('public')->put($report->file_path, 'fake pdf content');

    $otherProject = Project::factory()->completed()->create();
    $otherLeader = committeeLeaderActor($otherProject);

    $this->actingAs($otherLeader, 'sanctum')
        ->getJson("/api/project-reports/{$report->id}/download")
        ->assertStatus(403);
});
