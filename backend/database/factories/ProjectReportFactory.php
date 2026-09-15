<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectReport>
 */
class ProjectReportFactory extends Factory
{
    protected $model = ProjectReport::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'generated_by' => null,
            'file_path' => 'project-reports/'.fake()->uuid().'.pdf',
            'summary' => [
                'donations_total' => 0,
                'expenses_total' => 0,
                'allocations_total' => 0,
                'returned_to_pool' => 0,
                'budget' => 0,
                'donation_count' => 0,
                'expense_count' => 0,
            ],
        ];
    }
}
