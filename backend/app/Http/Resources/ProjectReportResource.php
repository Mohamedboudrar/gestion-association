<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'summary' => $this->summary,
            'generated_by' => $this->generator?->name,
            'download_path' => '/project-reports/'.$this->id.'/download',
            'created_at' => $this->created_at,
        ];
    }
}
