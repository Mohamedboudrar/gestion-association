<?php

/*
|--------------------------------------------------------------------------
| Reusable test actors and committee-setup helpers
|--------------------------------------------------------------------------
|
| Every function here is globally available in any Pest test (required by
| tests/Pest.php). They exist so individual test files never hand-roll
| "create a user, give them a role, attach a member record" — that setup
| is identical everywhere and belongs in one place.
*/

use App\Models\CommitteeAssignment;
use App\Models\Member;
use App\Models\Project;
use App\Models\Subscription;
use App\Models\User;

/**
 * A bureau user with the given Spatie role and a linked, verified Member
 * record (every bureau role in this app is also a Member — see
 * CreateBureauAccounts — so tests reflect that reality by default).
 */
function userWithRole(string $role, array $userAttributes = []): User
{
    $user = User::factory()->create($userAttributes);
    $user->assignRole($role);

    Member::factory()->for($user)->create();

    return $user->fresh();
}

function presidentActor(array $attributes = []): User
{
    return userWithRole('president', $attributes);
}

function treasurerActor(array $attributes = []): User
{
    return userWithRole('tresorier', $attributes);
}

function viceTreasurerActor(array $attributes = []): User
{
    return userWithRole('vice-tresorier', $attributes);
}

function vicePresidentActor(array $attributes = []): User
{
    return userWithRole('vice-president', $attributes);
}

function secretaireGeneralActor(array $attributes = []): User
{
    return userWithRole('secretaire-general', $attributes);
}

function viceSecretaireGeneralActor(array $attributes = []): User
{
    return userWithRole('vice-secretaire-general', $attributes);
}

function conseillerActor(array $attributes = []): User
{
    return userWithRole('conseiller', $attributes);
}

/**
 * A plain subscriber ("abonne") — no bureau role. Has a Member record but
 * no committee assignment and no verified subscription unless requested.
 */
function subscriberActor(array $attributes = [], bool $verifiedSubscription = false): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole('abonne');

    $member = Member::factory()->for($user)->create();

    if ($verifiedSubscription) {
        Subscription::factory()->for($member)->verified()->create();
    }

    return $user->fresh();
}

/**
 * Attaches an existing user (must already have a Member record) to a
 * project's committee with the given committee_role, mirroring exactly
 * what ProjectMemberController::store does on the pivot + history table —
 * bypassing the HTTP layer for fast, deliberate test setup. Also gives the
 * member a verified subscription, matching the real eligibility rule
 * (AuthorizationHelper::hasVerifiedSubscription) every non-bureau committee
 * assignment must satisfy.
 */
function assignToCommittee(Project $project, User $user, string $committeeRole = 'member'): void
{
    $member = $user->member ?? Member::factory()->for($user)->create();

    if (! $user->hasAnyRole(['president', 'vice-president', 'tresorier', 'vice-tresorier', 'secretaire-general', 'vice-secretaire-general', 'conseiller'])
        && ! $member->subscriptions()->where('status', 'verified')->exists()) {
        Subscription::factory()->for($member)->verified()->create();
    }

    $project->members()->attach($member->id, [
        'committee_role' => $committeeRole,
        'assigned_at' => now(),
    ]);

    CommitteeAssignment::factory()->create([
        'project_id' => $project->id,
        'member_id' => $member->id,
        'committee_role' => $committeeRole,
        'assigned_at' => now(),
        'action' => 'assigned',
    ]);
}

/**
 * A plain subscriber created and assigned as a project's committee leader
 * in one step — the most common "Committee Leader" actor tests need.
 */
function committeeLeaderActor(Project $project, array $attributes = []): User
{
    $user = subscriberActor($attributes);
    assignToCommittee($project, $user, 'leader');

    return $user->fresh();
}

function committeeTreasurerActor(Project $project, array $attributes = []): User
{
    $user = subscriberActor($attributes);
    assignToCommittee($project, $user, 'treasurer');

    return $user->fresh();
}

/**
 * A plain subscriber assigned to a project as an ordinary committee member
 * (not leader/treasurer) — the "Committee Member: read-only" actor.
 */
function committeeMemberActor(Project $project, array $attributes = []): User
{
    $user = subscriberActor($attributes);
    assignToCommittee($project, $user, 'member');

    return $user->fresh();
}
