<?php

namespace App\Exports;

use App\Models\Project;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;

class ProjectsExport implements FromCollection
{
    public function collection(): Collection
    {
        return Project::with(['manager','members'])
            ->get()
            ->map(function ($project){

                return [

                    'ID' => $project->id,

                    'Name' => $project->name,

                    'Manager' => optional($project->manager)->name,

                    'Status' => $project->status,

                    'Budget' => $project->budget,

                    'Members' => $project->members->count(),

                ];

            });
    }
}