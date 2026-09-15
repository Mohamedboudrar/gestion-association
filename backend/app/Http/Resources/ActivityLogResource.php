<?php

namespace App\Http\Resources;

use App\Helpers\ActivityLogHelper;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    // Set by ActivityLogController::show() to include the full subject
    // record + old/new property diff for the side-panel Details view. The
    // list view (index) omits these — the card doesn't need them.
    public bool $withDetails = false;

    public function toArray(Request $request): array
    {
        $described = ActivityLogHelper::describe($this->resource);

        $data = [
            'id' => $this->id,
            'event' => $this->event,
            'causer' => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name,
            ] : null,
            'entity_key' => $described['entity_key'],
            'entity_label' => $described['entity_label'],
            'reference' => $described['reference'],
            'action_label' => $described['action_label'],
            'message' => $described['message'],
            'status' => $described['status'],
            'project' => $described['project'],
            'created_at' => $this->created_at,
        ];

        if ($this->withDetails) {
            $data['changes'] = [
                'old' => $this->properties['old'] ?? null,
                'new' => $this->properties['attributes'] ?? null,
            ];
            $data['related'] = $this->buildRelated();
        }

        return $data;
    }

    private function buildRelated(): array
    {
        $subject = $this->subject;

        $related = [
            'project' => null,
            'member' => null,
            'donation' => null,
            'expense' => null,
        ];

        if (! $subject) {
            return $related;
        }

        $entityKey = ActivityLogHelper::entityKeyFor($this->subject_type);

        $project = match ($entityKey) {
            'project' => $subject,
            'donation', 'expense', 'fund_allocation', 'report' => $subject->relationLoaded('project') ? $subject->project : $subject->project()->first(),
            default => null,
        };

        if ($project) {
            $related['project'] = ['id' => $project->id, 'name' => $project->name, 'status' => $project->status ?? null];
        }

        $member = match ($entityKey) {
            'member' => $subject,
            'donation' => $subject->member_id ? ($subject->relationLoaded('member') ? $subject->member : $subject->member()->first()) : null,
            default => null,
        };

        if ($member) {
            $related['member'] = ['id' => $member->id, 'name' => $member->user?->name ?? $member->name ?? null];
        }

        if ($entityKey === 'donation') {
            $related['donation'] = new DonationResource($subject);
        }

        if ($entityKey === 'expense') {
            $related['expense'] = new ExpenseResource($subject);
        }

        return $related;
    }
}
