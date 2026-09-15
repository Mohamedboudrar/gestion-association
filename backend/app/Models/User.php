<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        // Deterministic (unsalted-by-design) hash used to look up a passkey
        // login by value — see App\Services\PasskeyService. Never serialize it.
        'passkey_hash',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'passkey_created_at' => 'datetime',
            'last_passkey_sent_at' => 'datetime',
        ];
    }
    public function member()
    {
        return $this->hasOne(Member::class);
    }

    // True once this subscriber has been verified and issued a passkey — the
    // only thing that gates access to the Member Portal. Deliberately not
    // fillable (see PasskeyService::issueFor) so it can never be set via mass
    // assignment from a request.
    public function hasPortalAccess(): bool
    {
        return ! is_null($this->passkey_hash);
    }
public function managedProjects()
{
    return $this->hasMany(Project::class, 'manager_id');
}

public function recordedDonations()
{
    return $this->hasMany(Donation::class, 'recorded_by');
}

public function createdExpenses()
{
    return $this->hasMany(Expense::class, 'created_by');
}

public function committeeRoleFor(Project $project): ?string
{
    if (! $this->member) {
        return null;
    }

    return $project->members()
        ->whereKey($this->member->id)
        ->value('member_project.committee_role');
}
}
