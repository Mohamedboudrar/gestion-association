<?php

namespace Database\Factories;

use App\Models\ProjectPhaseRequest;
use App\Models\ProjectPhaseRequestProof;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectPhaseRequestProof>
 */
class ProjectPhaseRequestProofFactory extends Factory
{
    protected $model = ProjectPhaseRequestProof::class;

    public function definition(): array
    {
        return [
            'project_phase_request_id' => ProjectPhaseRequest::factory(),
            'file_path' => 'project-phase-proofs/'.fake()->uuid().'.pdf',
            'original_name' => 'proof.pdf',
            'mime_type' => 'application/pdf',
            'size' => fake()->numberBetween(1000, 500000),
        ];
    }
}
