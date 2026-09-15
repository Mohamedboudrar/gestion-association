<?php

namespace App\Console\Commands;

use App\Helpers\ProjectLifecycle;
use App\Models\Notification;
use App\Models\Project;
use Illuminate\Console\Command;

class NotifyOverdueProjects extends Command
{
    protected $signature = 'app:notify-overdue-projects';

    protected $description = 'Notify a project\'s committee leader(s) and the president once its end date has passed without being closed';

    public function handle(): int
    {
        $projects = Project::whereNotIn('status', ProjectLifecycle::TERMINAL)
            ->whereDate('end_date', '<', now()->toDateString())
            ->with('members')
            ->get();

        $notified = 0;

        foreach ($projects as $project) {
            $leaderUserIds = $project->members
                ->filter(fn ($member) => $member->pivot->committee_role === 'leader')
                ->pluck('user_id')
                ->filter()
                ->all();

            $title = __('notifications.project.overdue_title');
            $message = __('notifications.project.overdue_message', [
                'name' => $project->name,
                'date' => $project->end_date->toDateString(),
                'status' => $project->status,
            ]);

            if ($leaderUserIds) {
                Notification::notifyUsersOnce($leaderUserIds, 'project_overdue', $title, $message, $project);
            }

            // The president stays informed of every overdue project, not
            // just ones with a committee leader assigned.
            Notification::notifyRolesOnce(['president'], 'project_overdue', $title, $message, $project);

            $notified++;
            $this->info("Flagged overdue project #{$project->id} ({$project->name}).");
        }

        $this->info("{$notified} overdue project(s) checked.");

        return self::SUCCESS;
    }
}
