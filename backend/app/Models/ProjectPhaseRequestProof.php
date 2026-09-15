<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectPhaseRequestProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_phase_request_id',
        'file_path',
        'original_name',
        'mime_type',
        'size',
    ];

    public function phaseRequest()
    {
        return $this->belongsTo(ProjectPhaseRequest::class, 'project_phase_request_id');
    }
}
