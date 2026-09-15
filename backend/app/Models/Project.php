<?php

namespace App\Models;

use App\Helpers\ProjectLifecycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Project extends Model
{
    use HasFactory, LogsActivity;

    protected $fillable = [
        'name',
        'description',
        'start_date',
        'end_date',
        'budget',
        'status',
        'phase',
        'latitude',
        'longitude',
        'manager_id',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'budget' => 'decimal:2',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function isLocked(): bool
    {
        return ProjectLifecycle::isTerminal($this->status);
    }

    public function isActive(): bool
    {
        return $this->status === ProjectLifecycle::ACTIVE;
    }

    public function members()
    {
        return $this->belongsToMany(Member::class)
            ->withPivot('role', 'committee_role', 'responsibility', 'assigned_by', 'assigned_at')
            ->withTimestamps();
    }

    public function donations()
    {
        return $this->hasMany(Donation::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function fundAllocations()
    {
        return $this->hasMany(ProjectFundAllocation::class);
    }

    public function reports()
    {
        return $this->hasMany(ProjectReport::class);
    }

    public function committeeAssignments()
    {
        return $this->hasMany(CommitteeAssignment::class);
    }

    public function phaseRequests()
    {
        return $this->hasMany(ProjectPhaseRequest::class);
    }

    public function pendingPhaseRequest(): ?ProjectPhaseRequest
    {
        return $this->phaseRequests()->where('status', 'pending')->first();
    }

    public function deletionRequests()
    {
        return $this->hasMany(ProjectDeletionRequest::class);
    }

    public function pendingDeletionRequest(): ?ProjectDeletionRequest
    {
        return $this->deletionRequests()->where('status', 'pending')->first();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty();
    }
}
