<?php

use App\Models\Notification;
use App\Models\Project;

it('notifies the committee leader and the president when an active project passes its end date', function () {
    $president = presidentActor();
    $project = Project::factory()->active()->create(['end_date' => now()->subWeek()]);
    $leader = committeeLeaderActor($project);

    $this->artisan('app:notify-overdue-projects')->assertExitCode(0);

    $this->assertDatabaseHas('notifications', ['user_id' => $leader->id, 'type' => 'project_overdue']);
    $this->assertDatabaseHas('notifications', ['user_id' => $president->id, 'type' => 'project_overdue']);
});

it('does not flag a project whose end date has not passed yet', function () {
    presidentActor();
    $project = Project::factory()->active()->create(['end_date' => now()->addMonth()]);
    committeeLeaderActor($project);

    $this->artisan('app:notify-overdue-projects');

    $this->assertDatabaseMissing('notifications', ['type' => 'project_overdue']);
});

it('does not flag a completed or cancelled project even if its end date has passed', function () {
    presidentActor();
    $completed = Project::factory()->completed()->create(['end_date' => now()->subMonth()]);
    $cancelled = Project::factory()->cancelled()->create(['end_date' => now()->subMonth()]);
    committeeLeaderActor($completed);
    committeeLeaderActor($cancelled);

    $this->artisan('app:notify-overdue-projects');

    $this->assertDatabaseMissing('notifications', ['type' => 'project_overdue']);
});

it('still notifies the president for an overdue project with no committee leader assigned', function () {
    $president = presidentActor();
    Project::factory()->active()->create(['end_date' => now()->subWeek()]);

    $this->artisan('app:notify-overdue-projects');

    $this->assertDatabaseHas('notifications', ['user_id' => $president->id, 'type' => 'project_overdue']);
});

it('never sends a duplicate overdue notification for the same project on a second run', function () {
    presidentActor();
    $project = Project::factory()->active()->create(['end_date' => now()->subWeek()]);
    $leader = committeeLeaderActor($project);

    $this->artisan('app:notify-overdue-projects');
    $countBefore = Notification::where('type', 'project_overdue')->where('user_id', $leader->id)->count();

    $this->artisan('app:notify-overdue-projects');

    expect(Notification::where('type', 'project_overdue')->where('user_id', $leader->id)->count())->toBe($countBefore)
        ->and($countBefore)->toBe(1);
});
