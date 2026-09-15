# Backend Summary

## Tech Stack

- **Framework**: Laravel 13 (`^13.8`), PHP `^8.3`
- **Auth**: Laravel Sanctum `^4.0` (personal access tokens)
- **Roles/Permissions**: spatie/laravel-permission `^8.2`
- **Audit Trail**: spatie/laravel-activitylog `^5.0`
- **PDF Reports**: barryvdh/laravel-dompdf `^3.1`
- **Excel Exports**: maatwebsite/excel `^3.1`
- **Transactional Email**: MailerSend HTTP API (no package — a thin `Http`-based service, see Member Portal below), used only for the two member-portal emails
- **Database**: MySQL (`association_db` in local dev)
- **Localization**: French is the app's default and fallback locale (`APP_LOCALE=fr`, `APP_FALLBACK_LOCALE=fr` in `config/app.php`) — every user-facing string the API emits (validation errors, `{message}` responses, 403 deny text, notifications, emails, PDF letterheads) is resolved through `__()`/`lang/fr/*.php`, never a hardcoded literal. See Localization below.
- **Code Style**: Laravel Pint (PSR-12)
- **Tests**: Pest 4 (`pestphp/pest` + `pestphp/pest-plugin-laravel`) on top of PHPUnit 12 — 448 tests across `tests/Feature` + `tests/Unit`, ~92% line coverage (`composer test-coverage`, pcov). `tests/Pest.php` seeds Roles/Permissions before every test; `tests/Support/helpers.php` has reusable role-actor factories (`presidentActor()`, `treasurerActor()`, `subscriberActor()`, etc.)

## Project Structure

```
backend/
├── app/
│   ├── Console/Commands/       # 5 commands
│   │   ├── CreateBureauAccounts.php
│   │   ├── ExpireSubscriptions.php     # scheduled daily — also sends 30/7/0-day expiry reminders, see Subscription Expiration & Notification Center below
│   │   ├── GenerateAnnualDues.php      # scheduled yearly (Jan 1) — see Annual Dues below
│   │   ├── MarkOverdueDues.php         # scheduled daily — see Annual Dues below
│   │   └── NotifyOverdueProjects.php   # scheduled daily — see Notification Center below
│   ├── Exports/                # Maatwebsite Excel export classes
│   │   ├── MembersExport.php
│   │   ├── ProjectsExport.php
│   │   ├── SubscriptionsExport.php
│   │   └── DuesExport.php      # see Annual Dues below
│   ├── Helpers/                # 6 helpers
│   │   ├── ActivityLogHelper.php # single source of truth for the Activity Explorer's entity map/filters/messages — see Activity Explorer below
│   │   ├── AuthorizationHelper.php
│   │   ├── ExpenseBudgetValidator.php # single source of truth for the per-project budget-ceiling check — see Expense Budget Validation below
│   │   ├── FundsHelper.php     # single source of truth for the Available Funds formula — see below
│   │   ├── ProjectLifecycle.php # single source of truth for the project stage state machine — see below
│   │   └── ProjectPhaseWorkflow.php # single source of truth for the execution-phase state machine — see below
│   ├── Services/               # 5 services
│   │   ├── DuesService.php         # single source of truth for the dues ledger's math — see Annual Dues below
│   │   ├── MailerSendService.php   # reusable MailerSend API client — see Member Portal below
│   │   ├── PasskeyService.php      # single source of truth for member-portal passkeys — see Member Portal below
│   │   ├── ProjectDeletionService.php # cascade delete + file cleanup for a project — see Project Deletion Workflow below
│   │   └── SettingsService.php     # cached singleton accessor for association settings — see Association Settings below
│   ├── Http/
│   │   ├── Controllers/Api/    # 20 — one controller per resource, plus ActionCenterController (consolidated president pending-items read, see Action Center below)
│   │   ├── Requests/           # FormRequest validation (Store*/Update*)
│   │   └── Resources/          # JsonResource response shapes
│   ├── Models/                 # 15 — User, Member, Subscription, Due, Project, Donation, Expense, CommitteeAssignment, ProjectFundAllocation, ProjectReport, ProjectPhaseRequest, ProjectPhaseRequestProof, ProjectDeletionRequest, AssociationSetting, Notification
│   ├── Policies/                # One policy per resource, gate every authorize() call
│   └── Providers/AppServiceProvider.php  # registers ActivityPolicy on Spatie's Activity model
├── database/
│   ├── migrations/             # 33 migrations — latest `2026_08_11_102118_add_performance_indexes_for_dashboard_notifications_action_center` adds composite `notifications` indexes ([user_id, read_at], [user_id, created_at]) + single `status` indexes on subscriptions/donations/expenses/projects/project_phase_requests/project_deletion_requests, backing the dashboard/Notification Center/Action Center queries
│   ├── seeders/                # DatabaseSeeder → RoleSeeder → PermissionSeeder → RolePermissionSeeder → PresidentSeeder (production baseline). Plus a Demo/ subsystem (8 seeders, see Seeders below) + Support/MoroccanData.php (localized fake-data pools) for a realistic dev dataset
│   └── factories/              # 14 — one per major model (UserFactory, MemberFactory, SubscriptionFactory,
│                                #   DueFactory, ProjectFactory, DonationFactory, ExpenseFactory, and 7 more),
│                                #   backing the Pest test suite under tests/Feature and tests/Unit
├── resources/views/emails/     # welcome.blade.php, passkey-reset.blade.php — see Member Portal below
├── bootstrap/app.php            # ->withSchedule(...) registers the daily expire-subscriptions job
└── routes/api.php              # All routes, prefixed /api
```

For full column-by-column schema and an ER diagram, see `backend/DATABASE.md` — this file summarizes only what changes controller/policy behavior.

## Database Schema (summary)

| Table | Key columns |
|---|---|
| `users` | name, email, password, **passkey_hash** (nullable, unique, indexed — never exposed, see Member Portal below), **passkey_created_at**, **last_passkey_sent_at** (both nullable) |
| `members` | user_id (FK users), phone, address |
| `subscriptions` | member_id, **due_id** (nullable FK `dues`, `nullOnDelete()` — the Annual Due this payment applies toward; `null` for every subscription that predates the Annual Dues system, see below), amount, payment_method, receipt_number, receipt_file, payment_date, expires_at, status (pending/verified/rejected/expired), verified_by (FK users), verified_at, notes |
| `dues` | member_id (FK, `cascadeOnDelete()`), **year** (unsigned smallint), amount_due, amount_paid (default 0), balance, status (enum: pending/partial/paid/overdue/waived, default `pending`), due_date, paid_at (nullable), waived_reason (nullable) — **unique on (member_id, year)**, the DB-level guarantee behind "dues generation never duplicates". See Annual Dues below |
| `projects` | name, description, start_date, end_date, budget, **status** (enum: draft/committee_ready/funding_ready/active/completed/cancelled, default `draft` — renamed/expanded from the old `planned/active/completed/cancelled`, existing `planned` rows backfilled to `draft`), **phase** (enum: planning/preparation/in_progress/finishing/completed, default `planning` — a separate axis from `status`, tracks execution progress, existing `completed`-status rows backfilled to phase `completed`), **latitude** (`decimal(10,8)`, nullable), **longitude** (`decimal(11,8)`, nullable) — see Project Location below, manager_id (FK users) — see Project Lifecycle and Project Phase Approval Workflow below |
| `member_project` (pivot) | member_id, project_id, role (legacy free text), **committee_role** (leader/treasurer/secretary/member, default `member` — this is what authorization actually reads), responsibility, assigned_by, assigned_at — **unified**: both `Member::projects()` and `Project::members()` now expose the identical full set of these 5 pivot columns (see Models) |
| `committee_assignments` | project_id (FK), member_id (FK), role, committee_role, responsibility, assigned_by (FK users), assigned_at, removed_by (FK users), removed_at, reason, action (enum: assigned/replaced/resigned/removed/dissolved, default `assigned`) — append-only committee history ledger, see Committee Assignment Lifecycle below |
| `donations` | member_id (nullable), **project_id (required)**, donor_name, amount, payment_method, receipt_number, receipt_file, donation_date, notes, recorded_by (FK users), **status** (enum: draft/pending/approved/rejected, default `draft`), approved_by (FK users), approved_at, rejected_by (FK users), rejected_at, rejection_reason, **rejection_type** (nullable enum: receipt/details) — see Donation Approval Workflow below |
| `expenses` | **project_id (required)**, supplier_name, description, amount, payment_method, invoice_number, invoice_path, expense_date, notes, created_by (FK users), **status** (enum: draft/pending/approved/rejected/paid, default `draft`), approved_by (FK users), approved_at, rejected_by (FK users), rejected_at, rejection_reason, **rejection_type** (nullable enum: invoice/details), paid_by (FK users), paid_at — see Expense Approval Workflow below |
| `project_fund_allocations` | project_id (FK), amount, allocation_date, proof_file (path, nullable in schema but `required` at the FormRequest level), recorded_by (FK users) |
| `project_reports` | project_id (FK), generated_by (FK users, nullable), file_path (closure PDF), summary (JSON: donations_total, expenses_total, allocations_total, returned_to_pool, budget, donation_count, expense_count) |
| `project_phase_requests` | project_id (FK), from_phase, to_phase, summary, notes (nullable), requested_by (FK users), requested_at, status (enum: pending/approved/rejected, default `pending`), reviewed_by (FK users, nullable), reviewed_at (nullable), rejection_reason (nullable) — append-only phase-change history ledger, never hard-deleted, see Project Phase Approval Workflow below |
| `project_phase_request_proofs` | project_phase_request_id (FK), file_path, original_name, mime_type, size — one or more proof files per phase request |
| `notifications` | user_id (FK users), type, title, message, **subject_type**/**subject_id** (polymorphic, nullable — the record this notification is about), read_at — see Notification Center below |
| `activity_log` | Spatie activitylog default schema |
| Spatie permission tables | roles, permissions, model_has_roles, model_has_permissions, role_has_permissions |
| `personal_access_tokens` | Sanctum tokens |
| `association_settings` | **Singleton** (never more than 1 row — enforced in `SettingsService`, not the schema). association_name, association_logo (path, nullable), address, phone, email, website (nullable), annual_subscription_amount (decimal), currency (default `MAD`), description (nullable) — see Association Settings below |

## Models & Relationships

- **User**: `member()` hasOne Member; `managedProjects()` hasMany Project (manager_id); `recordedDonations()`; `createdExpenses()`; **`committeeRoleFor(Project): ?string`** — reads `member_project.committee_role` for the user's member row on that project, `null` if the user has no member row or isn't on the project. Traits: HasApiTokens, HasFactory, Notifiable, HasRoles.
- **Member**: `user()`; `subscriptions()`; `donations()`; `dues()` hasMany Due (new — see Annual Dues below); `projects()` belongsToMany withPivot(`role`, `committee_role`, `responsibility`, `assigned_by`, `assigned_at`).
- **Subscription**: `member()`; `verifier()` (verified_by); **`due()`** (belongsTo Due, `due_id`, nullable — new, see Annual Dues below). Status enum now actively used end-to-end: `expired` is set by the daily `ExpireSubscriptions` command, not just a schema placeholder — see Subscription Expiration below.
- **Due** (new): `member()`; `payments()` hasMany Subscription — every payment ever applied toward this due, only `status = 'verified'` ones count toward `amount_paid`. `LogsActivity` (`logFillable()->logOnlyDirty()`, same as every other financial model). See Annual Dues below.
- **Project**: `manager()`; `members()` belongsToMany withPivot(`role`, `committee_role`, `responsibility`, `assigned_by`, `assigned_at`) — identical pivot column set to `Member::projects()` (previously these two relations had diverged, each extended independently for its own call sites; now unified); `donations()`; `expenses()`; `fundAllocations()` hasMany ProjectFundAllocation; `reports()` hasMany ProjectReport; **`committeeAssignments()`** hasMany CommitteeAssignment; **`phaseRequests()`** hasMany ProjectPhaseRequest, **`pendingPhaseRequest(): ?ProjectPhaseRequest`** (the one `status = pending` row, if any — see Project Phase Approval Workflow below); **`isLocked(): bool`** — delegates to `ProjectLifecycle::isTerminal()`, `true` when `status` is `completed` or `cancelled`, the single source of truth for the project hard-lock feature (see below); **`isActive(): bool`** — `true` when `status === 'active'`, used by the donation/expense/allocation stage gates — see Project Lifecycle below.
- **ProjectPhaseRequest**: `project()`; `requestedBy()` (requested_by); `reviewedBy()` (reviewed_by); `proofs()` hasMany ProjectPhaseRequestProof — the append-only execution-phase change-request ledger, see Project Phase Approval Workflow below. Deliberately does **not** use `LogsActivity`, same reasoning as `CommitteeAssignment` — it's already its own dedicated audit trail.
- **ProjectPhaseRequestProof**: `phaseRequest()` (project_phase_request_id) — one row per uploaded proof file (image/PDF), metadata only, actual bytes on the `public` disk.
- **CommitteeAssignment**: `project()`; `member()`; `assignedBy()` (assigned_by); `removedBy()` (removed_by); scope `active()` (`whereNull('removed_at')`) — the append-only committee history ledger, see Committee Assignment Lifecycle below. Deliberately does **not** use `LogsActivity` — it's already its own dedicated audit trail, logging it a second time would be redundant.
- **Donation**: `member()`; `project()`; `recorder()` (recorded_by); **`approver()`** (approved_by); **`rejecter()`** (rejected_by) — see Donation Approval Workflow below.
- **Expense**: `project()`; `creator()` (created_by); **`approver()`** (approved_by); **`rejecter()`** (rejected_by); **`payer()`** (paid_by) — see Expense Approval Workflow below.
- **ProjectFundAllocation**: `project()`; `recorder()` (recorded_by) — the ledger record for a president/trésorier/vice-trésorier "fund transfer" into a project. Creating one **no longer touches `project.budget`** (that increment was removed — see Fund Allocations below); it only feeds `ProjectResource.collected`/`allocated`.
- **ProjectReport**: `project()`; `generator()` (generated_by) — one row per project closure, holding the generated PDF's storage path and a JSON `summary` snapshot (financial totals at close time).
- **AssociationSetting**: no relationships — a flat singleton row. Never queried directly outside `SettingsService`; see Association Settings below.
- **Notification**: `user()`; `subject()` morphTo (nullable — the record this notification is about, e.g. an Expense or Project); static `notifyRoles(array $roles, string $type, string $title, ?string $message, ?Model $subject = null)` — bulk-inserts one row per user holding any of the given roles; static `notifyUsers(array $userIds, string $type, string $title, ?string $message, ?Model $subject = null)` — same but per explicit user id list, for audiences that aren't a global role (e.g. a project's committee members on close, or a single subscriber whose subscription just expired); **`notifyRolesOnce`/`notifyUsersOnce`** (new — require a `$subject`) — same as above but skip any recipient already notified with the exact same `(type, subject)` pair, for scheduled checks that run repeatedly (expiry reminders, overdue projects) and must never send a duplicate. See Notification Center below.
- Activity logging: Member, Subscription, Project, Donation, Expense, ProjectFundAllocation, **ProjectReport**, and **CommitteeAssignment** all use `LogsActivity` (`logFillable()->logOnlyDirty()`) — this also means every expense workflow transition (submit/approve/reject/mark paid) and every subscription auto-expiry gets an activity-log entry for free, since they're all just `update()` calls on a `LogsActivity` model. (Corrected this session — this note previously claimed ProjectReport/CommitteeAssignment didn't use the trait; both actually do. In practice `CommitteeAssignment` still produces zero logged rows in live data even though the trait is present — left uninvestigated/unchanged, since the Activity Explorer task this note is now part of explicitly forbids touching how activity gets recorded.) Only `Notification` genuinely doesn't use it. See Activity Explorer below for how this data is browsed — this section is unrelated to that; it's still governed entirely by each model's own `getActivitylogOptions()`, untouched this session.

## Roles & Permissions (Spatie)

**8 roles** (French kebab-case, seeded by `RoleSeeder`):
`president`, `vice-president`, `tresorier`, `vice-tresorier`, `secretaire-general`, `vice-secretaire-general`, `conseiller`, `abonne`.

- Every user gets `abonne` in addition to any bureau role (`RoleSeeder` assigns it to every existing user; `AuthController::register` and `MemberController::store` assign it to new users).
- Default seeded president: `president@association.com` / `password123` — **note: this seeded user has no `members` row** (`PresidentSeeder` only creates the `User` + role, not a `Member`), so anything that depends on `$user->member` (e.g. `committeeRoleFor`, self-scoped queries) degrades gracefully to "no assignment" for this specific account unless a member profile is created separately.

**Permission strings** (`PermissionSeeder`): `members.{view,create,update,delete}`, `subscriptions.{view,create,verify,delete,update}`, `dues.view`, `projects.{view,create,update,delete}`, `donations.{view,create,update,delete}`, `expenses.{view,create,update,delete}`.

**Role grants** (`RolePermissionSeeder` — rewritten to a bureau-role permission matrix; each role's grants are independent, not shared loops):

| Role | Members | Subscriptions | Dues | Projects | Donations | Expenses |
|---|---|---|---|---|---|---|
| **president** | view/create/update/delete | view/create/update/delete/verify | view | view/create/update/delete | view/create/update/delete | view/create/update/delete |
| **vice-president** | view/update | view | view | view/create/update | view/create/update | view/create/update |
| **tresorier** | view | view/create/update/verify | view | view | view/create/update/delete | view/create/update/delete |
| **vice-tresorier** | view | view/verify | view | view | view/create/update/delete | view/create/update/delete |
| **secretaire-general** | view/create/update | view | view | view | view/create/update | view/create/update |
| **vice-secretaire-general** | view/update | view | view | view | view/create/update | view/create/update |
| **conseiller** | view | view | view | view | view/create/update | view/create/update |
| **abonne** | view (self-scoped) | view (self-scoped) | view (self-scoped) | view † | — | — |

`dues.view` is granted to every role that already has `subscriptions.view` — same audience, deliberately (dues are just another view onto the same membership/payment relationship). There's no `dues.waive` permission: `DuePolicy::waive()` hardcodes the role check (président/trésorier/vice-trésorier) instead of consulting a permission string, following the exact same precedent `SubscriptionPolicy::verify()` already set — see Annual Dues below.

† `abonne`'s `projects.view` permission is still seeded/granted, but as of the Project Access Control fix above it is **no longer consulted anywhere in the codebase** for project visibility — a plain subscriber's actual project access is entirely determined by `AuthorizationHelper::canAccessProject()` (committee assignment only). The permission is effectively inert for this purpose now; left in place rather than removed from the seeders since deleting a permission is unrelated cleanup, not part of this fix, and something else could still reference it later.

Re-run `php artisan db:seed --class=RolePermissionSeeder` after changing grants — it's purely additive (`givePermissionTo` never revokes), safe to re-run against existing data.

## Authorization Model

Two mechanisms combine:

1. **Spatie roles/permissions** — global rights per role (table above).
2. **Committee membership** — per-project rights via the `member_project.committee_role` pivot (`leader`, `treasurer`, `secretary`, `member`). This is a *separate axis* from the global role: e.g. a plain `abonne` user can be a project's committee `treasurer` and gets donation/expense entry rights on that one project via `committeeRoleFor()`, independent of their global role.

**`AuthorizationHelper`**: `isPresident`; `isAssignedToProject` (president always true, else checks the member row is in `project.members`); `getAssignedProjectIds` (`[]` for president = unrestricted); `hasProjectAssignments`; `canManageProject` (president only); `canManageProjectResources` (= isAssignedToProject); **`isBureauMember`** (any of the 7 non-`abonne` roles — the line between "sees the full directory" and "sees own record only" used throughout the controllers); **`canAccessProject(User, Project): bool`** — the single centralized "can this user see this specific project at all" gate, see Project Access Control below; **`hasVerifiedSubscription(Member): bool`** (`$member->subscriptions()->where('status', 'verified')->exists()` — a strict equality check, which is exactly why the subscription-expiration command works with zero changes here: once a stale subscription's `status` flips to `expired`, this check naturally starts returning `false` for it); **`projectLockResponse(Project): ?Response`** — the single centralized project-lock guard, see below.

**`User::committeeRoleFor(Project)`**: returns the pivot `committee_role` string or `null`.

### Project Access Control (security fix)

**The bug**: any authenticated user — including a plain `abonne` subscriber with zero project assignments — could open `GET /projects/{id}` for *any* project and get its full detail (committee roster, donations/expenses totals, everything `ProjectResource` exposes), simply by changing the ID in the URL. Root cause: `ProjectPolicy::view()` OR'd in `$user->can('projects.view')`, and every `abonne` account is granted the `projects.view` permission by `RolePermissionSeeder` (originally meant to let subscribers browse a project list — see Known Gaps below on what that intent now looks like). Since `projects.view` is a *global* permission with no per-project scoping, it defeated the whole point of checking `$project` at all.

**The fix** — one centralized gate, `AuthorizationHelper::canAccessProject(User $user, Project $project): bool`:

```php
public static function canAccessProject(User $user, Project $project): bool
{
    if (self::isBureauMember($user)) {
        return true;
    }

    return self::isAssignedToProject($user, $project);
}
```

Bureau roles (president, vice-president, tresorier, vice-tresorier, secretaire-general, vice-secretaire-general, conseiller — i.e. everything except `abonne`) see every project, matching their association-wide oversight responsibilities. A plain subscriber only sees a project they are an *actual committee member of* — never merely by holding a generic permission. `ProjectPolicy::view()` now just calls this; the old `hasAnyRole(['tresorier', 'vice-tresorier'])` and `$user->can('projects.view')` branches are both gone (the treasury-role branch was redundant with `isBureauMember` anyway).

This one helper is now the single thing every project-scoped read authorization ultimately rests on. Two call sites had to change to actually use it correctly:

- **`ProjectPolicy::viewAny`/`view`** — as above.
- **`ProjectController::index()`** — the row-level scoping condition (`if (! AuthorizationHelper::isBureauMember($user)) { scope to assigned projects }`) previously read `if (! $user->hasRole('president') && ! $user->can('projects.view'))`, which — same bug — was `false` (i.e. "don't scope, show everything") for every `abonne` account. Now a subscriber's project list is genuinely limited to projects they're assigned to (empty if none).
- **`ProjectMemberController::index()`** (`GET /projects/{id}/members`, the committee roster) — was gated by a bare `abort_unless(auth()->user()->can('projects.view'), 403)`, with **no reference to `$project` at all** — any user with the permission (i.e. every user) could list *any* project's committee roster by ID regardless of assignment. Now `$this->authorize('view', $project)`, the same per-project gate as opening the project itself.

Every other project-scoped endpoint (donations, expenses, fund allocations, reports, phase requests, activity logs) was audited against the same failure pattern (a permission or "has *some* assignment" check standing in for a *per-project* check) and found already correct — none of them had a `*.view`-style permission bypass; `DonationPolicy`/`ExpensePolicy::view()` check committee-role-on-that-specific-donation's/-expense's-project, `ProjectFundAllocationPolicy`/`ProjectReportPolicy` check committee-role-on-that-project, `ProjectPhaseRequestPolicy` checks `isAssignedToProject`, `ActivityLogController` scopes its query to the caller's own assigned projects (now via `ActivityLogHelper::scopeToAssignedProjects()` — see Activity Explorer below, which also widened *who* bypasses this scope entirely). Their `?project_id=` query filters (`Donation`/`ExpenseController::index`) are additive `->where()` constraints layered on top of the row-level scope, not a replacement for it, so passing an arbitrary `project_id` the caller isn't scoped to just yields an empty result, not a leak.

Verified live end-to-end with a throwaway subscriber (no assignment) and a second one assigned to exactly one project: unassigned subscriber → `GET /projects/{id}` 403, `GET /projects` empty list, `GET /projects/{id}/members` 403; assigned-to-project-1 subscriber → project 1 succeeds, project 2 (not theirs) 403 on the project itself, its members, its phase-requests, its allocations, its reports, and its committee-history; a bureau test account (treasurer, no personal assignment) still opens any project. All test users/data cleaned up afterward.

### Project Lifecycle

`app/Helpers/ProjectLifecycle.php` is the single source of truth for the project stage state machine:

```
draft -> committee_ready -> funding_ready -> active -> completed
      \                  \                \
       \                  \                -> cancelled
        -> cancelled       -> cancelled
```

- **`committee_ready`** and **`funding_ready`** are reached **automatically**, as a side effect of another action, never through a direct status write: `ProjectMemberController::store()` flips a `draft` project to `committee_ready` right after the first committee member is attached; `ProjectFundAllocationController::store()` flips a `committee_ready` project to `funding_ready` right after the first fund allocation is recorded. Both checks are a plain `if ($project->status === ProjectLifecycle::X) { $project->update(['status' => ProjectLifecycle::Y]); }` inside the same `DB::transaction()` as the attach/create.
- **`active`** is reached manually via the new **`POST /projects/{id}/start`** action (`ProjectController::start`), gated by `ProjectPolicy::start` — only from `funding_ready`, same authority as `close` (president or the committee leader).
- **`completed`** is reached manually via the existing `POST /projects/{id}/close` — now additionally gated to only fire from `active` (`ProjectPolicy::close` denies with "Only active projects can be closed." otherwise).
- **`cancelled`** is the only status the generic `PUT /projects/{id}` endpoint may still set directly, and only from a non-terminal status — `ProjectLifecycle::canManuallySetStatus($from, $to)` is checked in `UpdateProjectRequest::withValidator()` and rejects (422) any other manual status change (e.g. trying to jump straight to `active`/`completed`, or manually forcing `committee_ready`/`funding_ready`). Cancelling from any stage dissolves the committee exactly like closing does (see Project Hard Lock below — the `dissolveCommittee()` trigger is "any transition into a locked status", not specifically `close()`).
- **Stage gates on other resources** — enforced in the relevant policy's `create()`, not just presentational on the frontend:
  - `ProjectLifecycle::requiresActiveResponse(Project): ?Response` — denies unless `status === 'active'`. Used by `DonationPolicy::create`, `ExpensePolicy::create` (donations/expenses can only ever be recorded while a project is active).
  - `ProjectLifecycle::allocationResponse(Project): ?Response` (backed by `canReceiveAllocation()`) — denies unless `status` is `committee_ready`, `funding_ready`, or `active`. Used by `ProjectFundAllocationPolicy::create` (a project needs a committee before it can receive funds; allocations keep being possible all the way through `active` too, not just the one that triggers the `funding_ready` transition).
- **`StoreProjectRequest`** no longer accepts a client-supplied `status` at all — `ProjectController::store()` always creates with `status: 'draft'` regardless of request input, the same "server decides, ignore client input" pattern used by `ExpenseController::store()` forcing `status: 'draft'`.
- Verified live end-to-end: created a project (forced to `draft` despite requesting `active` in the payload) → donation/expense rejected (403, "must be active") → direct `PUT` to `status: active` rejected (422, invalid transition) → `start` rejected (403, not funding_ready) → assigned a committee member (auto → `committee_ready`) → recorded a fund allocation (auto → `funding_ready`) → `start` succeeded (→ `active`) → donation/expense succeeded → `close` succeeded (→ `completed`) → second `close` denied (423/403, already locked). Also verified cancelling mid-lifecycle (from `committee_ready`) correctly dissolves the committee (`CommitteeAssignment.action = 'dissolved'`, reason "Project cancelled.").

### Project Hard Lock

Completed/cancelled projects become read-only. `Project::isLocked()` is the single source of truth (`status` is `completed` or `cancelled`); `AuthorizationHelper::projectLockResponse(Project $project): ?Response` is the single centralized guard every write policy calls first:

```php
public static function projectLockResponse(Project $project): ?Response
{
    if (! $project->isLocked()) {
        return null;
    }

    return Response::deny("This project is {$project->status} and is now read-only.");
}
```

Every policy method that mutates a project or something scoped to it calls this first and returns its result if non-null (Laravel policies support returning a `Response` object with a custom deny message instead of a plain `bool`): `ProjectPolicy::update/delete/assignCommittee/removeCommittee/close/resignCommittee`, `DonationPolicy::create` (and by extension `update/delete/uploadReceipt`, which delegate to `create`), `ExpensePolicy::create/submit/approve/reject/markPaid` (and `update/delete`, which delegate to `create`), `ProjectFundAllocationPolicy::create`. View-only actions (`view`, `viewAny`, report download, activity logs, project-members index) are never guarded — locked projects stay fully readable.

**Dissolution**: `ProjectController` has a private `dissolveCommittee(Project $project)` helper, called both from `close()` (after notifications, since it needs the pre-dissolution committee list) and from `update()` whenever a status transition crosses from unlocked into locked (`$wasLocked = $project->isLocked()` captured before `$project->update(...)`, then `dissolveCommittee()` fires if `! $wasLocked && $project->isLocked()` afterward — this is what makes cancelling a project via the plain `PUT /projects/{id}` endpoint dissolve it too, not just the dedicated `close()` action). It closes every still-open `CommitteeAssignment` row (`action = 'dissolved'`) and detaches the `member_project` pivot for that project — see Committee Assignment Lifecycle below.

A caveat worth knowing: `DonationPolicy`/`ExpensePolicy`'s `update`/`delete`/`uploadInvoice`/`uploadReceipt` methods originally did `return $donation->project && $this->create(...)`. PHP's `&&` operator returns a plain `bool`, not either operand — so if `create()` ever returned a `Response::deny(...)` object (truthy, since it's a non-null object), that `&&` expression would silently coerce it to `true` and bypass the deny. This was caught and fixed while adding the lock guard: those methods now do `if (! $expense->project) { return false; } return $this->create($user, $expense->project);` — returning `create()`'s result directly instead of ANDing it with a truthiness check.

Committee assignment eligibility: `ProjectMemberController::store()` (and `replace()`, for the incoming member) rejects (422) assigning a member who is **neither a bureau member nor has a verified subscription** — `AuthorizationHelper::isBureauMember($targetMember->user) || AuthorizationHelper::hasVerifiedSubscription($targetMember)` must be true. Bureau members are always eligible; a plain `abonne` is eligible only once one of their subscriptions has `status = 'verified'` (this replaced an earlier bureau-only rule), and stops being eligible again the moment that subscription auto-expires (see Subscription Expiration below) since `hasVerifiedSubscription` re-checks live. `store()` also 422s if the target is already actively assigned to that project (prevents a duplicate open `CommitteeAssignment` row for the same project/member pair). `MemberResource` exposes `is_bureau_member`/`has_verified_subscription` booleans so the frontend can filter the assignment dropdown without a separate lookup, plus **`has_portal_access`** (= `User::hasPortalAccess()`, whether a Member Portal passkey has been issued yet — see Member Portal below) so the bureau member list can show pending-vs-portal-enabled status.

### Policies (current rules)

| Policy | Rules |
|---|---|
| **MemberPolicy** | viewAny/create/delete = matching `members.*` permission; **view** = `members.view` AND (bureau member OR own record); **update** = `members.update` OR own record (self-service phone/address) |
| **SubscriptionPolicy** | viewAny/create/update/delete = matching `subscriptions.*` permission; **view** = own record OR bureau member; **verify** = role in (president, tresorier, vice-tresorier); **uploadReceipt** = verify-capable roles OR subscription owner |
| **ProjectPolicy** | **viewAny** = isBureauMember OR hasProjectAssignments (fixed — previously `hasProjectAssignments OR projects.view` permission, effectively every role including `abonne`); **view** = `AuthorizationHelper::canAccessProject()` — bureau member OR isAssignedToProject (fixed — previously also had a blanket `projects.view` permission bypass; see Project Access Control above); **create** = president or vice-president; **update** = 🔒 president/vice-president OR committee_role `leader`, **plus** any `status` change is limited by `ProjectLifecycle::canManuallySetStatus()` at the FormRequest layer (see Project Lifecycle above); **delete** = 🔒 president only; **assignCommittee** = 🔒 president or vice-president; **removeCommittee** = 🔒 president only (distinct from assignCommittee — vice-president can add/edit committee members but not remove them); **start** = 🔒 president OR committee_role `leader`, **plus** `status` must be `funding_ready`; **close** = 🔒 president OR committee_role `leader`, **plus** `status` must be `active` (new — see Project Lifecycle above); **resignCommittee(Project, Member)** = 🔒 self-service only — the acting user's own `Member` row must equal the target `$member`, and they must currently be an active committee member of that project (🔒 = also denies with the lock message once the project is completed/cancelled) |
| **DonationPolicy** | **viewAny** = hasProjectAssignments OR `donations.view` permission; **view** = on project OR role in (president, tresorier); **create** = 🔒 ⚡ committee_role in (leader, treasurer) on the donation's project; **update/delete** = 🔒 ⚡ same as create, **plus** denies once `status` has left `draft`/`pending`; **uploadReceipt** = 🔒 same as create, plus `status` must be draft/pending, **or** `rejected` with `rejection_type === 'receipt'` (Replace Receipt); **submit** = 🔒 same role check as create, plus `status` must be `draft`, **or** `rejected` with `rejection_type === 'receipt'` (Submit Again); **approve/reject** = 🔒 role in (president, tresorier, vice-tresorier) only, `status` must be `pending`, denies the donation's own recorder (`recorded_by === $user->id`), and `reject` requires both `reason` and `rejection_type` — see Donation Approval Workflow below for the full state machine |
| **ExpensePolicy** | **viewAny** = hasProjectAssignments OR `expenses.view` permission; **view** = on project OR role in (president, tresorier); **create** = 🔒 ⚡ committee_role in (leader, treasurer) on the expense's project; **update/delete** = 🔒 ⚡ same as create, **plus** denies once `status` has left `draft`/`pending` (an approved/rejected/paid expense's core fields can no longer be edited or deleted, no rejection-type exception); **uploadInvoice** = 🔒 same as create, plus `status` must be draft/pending, **or** `rejected` with `rejection_type === 'invoice'` (Replace Invoice); **submit** = 🔒 same role check as create, plus `status` must be `draft`, **or** `rejected` with `rejection_type === 'invoice'` (Submit Again); **approve/reject** = 🔒 role in (president, tresorier, vice-tresorier) only, `status` must be `pending`, denies the expense's own creator (`created_by === $user->id`), and `reject` requires both `reason` and `rejection_type`; **markPaid** = 🔒 same role trio, `status` must be `approved` — see Expense Approval Workflow below for the full state machine (🔒 = also gated by the project lock) |
| **ProjectFundAllocationPolicy** | **viewAny/view(Project)** = committee_role on that project is non-null (any committee role, not just leader/treasurer) OR role in (president, tresorier, vice-tresorier); **create(Project)** = 🔒 🎯 role in (president, tresorier, vice-tresorier) only — committee membership alone does **not** grant create, unlike donations/expenses |
| **ProjectReportPolicy** | **viewAny(Project)/view(ProjectReport)** = same rule as `ProjectFundAllocationPolicy` — committee_role on the report's project is non-null OR role in (president, tresorier, vice-tresorier). No `create` — reports are only ever generated as a side effect of `ProjectController::close()`, never through a direct endpoint |
| **ProjectPhaseRequestPolicy** | **viewAny(Project)** = isAssignedToProject OR role in (president, vice-president); **viewPending()** (no project — the association-wide queue) = role in (president, vice-president); **create(Project)** = 🔒 committee_role `leader` on that project only — never treasurer, unlike donations/expenses; **review(ProjectPhaseRequest)** = 🔒 role in (president, vice-president) only, and `status` must be `pending` (denies re-reviewing an already-decided request) — backs both the `approve` and `reject` controller actions, since the authority and precondition are identical for both |
| **ActivityPolicy** | **viewAny/view** = `AuthorizationHelper::canViewAllActivities()` (president/vice-président/trésorier — unrestricted) OR (any other bureau role AND has ≥1 project assignment, scoped per-row to that assignment via `ActivityLogHelper`) — a plain `abonne` subscriber is denied outright regardless of committee membership. Rewritten this session for the Activity Explorer — see below; the widened top tier (adding vice-président and dropping the old "president only" rule) is a deliberate permission change this feature's spec requested, not a side effect. Registered manually in `AppServiceProvider::boot()` via `Gate::policy()` since Spatie's `Activity` model lives outside `App\Models` and Laravel's naming-convention auto-discovery never finds it. |
| **DuePolicy** | **viewAny** = `dues.view` permission; **view(Due)** = own record (`due.member.user_id === user.id`) OR bureau member; **waive(Due)** = role in (president, tresorier, vice-tresorier) — a hardcoded role check (no permission string), the same financial-approval tier as `SubscriptionPolicy::verify` |
| **AssociationSettingPolicy** | No `view()` gate — `GET /api/settings` is a **public** route (login + member-portal login pages render branding before any session exists); **update** = 🔒 president only (association-wide settings) |
| **ProjectDeletionRequestPolicy** | **viewAny(Project)** = president OR committee_role `leader` on that project; **viewPending()** (association-wide review queue) = president only; **create(Project)** = 🔒 committee_role `leader` on that project only — never directly settable; **review(ProjectDeletionRequest)** = 🔒 president only, `status` must be `pending` — narrower than `ProjectPhaseRequestPolicy::review` (no vice-président), mirroring `ProjectPolicy::delete` being president-only |

(🔒 in the table above = also denied once `AuthorizationHelper::projectLockResponse()` fires, i.e. the project is completed/cancelled — see Project Hard Lock above. ⚡ = also denied unless `status === 'active'`, via `ProjectLifecycle::requiresActiveResponse()`. 🎯 = also denied unless `status` is `committee_ready`/`funding_ready`/`active`, via `ProjectLifecycle::allocationResponse()`. See Project Lifecycle above.)

Every controller action calls `$this->authorize(...)` (one exception: `ReportController`'s 6 actions use `abort_unless(...)` directly — checks `AuthorizationHelper::isBureauMember()`, not per-resource permissions, so `abonne` gets a flat 403 on every report/export regardless of what they can view elsewhere). `ProjectMemberController::index` used to be a second exception (a bare `abort_unless($user->can('projects.view'))` with no project reference at all) — fixed, see Project Access Control above; it now calls `$this->authorize('view', $project)` like everything else.

### Row-level filtering (in controllers, not policies)

- `MemberController::index`: non-bureau users (`abonne`) see only their own record.
- `SubscriptionController::index`: non-bureau users see only their own subscription history.
- `ProjectController::index`: non-bureau users (`abonne`) are scoped to projects they're a member of (fixed — see Project Access Control above; previously this branch never actually triggered for anyone, since every role including `abonne` held the `projects.view` permission it checked instead of bureau membership).
- `DonationController::index`: president and tresorier/vice-tresorier see every donation (association-wide financial oversight); everyone else is scoped to their own project assignments. Accepts an optional `?project_id=` filter.
- `ExpenseController::index`: president and tresorier/vice-tresorier bypass the assignment filter (fixed — now matches `DonationController::index` exactly instead of only bypassing for president). Accepts an optional `?project_id=` filter. **Does not** accept a `?status=` filter — the association-wide Expenses page fetches the full authorized list once and filters status/project client-side (deliberate: it already needs the whole set in memory for live per-status counts and instant filter switching, so a server round-trip per filter change would only add latency).
- `ActivityLogController::index`: non-presidents see only logs whose subject (Project/Donation/Expense/**ProjectFundAllocation**) belongs to an assigned project; paginated 20/page.

## API Endpoints

All under `/api`; everything except register/login is inside `auth:sanctum`. Full request/response shapes: `frontend/API_CONTRACT.md` (may lag behind real resource shapes — see Known Gaps).

| Method | Path | Controller | Notes |
|---|---|---|---|
| POST | `/register`, `/login` | AuthController | public; returns `{user, token}`; register assigns `abonne`. Unrelated to the Member Portal below — a self-registered `abonne` still has a real password and could use either login path once/if they get a passkey too |
| POST | `/logout` | AuthController | deletes the current Sanctum token — shared by both bureau and Member Portal sessions, since it just deletes whichever token authenticated the request |
| GET | `/me` | AuthController | raw user + roles |
| POST | `/member/login` | MemberPortalAuthController | public; body `{passkey}`; returns `{user, token}` (same shape as bureau login) on match, generic `401` otherwise; rate-limited 5/15min per IP — see Member Portal below |
| POST | `/member/forgot-passkey` | MemberPortalAuthController | public; body `{email}`; always `200` with an identical generic message; issues+emails a new passkey only if the email matches an already-portal-enabled user; rate-limited 3/15min per IP |
| GET | `/member/dashboard` | MemberPortalController | authenticated (any valid Sanctum token, from either login path); always scoped to the caller's own `Member` — profile, subscription/donation history + receipts, computed `outstanding_balance`, and (new, additive) **`dues_history`** (every due for this member, newest year first) — see Member Portal and Annual Dues below |
| CRUD | `/members` | MemberController | store creates User + Member in a transaction (no `password` field — see Member Portal below); index/show self-scoped for `abonne`; **destroy fixed this session** (real bug — see Member Deletion below) — deleting a plain subscriber's Member row now also deletes their linked User account (tokens revoked, roles detached first), not just the profile row; bureau members are excluded, only their Member row is removed |
| CRUD | `/subscriptions` | SubscriptionController | store/receipt-upload notify verifier roles via `Notification::notifyRoles` |
| POST | `/subscriptions/{id}/verify` | SubscriptionController | sets status/verified_by/verified_at; also issues a Member Portal passkey + sends the welcome email, but only the *first* time this member is ever verified (see Member Portal below) |
| POST | `/subscriptions/{id}/receipt` | SubscriptionController | multipart `receipt`, pdf/jpg/jpeg/png ≤5MB → `receipts/`; resets status to `pending` and re-notifies verifiers |
| GET | `/dues` | DueController | new — index, self-scoped for `abonne` (own dues only, same rule as `/subscriptions`); bureau roles may filter `?member_id=`/`?year=`/`?status=` — see Annual Dues below |
| GET | `/dues/{id}` | DueController | new — show; own due OR bureau member |
| POST | `/dues/{id}/waive` | DueController | new — président/trésorier/vice-trésorier only; body `{reason}` (required); sets `status: waived`, sticky (further payment activity no longer moves it) |
| CRUD | `/donations` | DonationController | index accepts `?project_id=`; store authorizes against target project, always creates with `status: 'draft'` regardless of request input |
| POST | `/donations/{id}/receipt` | DonationController | multipart `receipt` → `donation-receipts/`; also usable as "Replace Receipt" on a `rejected`+`rejection_type=receipt` donation |
| POST | `/donations/{id}/submit` | DonationController | draft → pending; also "Submit Again" for `rejected`+`rejection_type=receipt` → pending |
| POST | `/donations/{id}/approve` | DonationController | pending → approved; sets `approved_by`/`approved_at` |
| POST | `/donations/{id}/reject` | DonationController | pending → rejected; requires body `reason` (422 if missing/blank) **and** `rejection_type` (`receipt`\|`details`, 422 if missing/invalid); sets `rejected_by`/`rejected_at`/`rejection_reason`/`rejection_type` |
| CRUD | `/expenses` | ExpenseController | index accepts `?project_id=` (no `?status=`, see above); store authorizes against target project, always creates with `status: 'draft'` regardless of request input |
| POST | `/expenses/{id}/invoice` | ExpenseController | multipart `invoice` → `expense-invoices/`; also usable as "Replace Invoice" on a `rejected`+`rejection_type=invoice` expense |
| POST | `/expenses/{id}/submit` | ExpenseController | draft → pending; also "Submit Again" for `rejected`+`rejection_type=invoice` → pending |
| POST | `/expenses/{id}/approve` | ExpenseController | pending → approved; sets `approved_by`/`approved_at` |
| POST | `/expenses/{id}/reject` | ExpenseController | pending → rejected; requires body `reason` (422 if missing/blank) **and** `rejection_type` (`invoice`\|`details`, 422 if missing/invalid); sets `rejected_by`/`rejected_at`/`rejection_reason`/`rejection_type` |
| POST | `/expenses/{id}/mark-paid` | ExpenseController | approved → paid; sets `paid_by`/`paid_at` — terminal, nothing can follow it |
| POST | `/expenses/import` | ExpenseController | new — atomic batch import; body `{project_id, expenses: [{supplier_name, description, amount, payment_method, invoice_number?, expense_date, notes?}, ...]}`; rejects the whole batch (creates nothing, `422` with a `budget` breakdown) if the summed total would exceed the project's remaining budget — see Expense Budget Validation below |
| CRUD | `/projects` | ProjectController | `store()` always creates with `status: 'draft'`, `phase: 'planning'` regardless of request input; accepts optional `latitude`/`longitude` (both nullable, see Project Location below); `show()` relies on `ProjectPolicy::view` alone (no extra row-level scoping in the controller); `update()` accepts a partial body (e.g. just `{latitude, longitude}` for a location-only edit) and triggers `dissolveCommittee()` if the update transitions status into locked (e.g. cancelling via a plain `PUT`) — status changes other than → `cancelled` are rejected (422) by `UpdateProjectRequest`, see Project Lifecycle below |
| POST | `/projects/{id}/start` | ProjectController | funding_ready → active ("Start Project"); same authority as `close` |
| POST | `/projects/{id}/close` | ProjectController | active → completed only (new — see Project Lifecycle below); also sets `phase = 'completed'` (the only way to reach that phase — see Project Phase Approval Workflow below); generates a closure PDF + `ProjectReport` row, notifies committee members + bureau roles, then dissolves the committee — see Project Closure Reports and Committee Assignment Lifecycle below |
| GET/POST | `/projects/{id}/members` | ProjectMemberController | raw JSON (not a Resource); store = `attach()` (rejects 422 if the target is already actively assigned) with `role`/`committee_role`, rejects targets who are neither a bureau member nor have a verified subscription (422); **also now opens a `CommitteeAssignment` history row** (`action: 'assigned'`) |
| DELETE | `/projects/{id}/members/{memberId}` | ProjectMemberController | authorizes against `removeCommittee` (president-only); accepts an optional body `reason`; closes the open `CommitteeAssignment` row (`action: 'removed'`, `removed_by`/`removed_at`/`reason`) before detaching |
| POST | `/projects/{id}/members/{memberId}/replace` | ProjectMemberController | swaps one committee member for another in the same seat: closes the outgoing member's history row (`action: 'replaced'`), carries their `role`/`committee_role`/`responsibility` onto the incoming member, opens a fresh history row for them. Authorized the same as `assignCommittee` (president/vice-president) |
| POST | `/projects/{id}/members/{memberId}/resign` | ProjectMemberController | self-service only — a committee member resigns their own seat (`ProjectPolicy::resignCommittee`); accepts an optional body `reason`; closes the history row (`action: 'resigned'`) |
| GET | `/projects/{id}/committee-history` | ProjectMemberController | full append-only assignment/removal history for the project (never hard-deleted), eager-loaded with member/assignedBy/removedBy; authorized via the existing `ProjectPolicy::view` (no new policy method needed for a read) |
| GET | `/projects/{id}/allocations` | ProjectFundAllocationController | list a project's fund-allocation ledger, latest first |
| POST | `/projects/{id}/allocations` | ProjectFundAllocationController | the real "transfer funds to a project" endpoint — see Fund Allocations below; replaces the old pattern of `PUT /projects/{id}` directly incrementing `budget` with no record; **does not itself touch `project.budget`** (see Fund Allocations) |
| GET | `/projects/{id}/reports` | ProjectReportController | list a project's closure reports (in practice at most one, since projects aren't reopened) |
| GET | `/project-reports/{id}/download` | ProjectReportController | binary PDF download of a closure report |
| GET | `/phase-requests/pending` | ProjectPhaseRequestController | association-wide pending queue (`status = pending` across every project), for the president's review page — must be registered before any `phase-requests/{id}` route to avoid Laravel matching `pending` as a route parameter |
| GET | `/projects/{id}/phase-requests` | ProjectPhaseRequestController | full append-only phase-request history for one project (never hard-deleted), eager-loaded with requestedBy/reviewedBy/proofs |
| POST | `/projects/{id}/phase-requests` | ProjectPhaseRequestController | committee leader submits a phase change request — multipart `to_phase`, `summary`, `notes` (optional), `proofs[]` (≥1 file, pdf/jpg/jpeg/png ≤5MB each); rejects (422) if a pending request already exists for the project, or if `to_phase` isn't exactly the project's current phase's next step — see Project Phase Approval Workflow below |
| POST | `/phase-requests/{id}/approve` | ProjectPhaseRequestController | president/vice-president approves — sets the request `approved`, stamps `reviewed_by`/`reviewed_at`, and updates `project.phase` to the request's `to_phase` |
| POST | `/phase-requests/{id}/reject` | ProjectPhaseRequestController | requires body `reason` (422 if missing/blank); sets the request `rejected`, stamps `reviewed_by`/`reviewed_at`/`rejection_reason`; `project.phase` is left unchanged |
| DELETE | `/projects/{id}` | ProjectController | new behavior (route already existed) — president-only, deletes via `ProjectDeletionService` (file cleanup + audit log, see Project Deletion Workflow below); `403` on a locked (completed/cancelled) project |
| GET | `/deletion-requests/pending` | ProjectDeletionRequestController | new — association-wide pending queue, president-only, for the review page |
| GET/POST | `/projects/{id}/deletion-requests` | ProjectDeletionRequestController | new — full history for one project (GET) / committee leader submits a deletion request (POST, body `{reason}`); rejects (422) if a pending request already exists — see Project Deletion Workflow below |
| POST | `/deletion-requests/{id}/approve` | ProjectDeletionRequestController | new — president approves: marks the request approved and **deletes the project** in the same transaction |
| POST | `/deletion-requests/{id}/reject` | ProjectDeletionRequestController | new — requires body `reason` (422 if missing/blank); project is left unchanged |
| GET | `/dashboard` | DashboardController | aggregated stats/charts/notifications for the president dashboard; `spent_this_year`/`remaining_funds` are real computed values (not null); top-level `available_funds` now delegates to `FundsHelper::availableFunds()` — see the dedicated formula note below, it is **not** a simple subscriptions-minus-allocations subtraction; `projects.planned` renamed to **`projects.in_setup`** (counts draft/committee_ready/funding_ready together — the old `planned` status no longer exists, see Project Lifecycle above) and the `unfinished_projects` notification count switched from `whereIn('status', ['planned', 'active'])` to `whereNotIn('status', ['completed', 'cancelled'])` for the same reason — both would have silently returned 0/stale counts after the status enum changed if left as literal `'planned'` string matches. **`dues`** (new, additive top-level key) — `{year, expected, collected, outstanding, overdue_members, collection_rate}` for the current year, via `DuesService::summary()` — see Annual Dues below |
| GET | `/action-center` | ActionCenterController | **president-only** (403 otherwise) — one consolidated "what's waiting on me" read returning `{items, counts}`, each keyed `subscriptions`/`expenses`/`donations`/`phase_requests`/`deletion_requests` and filtered to `status = 'pending'` **server-side**; backs both the dashboard's Action Center card and the sidebar's Approvals badge — replaces the 4–8 separate unfiltered full-list fetches the frontend used to filter to "pending" client-side. See Action Center below |
| GET | `/reports/{members,subscriptions,projects,dues}` | ReportController | PDF downloads (dompdf); views live at `resources/views/reports/*.blade.php` (fixed — previously misplaced at the top-level `resources/views/`, causing a 500 for everyone). `dues` (new) additionally renders an Expected/Collected/Outstanding/Collection Rate summary row above the per-due table — see Annual Dues below |
| GET | `/reports/{members,subscriptions,projects,dues}/excel` | ReportController | XLSX via app/Exports classes |
| GET | `/activity-logs` | ActivityLogController | paginated (`current_page`/`data`/`last_page`/... envelope, unchanged shape), rows shaped by `ActivityLogResource` — filters/search/sort, see Activity Explorer below |
| GET | `/activity-logs/filters` | ActivityLogController | scoped User/Project/Entity+Status filter-dropdown options — see Activity Explorer below |
| GET | `/activity-logs/{id}` | ActivityLogController | single activity, resource includes `changes`/`related` for the frontend's detail panel |
| GET | `/notifications` | NotificationController | current user's own notifications, paginated (`?status=unread\|read`, `?category=`, `?date_from=`/`?date_to=`, `?per_page=`, default 20) — see Notification Center below |
| POST | `/notifications/read-all` | NotificationController | bulk mark-read for current user |
| POST | `/notifications/{id}/read` | NotificationController | mark one read; 403 if not the owner |
| DELETE | `/notifications/{id}` | NotificationController | new — deletes one notification; 403 if not the owner. Manual only, nothing auto-deletes read notifications |
| GET | `/settings` | AssociationSettingController | **public** (outside `auth:sanctum`) — see Association Settings below |
| PUT | `/settings` | AssociationSettingController | president-only (403 otherwise); multipart via method-spoofing for the optional `logo` upload — see Association Settings below |

Subscription expiration is **not** an HTTP endpoint — it's a scheduled console command (`app:expire-subscriptions`), see Subscription Expiration below.

## Fund Allocations (project funding ledger)

`ProjectFundAllocationController::store` is the way association funds get earmarked for a project:

1. `StoreProjectFundAllocationRequest` validates `amount` (numeric, 0.01–99,999,999.99), `allocation_date` (date), `proof_file` (**required**, pdf/jpg/jpeg/png, ≤5MB).
2. A `withValidator`/`after` hook then rejects (422, error on the `amount` field) if `amount` exceeds available funds — calls `FundsHelper::availableFunds()` (the exact same formula the dashboard uses, see below), enforced server-side, not just checked client-side before submit. This used to be a second, simpler, independent calculation (`verified subscriptions − sum of all allocations ever`, never accounting for closed-project returns) that could disagree with — and be stricter than — the dashboard's real figure; both now call the one shared helper so they can't drift apart again.
3. The controller stores the proof file to `project-fund-allocations/`, then creates the `ProjectFundAllocation` row inside a `DB::transaction()`.

**`project.budget` is never modified by an allocation.** An earlier version of this feature incremented `budget` on every transfer, which double-counted money once `ProjectResource.collected` also started including allocations — `budget` stays fixed at whatever value it was given at project creation/update (`UpdateProjectRequest` is the only thing that can change it), and `collected`/`allocated`/`remaining` are the fields that move as allocations and expenses happen. (`progress_percentage` does **not** move with them any more — it's derived purely from `phase`, see Project Progress below.) This replaced the older behavior where the frontend's "Transfer funds" control called `PUT /projects/{id}` directly with a computed `budget` value (no proof, no ledger row).

**Expense validation against remaining funds**: `StoreExpenseRequest`/`UpdateExpenseRequest` reject (422, error on `amount`) an expense whose amount would exceed the project's `remaining` (`approved donations + allocations − existing approved/paid expenses`, excluding the expense being edited on update) — same balance `ProjectResource.remaining` exposes.

## Project Closure Reports

`ProjectController::close()` runs the whole thing inside one `DB::transaction()`:

1. Sets `project.status = 'completed'`.
2. Gathers the project's donations, expenses, and fund allocations, and computes a `summary` array (`donations_total`, `expenses_total`, `allocations_total`, `returned_to_pool`, `budget`, `donation_count`, `expense_count`) — `returned_to_pool = (donations_total + allocations_total) − expenses_total`.
3. Renders `resources/views/reports/project-closure.blade.php` (dompdf) with the project, its donations/expenses/allocations, and the summary; stores the PDF to `project-reports/` and creates a `ProjectReport` row.
4. Notifies the project's committee members (`Notification::notifyUsers`) and the bureau roles (`Notification::notifyRoles`) with a `project_closed` notification: "Project {name} closed — report available."
5. Calls `dissolveCommittee()` (see Project Hard Lock above) — closes every still-open `CommitteeAssignment` row as `dissolved` and detaches the project's committee members. Done *after* the notification step, since that step needs the pre-dissolution committee list.
6. Returns `ProjectResource` as `{ data: {...project} }` **plus** a top-level `report: {...}` key (via `->additional([...])`) so the frontend gets the freshly generated report without a second request.

`returned_to_pool` is a point-in-time snapshot for the PDF/report display only — the live `available_funds` figure (below) is always recomputed from current DB state, not from this stored summary.

**Closing twice is now prevented**: `close()` is gated by `ProjectPolicy::close`, which (like every other write policy) now calls `AuthorizationHelper::projectLockResponse()` first — since `close()` itself sets `status = 'completed'`, any second attempt finds the project already locked and is denied with the lock message, instead of generating a duplicate `ProjectReport` and re-notifying everyone.

**`ProjectReportPolicy` fix (this session)**: step 5's `dissolveCommittee()` detaches the `member_project` pivot in the *same* transaction that step 3 creates the `ProjectReport` — so `ProjectReportPolicy::viewAny`/`view`, which only checked `$user->committeeRoleFor($project)` (the *live* pivot), would 403 the very committee members the report is for, the instant it's generated. Now reachable from every committee-assigned user via the unified `CommitteeProjectPage.jsx` (see "App Unification" below), this needed fixing: both methods now also accept `CommitteeAssignment` history (`where('project_id', ...)->where('member_id', ...)->exists()`) — the permanent audit trail of every seat a member ever held on the project, not just the currently-active one. Verified live: closed a test project (confirmed the committee was actually empty afterward — `project->members()->count() === 0`, `committeeRoleFor()` returns `null`), then confirmed the former committee member's token still got `200` from both `GET /projects/{id}/reports` and `GET /project-reports/{id}/download`.

## Committee Assignment Lifecycle & History

Full history/lifecycle for project committee membership, on top of the pre-existing `member_project` pivot (which still is what everything else — `committeeRoleFor`, `isAssignedToProject`, `ProjectResource.members`, donation/expense authorization — reads for "who is currently on this committee"). The pivot is kept exactly as-is deliberately, rather than replaced, to avoid rippling the change across every other place that already reads it; `committee_assignments` is a parallel, synchronized ledger written alongside every pivot attach/detach.

- **Never hard-deleted**: a row is written once (`action: 'assigned'`) and only ever closed (`removed_by`/`removed_at`/`reason`/`action` updated), never deleted. `action` is one of `assigned` (still open), `replaced`, `resigned`, `removed`, `dissolved`.
- **Four ways a row closes**: `ProjectMemberController::destroy()` (`removed`, president-only, optional reason), `resign()` (`resigned`, self-service only), `replace()` (`replaced`, swaps in a new member in the same seat, preserving `committee_role`/`responsibility`), and `ProjectController`'s `dissolveCommittee()` (`dissolved`, fired when a project completes/cancels — see Project Hard Lock above).
- **Backfilled**: the migration that created `committee_assignments` also inserted one opening `assigned` row per pre-existing `member_project` row (using its `assigned_at`/`assigned_by` if present, else `created_at`/`null`), so committees that existed before this feature isn't invisible in the new history endpoint.
- **`GET /projects/{id}/committee-history`** returns the raw ledger for a project, newest-first by `assigned_at`, eager-loaded with `member.user`/`assignedBy`/`removedBy`. Since `assignedBy()`/`removedBy()` are eager-loaded relations with the *same name* as the raw `assigned_by`/`removed_by` FK columns, Laravel's default `toArray()` serialization has the relation **overwrite** the raw integer in the JSON output — so `assigned_by`/`removed_by` in the response are full `{id, name, email, ...}` user objects, not IDs. (Verified live — this is relied on by the frontend timeline, not accidental.)

## Expense Approval Workflow

`draft → pending → approved/rejected → paid`. `status` enum lives directly on `expenses` (no separate history table, unlike committees).

- **`store()`** always creates with `status: 'draft'` regardless of what's sent — the field isn't even in `StoreExpenseRequest`'s validated list, so it can't be set at creation via the API.
- **`submit`**: draft → pending. Same authority as creating (committee leader/treasurer for that project). Also allows **"Submit Again"**: rejected → pending, but only when `rejection_type === 'invoice'` (see Rejection Types below) — a details-issue rejection can never be resubmitted this way.
- **`approve`/`reject`**: pending → approved/rejected. Only president/tresorier/vice-tresorier (`ExpensePolicy::APPROVAL_ROLES`) — a separate authority axis from the committee leader/treasurer roles that create/submit/edit. Both deny the expense's own creator (`created_by === $user->id`) — a creator can never approve or reject their own expense. `reject` requires a `reason` (`required|string|max:500`) **and** a `rejection_type` (`required|in:invoice,details`) — 422 if either is missing.
- **`markPaid`**: approved → paid. Same role trio as approve/reject, no creator restriction. Terminal — nothing can follow it.
- **No transition ever moves status backwards**, except the one deliberate exception above (rejected+invoice → pending via Submit Again). Every other invalid transition is prevented by each action's own precondition (`approve`/`reject` require `pending`, `markPaid` requires `approved`).
- **`update`/`delete`** still deny once `status` has left `draft`/`pending` — an approved/paid expense's supplier/amount/etc. can no longer be silently edited or the row deleted after a decision has been made. A rejected expense is also always blocked here regardless of rejection type — its only way back to editable is a details-rejection's brand-new expense, or an invoice-rejection's Submit Again (which doesn't touch these fields).
- Verified live end-to-end against the running app: draft → submit → creator-tries-to-approve-own (403) → president approves → creator-tries-to-edit-now-approved (403) → president marks paid → re-approve/re-mark-paid/delete on the paid expense all 403 → separate expense rejected without a reason (422) then with one (recorded correctly) → rejected expense can't be edited.

### Rejection Types (`rejection_type`)

Added by `2026_07_29_010227_add_rejection_type_to_expenses_table` — nullable `enum('invoice', 'details')`, only meaningful once `status = rejected`, set by the same `reject` call that sets `rejection_reason`. Pre-existing rejected rows (from before this column existed) were backfilled to `'details'` — the safer default, since it preserves their already-fully-locked behavior instead of silently making them resubmittable.

- **`invoice`** (invoice issue) — the leader can **Replace Invoice** (`uploadInvoice` — `ExpensePolicy::canEditInvoice()` now also allows this on a `rejected`+`invoice` expense, not just draft/pending) and then **Submit Again** (`submit`, rejected → pending, see above). Full history is preserved — the same expense row transitions back to pending rather than a new one being created.
- **`details`** (expense details issue) — the expense is permanently read-only. `update`/`delete`/`uploadInvoice` all stay denied forever (status never leaves `rejected`). The leader must create a brand-new expense (a plain `POST /expenses`, status starts at `draft` as always); the old rejected expense remains in history untouched.
- `ExpenseResource` exposes `rejection_type` alongside `rejection_reason` so the frontend can branch the UI between the two flows.

### Financial calculations use approved/paid expenses only

`Expense::FINANCIALLY_COUNTED_STATUSES = ['approved', 'paid']` and the `Expense::scopeFinanciallyCounted()` query scope are the single source of truth for "does this expense count against project/association funds." Draft, pending, and rejected expenses **never** reduce remaining/available funds — only approved and paid do (paid is included because it's the post-approval settlement of the same already-approved spend, not a separate state; excluding it would make marking an expense paid look like money came back).

Every previously-existing `sum('amount')` over an unfiltered expense list was found and fixed to go through this scope:
- `ProjectResource.expenses` (and therefore `.remaining`) — was summing all expenses regardless of status.
- `FundsHelper::availableFunds()` — the closed-project expense figure used to compute `donationsUsed`/`allocationUsed` (and therefore `available_funds` overall).
- `StoreExpenseRequest`/`UpdateExpenseRequest`'s remaining-funds validation (an expense's amount can no longer be inflated-looking-invalid by a pile of rejected/pending expenses, nor can a pending expense's amount hide behind funds a rejected expense never actually spent).
- `DashboardController`'s `spent_this_year`/`remaining_funds`.
- `ProjectController::close()`'s closure-report `expenses_total`/`returned_to_pool` — the full expense list (all statuses) is still passed to the PDF for history, now with a Status column per row, but only approved/paid feed the total figure.

Verified live via tinker: created one expense per status (draft/pending/approved/rejected/paid, $100/$200/$300/$400/$500) on the same project — `financiallyCounted()->sum('amount')` and `ProjectResource.expenses` both correctly returned `800` (300 approved + 500 paid), not `1500`.

## Expense Budget Validation (`app/Helpers/ExpenseBudgetValidator.php`)

A second, independent check from the "remaining funds" validation above (`StoreExpenseRequest`/`UpdateExpenseRequest`'s pre-existing `donations + allocations − financially-counted-expenses` check, untouched) — both must pass. Remaining-funds asks "has this money actually been collected"; this one asks "does this spending fit inside the budget *plan*", regardless of whether the money backing it has arrived yet:

```php
public const ALLOCATING_STATUSES = ['draft', 'pending', 'approved', 'paid'];

public static function alreadyAllocated(Project $project, ?int $excludeExpenseId = null): float
public static function breakdown(Project $project, float $newTotal, ?int $excludeExpenseId = null): array
```

- **`ALLOCATING_STATUSES` deliberately includes `paid`** even though the task's own spec literally said "Draft + Pending + Approved" — `paid` is already-approved money that has also gone out the door, so excluding it would let further spending ignore money that's already spent. Rejected expenses never count, matching the spec exactly.
- **`breakdown()`** returns `project_budget`, `already_allocated`, `remaining_budget`, `new_total`, `exceeded_by`, `within_budget` — the exact fields the spec's error example asks for (Project Budget / Already Allocated / Remaining Budget / Imported Total / Exceeded By).
- **Single expense create/update** (`StoreExpenseRequest`/`UpdateExpenseRequest`'s `withValidator`): adds a second `amount` error via `ExpenseBudgetValidator::errorMessage($breakdown)` when the check fails (update excludes the expense's own prior amount from `already_allocated` via `$excludeExpenseId`, same pattern the remaining-funds check already used). Also writes `activity()->log('Manual expense rejected because budget exceeded.')` with the full breakdown as `properties` — a side effect inside the FormRequest's `after()` validator, the only place this rejection is observable since the controller body never runs when validation fails.
- **Batch import** (`POST /expenses/import`, new — `ExpenseController::import()`, `StoreExpenseImportRequest` for per-row structural validation): sums every row's `amount` into one `$importTotal`, runs `ExpenseBudgetValidator::breakdown()` **once** against that total, and only if it's within budget does it create anything — inside one `DB::transaction()`, so it's genuinely all-or-nothing (no row is ever persisted, draft or otherwise, if the batch would exceed the budget). On rejection it returns `422` with `{ message: "Import exceeds remaining project budget. No expenses were imported.", budget: {...breakdown} }` and logs `activity()->log('Expense import rejected because budget exceeded.')`. Replaces the frontend's previous per-row `POST /expenses` loop (`CommitteeProjectPage.jsx`'s `handleSaveAllImports`), which had no atomicity — a failure partway through left earlier rows already created.
- Verified live end-to-end against the spec's own worked example (Budget 100,000 / Draft 20,000 / Pending 10,000 / Approved 50,000 / Remaining 20,000 / Imported 35,000): import correctly rejected with `exceeded_by: 15000` and zero rows created; an import of exactly the remaining 20,000 succeeded; a manual create/edit that would tip the now-fully-allocated budget over was rejected with the same breakdown; an edit that *reduced* an expense's amount (freeing up budget) correctly succeeded.

## Donation Approval Workflow

`draft → pending → approved/rejected`. Deliberately mirrors the Expense Approval Workflow above field-for-field and action-for-action — donations have no "paid" equivalent state, so this is otherwise the identical state machine on `donations.status` (added by `2026_07_29_134133_add_approval_workflow_to_donations_table`, same columns as the expense migration: `approved_by`/`approved_at`/`rejected_by`/`rejected_at`/`rejection_reason`/`rejection_type`).

- **`store()`** always creates with `status: 'draft'` regardless of what's sent — the field isn't in `StoreDonationRequest`'s validated list, so it can't be set at creation via the API.
- **`submit`**: draft → pending. Same authority as creating (committee leader/treasurer for that project). Also allows **"Submit Again"**: rejected → pending, but only when `rejection_type === 'receipt'` — a details-issue rejection can never be resubmitted this way.
- **`approve`/`reject`**: pending → approved/rejected. Only president/tresorier/vice-tresorier (`DonationPolicy::APPROVAL_ROLES`) — a separate authority axis from the committee leader/treasurer roles that create/submit/edit. Both deny the donation's own recorder (`recorded_by === $user->id`) — a recorder can never approve or reject their own donation. `reject` requires a `reason` (`required|string|max:500`) **and** a `rejection_type` (`required|in:receipt,details`) — 422 if either is missing.
- **No transition ever moves status backwards**, except the one deliberate exception above (rejected+receipt → pending via Submit Again).
- **`update`/`delete`** deny once `status` has left `draft`/`pending` — an approved donation's donor/amount/etc. can no longer be silently edited or the row deleted after a decision has been made. A rejected donation is also always blocked here regardless of rejection type — its only way back to editable is a details-rejection's brand-new donation, or a receipt-rejection's Submit Again (which doesn't touch these fields).
- Verified live via tinker (rolled back): submit on rejected+receipt → true; submit on rejected+details → denied; uploadReceipt on rejected+receipt → true; uploadReceipt on rejected+details → denied ("This donation's receipt can no longer be replaced."); update on rejected+receipt → still denied ("already been decided"); approve by the recorder → denied ("You cannot approve a donation you recorded."); approve/reject by president on someone else's pending donation → true; approve by a non-approval-role user → denied.

### Rejection Types (`rejection_type`)

Nullable `enum('receipt', 'details')`, only meaningful once `status = rejected`, set by the same `reject` call that sets `rejection_reason`. Pre-existing rejected rows are backfilled to `'details'` (none existed at migration time, but matches the same safer-default reasoning as the expense migration).

- **`receipt`** (receipt issue — unreadable/wrong/missing receipt) — the leader can **Replace Receipt** (`uploadReceipt` — `DonationPolicy::canEditReceipt()` now also allows this on a `rejected`+`receipt` donation, not just draft/pending) and then **Submit Again** (`submit`, rejected → pending). Full history is preserved — the same donation row transitions back to pending rather than a new one being created.
- **`details`** (donation details issue — wrong amount/donor/project, duplicate) — the donation is permanently read-only. `update`/`delete`/`uploadReceipt` all stay denied forever (status never leaves `rejected`). The leader must create a brand-new donation (a plain `POST /donations`, status starts at `draft` as always); the old rejected donation remains in history untouched.
- `DonationResource` exposes `rejection_type` alongside `rejection_reason` so the frontend can branch the UI between the two flows — identical shape to `ExpenseResource`.

### Financial calculations use approved donations only

`Donation::FINANCIALLY_COUNTED_STATUSES = ['approved']` and the `Donation::scopeFinanciallyCounted()` query scope are the single source of truth for "does this donation count toward collected/available funds" — mirrors `Expense::FINANCIALLY_COUNTED_STATUSES` exactly, just a one-status set since donations have no paid/settlement stage. Draft, pending, and rejected donations **never** increase collected/remaining/available funds.

Every previously-existing `sum('amount')` over an unfiltered donation list was found and fixed to go through this scope:
- `ProjectResource.collected` (and therefore `.remaining`) — was summing all donations regardless of status.
- `FundsHelper::availableFunds()` — the closed-project donation figure used to compute `donationsUsed`/`closedProjectsReturnedDonations` (and therefore `available_funds` overall).
- `StoreExpenseRequest`/`UpdateExpenseRequest`'s remaining-funds validation — a project's "collected" figure used to cap a new/edited expense now only counts approved donations, same as it already only counted approved/paid expenses.
- `DashboardController`'s `donations.total`/`.revenue`, `overview.collected_this_year`, `buildRevenueStat()`'s current/previous-month donation figures, `buildDonationsByMonthChart()`, `buildRevenueVsProjectBudgetsChart()`'s donation revenue, and `recentDonations()`.
- `ProjectController::close()`'s closure-report `donations_total`/`returned_to_pool` — the full donation list (all statuses) is still passed to the PDF for history, now with a Status column per row, but only approved donations feed the total figure.

Verified live via tinker: created one donation per status (draft/pending/approved/rejected, $100/$200/$300/$400) on the same project — `financiallyCounted()->sum('amount')` and `ProjectResource.collected` both correctly reflected only the $300 approved donation, not $1000.

### Donation access scoping (this session — subscriber privacy fix)

A "My Donations" personal donor-history view was added for subscribers in a prior session, then found to be wrong: it exposed donor names and individual donation records, and subscribers were never supposed to have a personal donations page at all — donation records belong to whoever manages a project's finances, not to whoever happens to be a committee member. Auditing the app for the same problem elsewhere found it also affecting `DonationPolicy`/`DonationController`'s existing committee scoping, predating that session: **any** committee role (including a plain `'member'`/`'secretary'` pivot, not just `'leader'`/`'treasurer'`) was previously enough to list a project's full itemized donation records via `hasProjectAssignments()`.

- **`AuthorizationHelper::hasFinancialCommitteeRole(User $user)`** (new) — true for president, or a member with a `leader`/`treasurer` committee role on at least one project. Deliberately narrower than the pre-existing `hasProjectAssignments()` (any committee role at all), which is still used elsewhere (e.g. `ProjectPolicy`, `hasProjectAssignments`-gated nav) where "is on the committee at all" is the correct question — donations specifically needed a narrower one.
- **`DonationPolicy::viewAny()`** now checks `hasFinancialCommitteeRole($user) || $user->can('donations.view')` instead of `hasProjectAssignments($user) || $user->can('donations.view')`. `DonationPolicy::view()` narrowed the same way: `in_array($user->committeeRoleFor($project), ['leader', 'treasurer'])` instead of `committeeRoleFor($project) !== null`.
- **`DonationController::index()`**'s non-financial-oversight scoping branch now filters `$member->projects()->wherePivotIn('committee_role', ['leader', 'treasurer'])` instead of every assigned project.
- **A plain committee member (or unassigned subscriber) now gets `403` from `GET /donations` (with or without `project_id`) and `GET /donations/{id}`** — they can still see a project's *aggregate* `collected` figure via `GET /projects/{id}` (unaffected, already backend-computed and never itemized) — matching the requirement that subscribers only ever see financial *summaries* for their own projects, never individual donor records.
- **Bureau roles are unaffected**: every bureau role already had (and keeps) `donations.view`, matching the pre-existing `RolePermissionSeeder` grants — nothing about president/trésorier/vice-trésorier's full access, or any other bureau role's own committee-scoped access, changed.
- Verified live: a committee **leader** on a test project still got `200` + the donor name from both `GET /donations` and `GET /donations?project_id={id}`; a plain committee **member** on the same project got `403` from both, while `GET /projects/{id}` still correctly returned the full `collected`/`budget`/`expenses`/`remaining` aggregate for them. All test data cleaned up afterward.

## Project Phase Approval Workflow

Replaces the old cosmetic "execution %" (`ProjectResource.progress`, a budget-utilization ratio that was being shown as if it meant execution progress — since removed entirely, see Project Progress below) with a real, verified 5-stage execution phase tracked on `project.phase` — a separate column/axis from `project.status` (the administrative Project Lifecycle above). `app/Helpers/ProjectPhaseWorkflow.php` is the single source of truth:

```
planning -> preparation -> in_progress -> finishing -> completed
```

- **The committee leader can never set `phase` directly** — there is no `phase` field in `StoreProjectRequest`/`UpdateProjectRequest` at all, so it can't be mass-assigned through the generic project endpoints regardless of what a client sends. The only way to move `phase` forward is a `ProjectPhaseRequest` that a president/vice-president approves.
- **`store()`** (`ProjectPhaseRequestController::store`, gated by `ProjectPhaseRequestPolicy::create` — committee leader only): validated by `StoreProjectPhaseRequestRequest`, which combines format rules (`to_phase` must be one of `ProjectPhaseWorkflow::REQUESTABLE` = `preparation`/`in_progress`/`finishing`; `summary` required; `notes` optional; `proofs` required array, ≥1 file, each `mimes:pdf,jpg,jpeg,png|max:5120`) with two business checks in a `withValidator`/`after` hook: (1) the project must not already have a `pending` request (`Project::pendingPhaseRequest()`) — **one pending request per project, max**; (2) `to_phase` must be exactly `ProjectPhaseWorkflow::nextPhase($project->phase)` — no skipping ahead, no moving backward, and `completed` is never a valid target here at all (excluded from `REQUESTABLE`). The controller snapshots `from_phase` from the project's current phase, stores the request as `status: 'pending'`, and stores each uploaded proof as its own `ProjectPhaseRequestProof` row (`project-phase-proofs/` on the public disk) in the same `DB::transaction()`.
- **`approve`** (`ProjectPhaseRequestController::approve`, gated by `ProjectPhaseRequestPolicy::review` — president/vice-president, request must still be `pending`): sets the request `status: 'approved'`, stamps `reviewed_by`/`reviewed_at`, and — the only place `project.phase` is ever written from this workflow — updates `project.phase` to the request's `to_phase`.
- **`reject`**: same authority/precondition as `approve`; requires body `reason` (`required|string|max:500`, 422 if missing/blank); sets `status: 'rejected'`, `reviewed_by`/`reviewed_at`/`rejection_reason`. **`project.phase` is left completely unchanged** on rejection.
- **`completed` is reached only through the existing project-close flow**, never through this workflow: `ProjectController::close()` now sets `phase: 'completed'` alongside `status: 'completed'` in the same `update()` call — a one-line addition, the rest of `close()`'s logic (report generation, notifications, committee dissolution) is untouched.
- **Never hard-deleted**: every request — pending, approved, or rejected — stays in `project_phase_requests` forever; `GET /projects/{id}/phase-requests` returns the full ledger newest-first, eager-loaded with `requestedBy`/`reviewedBy`/`proofs`.
- **`ProjectResource`** now exposes `phase` and `pending_phase_request_id` (`Project::pendingPhaseRequest()?->id`, `null` if none) so the frontend can show a "pending" badge without a separate request — same N+1-per-row caveat as the existing `collected`/`allocated`/`expenses` sums (see Known Gaps).
- A pending request on a project that gets closed/cancelled directly (bypassing this workflow) becomes permanently unreviewable rather than being auto-resolved: `ProjectPhaseRequestPolicy::review` denies once the project is locked (same `AuthorizationHelper::projectLockResponse()` guard as everywhere else), so a leftover `pending` row just sits there — a known edge case, not handled specially (see Known Gaps).
- Verified live end-to-end: leader requests `in_progress` from `planning` (skip-ahead) → 422 "phases advance one step at a time"; leader requests `preparation` (valid next step) with 2 proof files → 201, both proofs stored and downloadable; leader submits a second request while one is pending → 422 "already has a pending phase request"; leader tries to approve their own request → 403; president approves → `project.phase` becomes `preparation`, `pending_phase_request_id` clears; re-approving the same (now-decided) request → 403 "Only pending phase requests can be reviewed"; a second request (`in_progress`) rejected without a reason → 422, then with one → recorded, `project.phase` unchanged; full history correctly lists both requests newest-first; closing the project sets `phase: 'completed'`; a further phase request against the now-locked project → rejected (422, via the sequential-order check firing before the lock check ever runs — see Known Gaps).

## Project Deletion Workflow

President deletes directly; a committee leader can only *request* deletion, which the president approves or rejects. Mirrors the Project Phase Approval Workflow's request/review shape as closely as the two features' actual differences allow.

- **`ProjectPolicy::delete`** (unchanged, already existed) — president-only, gated by `AuthorizationHelper::projectLockResponse()` like every other project mutation, so a `completed`/`cancelled` project can never be deleted by anyone, matching the spec's "Completed projects can't be deleted." The task's "Draft, Pending, Active" wording maps onto this app's actual lifecycle as "not terminal" — `draft`/`committee_ready`/`funding_ready`/`active` are all deletable, `completed`/`cancelled` are not (cancelled wasn't explicitly called out either way by the task; treating it as locked keeps it consistent with every other write policy in the app, which already treats cancelled identically to completed everywhere else).
- **`ProjectController::destroy()`** now delegates to `app(ProjectDeletionService::class)->delete($project)` instead of a bare `$project->delete()`.
- **`ProjectDeletionRequest`** (new model/table, `project_deletion_requests`) — `project_id` (**nullable, `nullOnDelete()`** — the one deliberate deviation from `project_phase_requests`' `cascadeOnDelete()`: a deletion request's whole reason for existing is to record who requested/approved a project's deletion, so unlike a phase request it must survive the very deletion it caused; `project_name` is a snapshot column so the history stays readable once `project_id` goes null), `reason`, `status` (`pending`/`approved`/`rejected`), `requested_by`/`requested_at`, `reviewed_by`/`reviewed_at`, `rejection_reason`.
- **`ProjectDeletionRequestPolicy`** — `create()`: committee leader only (`committeeRoleFor($project) === 'leader'`), lock-gated. `review()`: **president only** (`REVIEW_ROLES = ['president']`) — deliberately narrower than phase requests' president+vice-president, matching `ProjectPolicy::delete`'s own president-only authority exactly (no point granting review rights to a role that can't delete directly either).
- **`StoreProjectDeletionRequestRequest`**: `reason` required; `withValidator` denies a second request while one is already `pending` (`Project::pendingDeletionRequest()`), same one-pending-request-per-project rule as phase requests.
- **Routes** (`GET deletion-requests/pending`, `GET/POST projects/{project}/deletion-requests`, `POST deletion-requests/{id}/approve|reject`) and **`ProjectDeletionRequestController`** mirror `ProjectPhaseRequestController` method-for-method (`index`/`pending`/`store`/`approve`/`reject`).
- **`approve()`** does the status update, the actual deletion (via `ProjectDeletionService`, passing the request's `reason` through), and both activity-log calls inside one `DB::transaction()`. If the project was somehow already gone (shouldn't happen — `review()`'s lock check runs first) it returns `422` instead of deleting nothing silently.
- **`ProjectDeletionService`** (`app/Services/ProjectDeletionService.php`) — the one place that actually deletes a project, used by both the president's direct delete and an approved request, so "no orphan records" can't drift between the two paths:
  - Every table with a `project_id` foreign key is already `onDelete('cascade')` at the DB level (`member_project`, `donations`, `expenses`, `project_fund_allocations`, `project_reports`, `committee_assignments`, `project_phase_requests` → `project_phase_request_proofs`) — confirmed by reading every migration — so `$project->delete()` alone leaves no orphaned **rows**. What cascade does *not* clean up is the **files** those rows pointed to on the public disk (`expenses.invoice_path`, `donations.receipt_file`, `project_fund_allocations.proof_file`, `project_reports.file_path`, `project_phase_request_proofs.file_path`) — cascade deletes bypass Eloquent model events entirely, so those would otherwise leak. `deleteRelatedFiles()` walks every one of those paths and deletes them from `Storage::disk('public')` *before* the project row (and therefore the path) disappears, all inside the same transaction.
  - Writes `activity()->log('Project deleted.')` with `project_name`/`budget`/`reason` as `properties`, right before the actual `$project->delete()` call (so `performedOn($project)` still has a valid subject to point at).
- **Notifications**: president gets `project_deletion_pending` on request (deep-links to `/deletion-requests/pending`, the review queue — same "review queue is more useful than the project page" precedent as `phase_request_pending`). The requesting committee leader gets `project_deletion_approved`/`project_deletion_rejected`. A rejected request's notification deep-links back to the still-alive project; an approved request's project is gone by the time the notification is read, so `NotificationResource::resolveLink()` returns `null` for it (checked via the subject's own `project_id`, which is null for *every* request that ever referenced that project once it's deleted — verified live: a much-earlier *rejected* request's link also degrades to `null` once the project is later deleted via a different, later-approved request, since they share the same now-nulled FK).
- **Audit trail**: 4 of the 6 spec-required log lines live here — `'Project deletion requested.'`, `'Project deletion approved.'`, `'Project deletion rejected.'`, `'Project deleted.'` — each a manual `activity()->log(...)` call (the `PasskeyService` precedent for a manual, non-dirty-attribute log entry), **not** surfaced through the Activity Explorer's `ActivityLogHelper::describe()` synthesized-message layer (that layer computes its own text from `subject_type`/`event`/the subject's live status and completely ignores the raw `activity_log.description` column for any `ENTITIES`-mapped subject type, and `ProjectDeletionRequest` isn't in that map) — teaching the Explorer about this new entity/event set was left out of scope (see Activity Explorer above: "no unrelated refactoring" applies both ways). The literal spec-worded text is still exactly what lands in `activity_log.description`, visible via direct query or a future Explorer enhancement.
- **Frontend**: `CommitteeProjectPage.jsx` gets a "Delete Project" button (president, `window.confirm('Delete Project?\n\nThis action cannot be undone.')`) and a "Request Deletion" button (committee leader, opens a new generic `ReasonModal` — distinct from `RejectReasonModal`, which always shows an irrelevant rejection-type radio group). `ProjectsPage.jsx`'s portfolio list also gets a per-row delete icon for the president. A new `PendingProjectDeletionRequestsPage.jsx` (route `/deletion-requests/pending`, nav entry under Projects, president-only) mirrors `PendingPhaseRequestsPage.jsx`, reusing `RejectReasonModal` without `rejectionTypes` for the reject reason — same known cosmetic quirk (shows the generic invoice/details labels) already present on the page it mirrors, not newly introduced.
- Verified live end-to-end against the running app: committee leader blocked from direct delete (403) → requests deletion → second request while pending rejected (422) → president rejects with a reason → project unchanged, requester notified with a working deep link → leader requests again → president approves → project, its expense, and its `member_project` row are all gone (cascade confirmed via direct query) → the expense's invoice file confirmed deleted from the public disk → both the earlier-rejected and the approved request's notification links now correctly show `null` → all 4 audit log lines present with the exact literal spec wording.

## Project Progress

`project.progress` (a `collected/budget %`, capped at 100 — a purely financial ratio that was being displayed as if it meant execution progress) has been **removed entirely** — progress is never stored and never independently calculated. It is now always derived from `phase` alone, via one reusable mapping in `ProjectPhaseWorkflow`:

```php
public const PROGRESS_BY_PHASE = [
    self::PLANNING => 0,
    self::PREPARATION => 25,
    self::IN_PROGRESS => 50,
    self::FINISHING => 75,
    self::COMPLETED => 100,
];

public static function progressPercentage(string $phase): int
{
    return self::PROGRESS_BY_PHASE[$phase] ?? 0;
}
```

- **`ProjectResource`** is the only place this is called (`ProjectPhaseWorkflow::progressPercentage($this->phase)`), exposed as **`progress_percentage`** — this single resource backs every endpoint that returns project data (`GET /projects`, `GET /projects/{id}`, and by extension the president dashboard's recent-projects/committee views), so fixing it here fixes it everywhere at once. There is no other code path that computes or stores a project completion percentage — `ExpenseController`, `DonationController`, `ProjectFundAllocationController`, the Excel/PDF report exports, and `DashboardController`'s own JSON never referenced `progress` and still don't.
- **Never settable from the client**: `progress`/`progress_percentage` was never a fillable column and never appeared in `StoreProjectRequest`/`UpdateProjectRequest`'s validated fields — there is no `progress` column on the `projects` table at all (it was always computed at read time, just from the wrong inputs before). Nothing changed about "storage" because there was never any to remove; what changed is the formula and its inputs (`phase` instead of `collected`/`budget`).
- **Automatic on phase change, no extra code**: since `progress_percentage` is derived fresh from `phase` on every serialization, `ProjectPhaseRequestController::approve()` updating `project.phase` is the only thing that needs to happen — the next time that project is serialized by `ProjectResource` (e.g. the committee leader's next page load), `progress_percentage` reflects the new phase automatically. No separate "recalculate progress" step exists or is needed.
- Verified live: a project's `progress_percentage` read `0` at `planning`, `25` right after a phase-approval into `preparation` — no other field or endpoint touched to make that change visible.

## Project Location

Optional TomTom-map-picked coordinates on a project, added via the `2026_07_25_000000_add_location_to_projects_table` migration: `latitude` (`decimal(10,8)`, nullable), `longitude` (`decimal(11,8)`, nullable) — both nullable so every project created before this feature keeps working unchanged (`null`/`null`).

- **Validation**: `StoreProjectRequest`/`UpdateProjectRequest` both add `latitude => nullable|numeric|between:-90,90` and `longitude => nullable|numeric|between:-180,180`. Sending `null` for both (no location picked) is valid and simply stores `null`; sending nothing at all on create also stores `null` (column default). `UpdateProjectRequest`'s fields are already `sometimes`, so a partial `PUT` with just `{latitude, longitude}` — as the frontend's location-only edit flow does — works without touching any other project field.
- **`Project` model**: `latitude`/`longitude` added to `$fillable` and cast `decimal:8` (matches the column scale; Eloquent's decimal cast returns a numeric string, which `ProjectResource` then explicitly casts to `float` or `null` for the JSON response — a JS client should never receive `"33.5731"` as a string when it means a number).
- **`ProjectResource`** exposes both fields on every project (list and detail) — `null` when unset, otherwise a plain float. No other resource, export, or report currently surfaces location; none needed changing.
- **Backend is the source of truth**: coordinates are only ever persisted through the normal `store()`/`update()` flow (already inside each action's own validation + policy checks) — there is no separate "save location" endpoint. The frontend's location-only edit calls the exact same `PUT /projects/{id}` as any other partial project update.
- Verified live: created a project with valid coordinates (`33.5731, -7.5898`) → round-tripped exactly as floats; created one with explicit `latitude: null, longitude: null` → stored as `null`/`null`; out-of-range `latitude: 95` → 422 with the expected message; `PUT` with just `{latitude: null, longitude: null}` on an existing project correctly cleared a previously-set location; omitting both fields entirely on create defaults cleanly to `null`/`null`.
- **Found and fixed a pre-existing, unrelated bug while testing this**: `ProjectController::store()` never explicitly set `phase` on create (it relied on the `phase` column's DB default of `'planning'`), so the `Project` model Eloquent returned from `create()` had `phase` completely unset in memory (not even `null`-but-present) even though the DB row correctly defaulted to `'planning'`. Any code reading `$project->phase` from that same in-memory instance (like `ProjectResource::progress_percentage`, added in the phase-workflow task) would see `null` and crash — `ProjectPhaseWorkflow::progressPercentage(null)` has no string-typed fallback. Fixed by explicitly setting `'phase' => 'planning'` in the `create()` call, mirroring how `'status' => 'draft'` was already forced. This had no visible symptom before (every page that displays a project re-fetches it via `GET /projects` after creating one, which always returns the correct DB value), but the raw `POST /projects` response itself was returning broken data — now fixed.

## Passkey Authentication for Subscribers

A second authentication path for plain subscribers (`abonne`) alongside the existing bureau email/password login — **not** a second user system, and (as of this session) **not** a second application either. Both paths authenticate against the exact same `User` model and issue the exact same kind of Sanctum personal access token; only *how* a request proves who it is differs. **The former "Member Portal" (a separate `/member/dashboard` mini-app with its own routes/pages) has been removed** — see "App Unification" below. What remains from that feature: the passkey issuance/verification workflow, `MemberPortalAuthController`'s two public endpoints, and `MemberPortalController::dashboard()` as a data source (not a page) reused by the unified frontend.

### The passkey itself

`app/Services/PasskeyService.php` is the single source of truth:

```php
public static function hash(string $passkey): string
{
    return hash_hmac('sha256', $passkey, config('app.key'));
}

public function generateUniquePasskey(): string
{
    do {
        $passkey = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    } while (User::where('passkey_hash', self::hash($passkey))->exists());

    return $passkey;
}
```

- **`random_int()`** — PHP's cryptographically secure RNG (not `rand()`/`mt_rand()`).
- **Uniqueness** is enforced by regenerating until no existing user has the same hash — this is only checkable at all because the hash is deterministic (see below).
- **Why HMAC-SHA256, not `Hash::make()`/bcrypt**: a 6-digit passkey has only 1,000,000 possible values, so no hash algorithm makes it resistant to brute force by itself — that's what login rate-limiting/lockout is for (below). What hashing buys here is that the plain passkey never sits in the database. Because a passkey login has **no username/email to look the user up by first** (that's the whole point — no email, no password), resolving "which user does this passkey belong to" requires a direct, indexed query (`User::where('passkey_hash', $hash)->first()`), which is only possible with a *deterministic* hash — bcrypt/argon2 salt every call differently, so the only way to check them would be iterating every user in the table and calling `Hash::check()` on each, for every single login attempt. HMAC keyed with the app's own secret key avoids being a bare unsalted SHA-256 (which would be precomputable across all 1M possible codes) while staying deterministic and O(1) to query. This is the same category of tradeoff Sanctum itself makes for token lookup.
- **Never exposed**: `passkey_hash` is in `User::$hidden`; never in `$fillable` either (so it can never be set via mass assignment) — the only way to set it is `PasskeyService::issueFor()`, which uses `forceFill()`.
- **Never logged**: nothing in this feature ever passes the plain passkey to `Log::*` — `MailerSendService` logs only the recipient/subject when mail isn't configured, never the body.

### Verification workflow — when a passkey gets issued

There is no separate "Member.status: pending/verified" field added for this — the codebase already has exactly the right concept: **subscription verification** (`Subscription.status`, `SubscriptionController::verify()`). Reusing it instead of inventing a parallel "membership status" was a deliberate choice (the task explicitly said reuse Subscription, don't duplicate business logic):

1. **Bureau creates a subscriber** — `MemberController::store()` (`POST /members`). `StoreMemberRequest` no longer has a `password` field at all — subscribers created here never sign in with email/password, so making the bureau invent an unused password was pointless. The `users.password` column isn't nullable, so it's filled with `Hash::make(Str::random(40))` — a value nobody knows and that's never communicated anywhere, which in practice makes email/password login for this account impossible (not merely "not the intended path" — actually unusable, since no one has the password). The new user has `passkey_hash = null` → `hasPortalAccess()` is `false` → cannot use the Member Portal yet.
2. **Subscriber cannot log in** — matches the above: no passkey, no bureau password anyone knows.
3. **Authorized bureau member verifies a subscription** — `SubscriptionController::verify()` (`POST /subscriptions/{id}/verify`), same endpoint/policy as always, completely unchanged authorization (`SubscriptionPolicy::verify` = president/tresorier/vice-tresorier, unchanged).
4. **Only then, and only once**: inside `verify()`, if `$subscription->member->user` exists and `! $user->hasPortalAccess()`, it calls `PasskeyService::issueFor($user)` and emails the welcome message. **Verifying a second/later subscription for an already-portal-enabled user does nothing extra** — no new passkey, no second email — this is the literal "never regenerate a passkey when verifying an already verified subscriber" rule, and it falls out naturally from checking `hasPortalAccess()` first.

```php
$user = $subscription->member?->user;

if ($user && ! $user->hasPortalAccess()) {
    $passkey = $passkeys->issueFor($user);
    $mailer->send($user->email, $user->name, 'Welcome to '.config('app.name'),
        view('emails.welcome', [...])->render());
}
```

### MailerSend integration

`app/Services/MailerSendService.php` — a thin, reusable wrapper around MailerSend's HTTP API (`POST https://api.mailersend.com/v1/email`) via Laravel's `Http` facade; no MailerSend SDK package was added, since the API surface needed here (send one HTML email) is a single call. Config lives in `config/services.php` under `mailersend` (`key`, `from_email`, `from_name`), reading `MAILERSEND_API_KEY`/`MAILERSEND_FROM_EMAIL`/`MAILERSEND_FROM_NAME` — the conventional Laravel location for third-party API credentials (same file Postmark/Resend/SES/Slack already live in), not a new bespoke config file.

If `MAILERSEND_API_KEY` is unset, `send()` logs the attempt (recipient + subject only, never the HTML body) and returns `true` instead of calling out to the API — callers never need to branch on "is mail configured yet", matching how this app already treats other not-yet-issued API keys (e.g. `VITE_TOMTOM_API_KEY`'s placeholder pattern on the frontend). A real key is expected to be filled in per-environment.

Two Blade views render the actual HTML (`resources/views/emails/welcome.blade.php`, `passkey-reset.blade.php`) — self-contained, inline-styled, table-based markup (the convention for HTML email, since client CSS support is inconsistent), both showing: greeting, the 6-digit passkey in a large monospace-style block, an "Open Member Portal" button linking to `{FRONTEND_URL}/member`, and a short explanation of what the passkey is for / how to reset it.

`config('app.frontend_url')` (new, `FRONTEND_URL` env var, defaults to `http://localhost:5173`) is the one place the backend knows the *frontend's* own base URL — needed only to build this link; nothing else in the app referenced the frontend's URL before.

### Passkey login & reset — `MemberPortalAuthController`

Two public endpoints (outside `auth:sanctum`, same tier as `/login`/`/register`):

- **`POST /member/login`** — body `{ "passkey": "123456" }` (validated `regex:/^\d{6}$/`). Resolves via `PasskeyService::resolve()`. On match: issues a normal Sanctum token (`$user->createToken('member-portal')`, named differently from the bureau `'api'` token purely for audit trails in the `personal_access_tokens` table — functionally identical token). On no match: **generic** `401 "Invalid passkey."` — deliberately identical whether the code was well-formed-but-wrong or simply belonged to nobody, so a caller can't learn anything from the response.
- **`POST /member/forgot-passkey`** — body `{ "email": "..." }`. Looks up the member by email; **only if a matching user exists AND already `hasPortalAccess()`** does it issue a new passkey (overwriting the old hash — the old passkey stops working immediately) and send the passkey-reset email. A pending (never-verified) subscriber has no passkey to reset, so nothing happens for them — but the HTTP response is **identical either way**: `200 {"message": "If that email is registered and verified, a new passkey has been sent."}`. This is what makes the endpoint safe against email enumeration — it can't be used to test which emails exist or which subscribers are verified.

**Rate limiting** (`Illuminate\Support\Facades\RateLimiter`, keyed by request IP):
- Login: 5 failed attempts → locked for 15 minutes (`RateLimiter::hit($key, 900)` on failure, `tooManyAttempts($key, 5)` checked first, `clear($key)` on success). Verified live: 5 cumulative failures (across separate requests) correctly blocked the 6th attempt — including the correct passkey — with `429`.
- Forgot-passkey: 3 requests per 15 minutes per IP, same `RateLimiter` pattern. (Count/window aren't specified by name anywhere else in the app; picked as a reasonable default for an unauthenticated, enumerable-by-email endpoint.)

### Member dashboard — `MemberPortalController::dashboard()`

`GET /member/dashboard` (inside the normal `auth:sanctum` group — the passkey-issued token authenticates it exactly like any other Sanctum token). Always scoped to `auth()->user()->member` — there is no `{id}` anywhere in this controller, so there's no cross-member access to even guard against. Reuses `SubscriptionResource`/`DonationResource` as-is for history + receipts (`receipt_url` is already on both) rather than re-deriving their shape. Response:

```json
{
  "profile": { "name": "...", "email": "...", "phone": "...", "address": "..." },
  "membership_status": "verified",
  "subscription_status": "verified",
  "current_subscription": { ...SubscriptionResource... },
  "expiration_date": "2027-07-23",
  "outstanding_balance": 0,
  "subscription_history": [ ...SubscriptionResource[]... ],
  "donation_history": [ ...DonationResource[]... ]
}
```

- **`membership_status`** is always `"verified"` — reaching this endpoint at all requires a passkey, which is only ever issued after verification, so a logged-in portal user is a verified member by construction. Not a stored field.
- **`current_subscription`** = the subscription with the latest `expires_at`. **`subscription_status`**/**`expiration_date`** are read off it.
- **`outstanding_balance`** — kept exactly as it was for backward compatibility: an honest but approximate derivation from the *current* subscription only (`0` while it's `verified` and not yet expired, otherwise its `amount`), predating the Annual Dues system below and never rewired to use it (changing an existing field's meaning was avoided — see Annual Dues). **`dues_history`** (new, see below) is the accurate per-year figure now sitting alongside it in the same response — a real ledger (`amount_due`/`amount_paid`/`balance`/`status` per year) now exists, this field just isn't it.
- **Reused by the unified frontend** (see "App Unification" below) as the data source for a plain subscriber's Dashboard summary and Profile page. Its `donation_history`/`subscription_history` fields are **not** surfaced anywhere in the unified app — a prior session briefly used `donation_history` as a "My Donations" personal view, found to be wrong (subscribers don't get a personal donations page at all — see "Donation access scoping" above), and removed.

**Removed this session** (superseded by reusing the bureau `/projects` + `/projects/{id}` pages for everyone, see "App Unification"): `MemberPortalProjectController`, `MemberPortalProjectResource`, and the `GET /member/projects` / `GET /member/project-reports/{id}/download` routes — these existed for exactly one prior session as the Member Portal's read-only project view; `CommitteeProjectPage.jsx` already covered the same ground (and more) once reachable by a subscriber, so keeping both would have been the duplication this session was explicitly asked to remove.

### Security notes

- **Isolated from bureau auth end-to-end**: separate controller, separate validation, no shared code path with `AuthController::login()`. The token each issues is the same *kind* (Sanctum), but a subscriber's existing role (`abonne`) already restricts them to self-scoped data everywhere via the policies documented above — reusing the token mechanism doesn't grant any new capability, it's just how the request authenticates.
- Every response — success or failure, login or reset — was designed around "never let the response teach an attacker anything" (generic 401, generic reset message, identical timing-insensitive code paths for exists/doesn't-exist).
- Verified live end-to-end: created a subscriber via `POST /members` with no password → confirmed passkey login rejected pre-verification → verified their subscription → confirmed `passkey_hash` set, plain passkey never appears in `storage/logs/laravel.log` (only recipient+subject) → 4 wrong attempts + 1 earlier pre-verification attempt correctly triggered the 5-attempt lockout on the 6th (even correct) attempt, `429` → cleared the rate limiter, logged in successfully, fetched `/member/dashboard` → ran `forgot-passkey` for both the real email and a nonexistent one, got byte-identical responses → confirmed the old passkey stopped working immediately after reset → verified a *second* subscription for the same member and confirmed the passkey hash was unchanged and no second email was logged → confirmed bureau password login is completely unaffected. All test data cleaned up afterward.

## App Unification (single dashboard, permission-driven)

The frontend used to be two applications sharing this backend: the bureau SPA and a fully separate "Member Portal" mini-app (its own axios instance, its own token storage, its own `/member/dashboard` page, no shared layout/nav). This session collapsed that into **one** application — same login screens, same two authentication mechanisms (bureau email/password, subscriber 6-digit passkey), but every authenticated user now lands on the same `/dashboard` inside the same layout, with the sidebar and page content driven by permissions/committee assignments rather than which "app" they logged into.

**No backend auth changes were needed** — both `AuthController::login()` and `MemberPortalAuthController::login()` already issued interchangeable Sanctum tokens against the identical `User` model, and `GET /me` already worked the same regardless of which endpoint minted the token. The unification was almost entirely a frontend routing/nav change (see `frontend/FRONTEND_SUMMARY.md`), plus two small backend adjustments:

1. **`ProjectReportPolicy` fix** (documented above, under Project Closure Reports / My Projects) — required for a former committee member to see their own completed project's report through the now-shared `CommitteeProjectPage.jsx`, since closing a project dissolves the committee that same policy used to gate on.
2. **Deletion of the now-redundant `MemberPortalProjectController`/`MemberPortalProjectResource`** (documented above) — their job is now done by the existing `/projects` + `/projects/{id}` endpoints, reused for every audience instead of duplicated.

`GET /member/dashboard` (`MemberPortalController::dashboard()`) was **kept**, not deleted — it's still the safe, self-scoped data source the unified frontend reuses for a plain subscriber's Dashboard summary and Profile page. Nothing about its own authorization changed. (A later session removed its brief use as a "My Donations" data source — see "Donation access scoping" above.)
## Subscription Expiration

`app:expire-subscriptions` (`app/Console/Commands/ExpireSubscriptions.php`), registered in `bootstrap/app.php` via `->withSchedule(fn (Schedule $schedule) => $schedule->command('app:expire-subscriptions')->daily())` — there was no scheduler configuration at all before this (Laravel 11+/13-style config, no `App\Console\Kernel` class exists in this app).

- Query: `Subscription::where('status', 'verified')->whereDate('expires_at', '<', now()->toDateString())` — the `status = 'verified'` filter alone means already-`expired`/`pending`/`rejected` rows are never touched, no extra guard needed.
- Each match: `update(['status' => 'expired'])` (individually, not a bulk `update()`, so the `LogsActivity` trait still fires per row), then `Notification::notifyUsers([$subscriberUserId], ...)` ("Your subscription expired.") and `Notification::notifyRoles(['president', 'tresorier', 'vice-tresorier'], ...)` ("Member {name} subscription expired.").
- **No change needed anywhere else** for the "expired subscribers can't be assigned to a committee" business rule — `AuthorizationHelper::hasVerifiedSubscription()` was already a strict `status === 'verified'` equality check, so it naturally starts returning `false` the moment this command flips a subscription's status; `MemberResource::has_verified_subscription` is computed fresh from the DB on every request, no caching involved.
- Existing committee members whose subscription later expires **stay assigned** — this command never touches `member_project`/`committee_assignments`, by design.
- Verified live: created a subscription with `expires_at` in the past and `status: 'verified'`, ran the command, confirmed status flipped, both notifications were created with the right message text, a second run correctly processed 0 rows (idempotent), and a since-expired-only member was correctly rejected (422) when assignment was attempted.
- **Extended this session** with a second pass reminding subscribers 30/7/0 days *before* expiry (not just on/after) — see Notification Center below for the full design (dedup via `notifyUsersOnce`, the `expires_at`-is-a-plain-string bug caught during testing).

## Annual Dues

A per-member, per-year billing ledger layered on top of Subscriptions — an **extension**, not a replacement: `Subscription` still represents an individual payment/verification event exactly as before, `Due` is a new, separate concept (what a member owes for a given calendar year) that payments link to. Every existing subscription/payment/approval/receipt/report/dashboard/notification/activity-log flow keeps behaving exactly as it did before this feature — verified end-to-end (447/447 tests passing, zero regressions, plus a live smoke test against the real MySQL dev DB).

**`dues` table** (`member_id`, `year`) unique — one row per member per year, `amount_due`/`amount_paid`/`balance`/`status`/`due_date`/`paid_at`/`waived_reason`. **`subscriptions.due_id`** (new, nullable FK, `nullOnDelete()`) — the due a payment applies toward; every subscription created before this feature keeps `due_id = null` forever, unaffected.

### The state machine (`DuesService::recalculate()`)

The single place that computes `amount_paid`/`balance`/`status` from a due's linked payments — every event that could change them routes through here instead of re-deriving status inline:

```
balance == amount_due          -> pending
0 < balance < amount_due       -> partial
balance == 0                   -> paid   (paid_at set once, on first transition)
due_date passed, balance > 0   -> overdue
waived_reason set              -> waived (sticky — payment activity no longer moves it
                                           until someone clears waived_reason)
```

`amount_paid` is computed fresh each call as `SUM(amount)` over the due's `payments()` where `status = 'verified'` — draft/pending/rejected/expired payments never count, matching the exact same "only verified/approved money counts" discipline `Subscription::FINANCIALLY_COUNTED`-style logic already follows elsewhere in this app (Donation/Expense). `recalculate()` is called, wrapped in `DB::transaction()` with `Due::lockForUpdate()` (prevents two concurrent payment events on the same due from racing each other's read-modify-write), from every `SubscriptionController` action that can change what a due should show:

- **`verify()`** — the main trigger: a payment being verified is what actually moves the due forward.
- **`update()`** — only when the subscription being edited is already `verified` and linked to a due (editing a still-pending payment's amount doesn't move anything, since it isn't counted yet).
- **`destroy()`** — deleting a verified, due-linked payment must stop counting it.
- **`uploadReceipt()`** — replacing a receipt on an already-verified payment reverts its status to `pending` (existing, unrelated behavior, unchanged) — which means it stops counting, so the due needs recalculating too.

### Linking payments to dues — fully automatic

`SubscriptionController::store()`: if the request didn't specify an explicit `due_id` (rare — `StoreSubscriptionRequest` accepts one optionally, `nullable|exists:dues,id`, for a caller that already knows it), it resolves the payer's due for `Carbon::parse(payment_date)->year` via `DuesService::getOrCreateForMemberYear($member, $year)` — **creating that due on the fly** (using the association's `annual_subscription_amount` setting as `amount_due`, `due_date = "{year}-12-31"`) if it doesn't exist yet — and links it. This means the existing subscription-creation flow (frontend form included) needed **zero changes** to start participating in the dues system: every new payment auto-links itself, silently, server-side. `getOrCreateForMemberYear()` is idempotent (checked-then-created inside a transaction, with a `QueryException` catch-and-refetch on a unique-constraint race from two simultaneous requests) — safe to call from anywhere, any number of times, for the same member+year.

### Generation (`DuesService::generateForYear()` / `app:generate-annual-dues {year?}`)

Iterates every `Member` (`chunkById(100, ...)`), calling the same `getOrCreateForMemberYear()` above and counting `wasRecentlyCreated` to report `{created, skipped}` — **safe to run multiple times**, a member who already has a due for that year is simply skipped, never duplicated (belt-and-suspenders: idempotent in application code, and the `(member_id, year)` unique index makes a true duplicate impossible even if the application-level check were somehow bypassed). Scheduled `->yearlyOn(1, 1, '00:30')` in `bootstrap/app.php`; also runnable ad hoc/backfilled for a past year via the `{year}` argument.

### Overdue detection (`DuesService::markOverdue()` / `app:mark-overdue-dues`, daily)

Time passing doesn't by itself trigger `recalculate()` (that only runs on a payment event) — so a due with zero payments ever received needs a separate sweep once its `due_date` passes. Query: `whereDate('due_date', '<', today)->where('balance', '>', 0)->whereNotIn('status', ['paid', 'waived', 'overdue'])` — mirrors `ExpireSubscriptions`'s exact idempotency pattern (the query itself excludes already-processed rows, so a second run the same day is a safe no-op, no `Once`-variant notification needed here either, same reasoning). Notifies the member and président/trésorier/vice-trésorier.

### Waiving (`DuesService::waive()` / `POST /dues/{id}/waive`)

Président/trésorier/vice-trésorier only (`DuePolicy::waive`, hardcoded role check — see Roles & Permissions above for why, matching `SubscriptionPolicy::verify`'s existing precedent rather than "fixing" it here). Sets `waived_reason` + `status: waived`; from then on `recalculate()`'s first check (`waived_reason !== null`) short-circuits and leaves it alone even if a new verified payment later links to that due — waiving is a deliberate, sticky override, not just a one-time status flip.

### Reachable via `DueController` (read + waive only — no dead CRUD surface)

`GET /dues` (self-scoped for `abonne`, `?member_id=`/`?year=`/`?status=` filters for bureau), `GET /dues/{id}`, `POST /dues/{id}/waive`. **No create/update/delete endpoints** — dues are system-generated and auto-updated via linked payments; a manual creation/edit HTTP surface was deliberately not built (the task's own spec asked for automatic generation, not a manual form), matching the "no dead code" constraint.

### Dashboard, Reports, Member Portal — additive only

- **`DashboardController`**: new `dues` block (`{year, expected, collected, outstanding, overdue_members, collection_rate}`, current year), computed via `DuesService::summary(int $year = null)` — the same method `ReportController::dues()` calls, so the dashboard card and the PDF/Excel report can never disagree about these 4 numbers (this was a real duplication caught and fixed mid-session — the two controllers originally each summed the `dues` table independently). Every existing dashboard key is untouched.
- **`ReportController::dues()` / `duesExcel()`** (new `DuesExport`) — same bureau-only `abort_unless(isBureauMember)` gate as every other report; PDF adds an Expected/Collected/Outstanding/Collection Rate summary row above the per-due table.
- **`MemberPortalController::dashboard()`**: new additive `dues_history` field (`DueResource::collection($member->dues)`) — `outstanding_balance` (the pre-existing, now-approximate field) is untouched, see the fixed Known Gaps note above.
- **`SubscriptionResource`**: new additive `due` field (`whenLoaded('due')` — `{id, year, status, balance}`), `null` for unlinked/legacy subscriptions. `SubscriptionController`'s every `with()`/`load()` call updated to eager-load `due` alongside `member.user`/`verifier` — no N+1 introduced.

### Verified live end-to-end (real MySQL dev DB, all test data cleaned up afterward)

Created a subscriber, posted a payment with no `due_id` → a due auto-created (`amount_due: 500` from the real association setting, `status: pending`) and linked. Verified it (partial, `amount: 100`) → due became `partial`, `balance: 400`. A second payment (`400`) verified → due became `paid`, `balance: 0`, `paid_at` set, `due_paid` notifications fired to the member and every président/trésorier/vice-trésorier account. Dashboard's `dues` block matched exactly (`expected: 500, collected: 500, outstanding: 0, collection_rate: 100`). Waived a fresh due as président → `status: waived`, `waived_reason` recorded. Both `/reports/dues` (PDF, 900KB+) and `/reports/dues/excel` downloaded successfully. Also confirmed every pre-existing flow untouched: `available_funds`, the `subscriptions` dashboard block, and `/reports/subscriptions`/`/reports/members`/`/reports/projects` all still returned identical shapes/values.

## Member Deletion

**The bug**: `MemberController::destroy()` only ever called `$member->delete()` — removing the `Member` profile row (phone/address) but never the `User` account (name, email, password hash) it was created together with. Since `store()` creates a `User` + `Member` as one atomic pair (a subscriber account *is* that pair — see Passkey Authentication above, the `User` gets an unusable random password specifically because it only exists to represent this one subscriber), deleting only half of it left a permanent orphaned `users` row behind: the email could never be reused, and the account technically still existed (with a role, potentially still-valid Sanctum tokens) even though the UI said "Member deleted." Root cause of the user-visible report ("delete subscriber doesn't delete from the database"): the orphaned `users` row was exactly what showed up in a direct database inspection.

**The fix** — `MemberController::destroy()`, inside a `DB::transaction()`:

```php
$user = $member->user;
$member->delete();

if ($user && ! AuthorizationHelper::isBureauMember($user)) {
    $user->tokens()->delete();   // personal_access_tokens has no FK cascade (polymorphic)
    $user->roles()->detach();    // model_has_roles has no FK cascade either (Spatie, polymorphic)
    $user->delete();
}
```

**Bureau accounts are deliberately excluded** — a bureau officer's `Member` row can still be deleted (e.g. by mistake, or intentionally to clear their subscriber-side profile), but their `User` login/role is never touched by this action. Bureau accounts are shared system-access identities (roles, approval authority, causer of activity-log entries) — not single-purpose subscriber records — so this endpoint must never be the thing that silently destroys someone's login. `notifications.user_id` already `cascadeOnDelete()`s (no extra cleanup needed there); direct permission assignments (`model_has_permissions`) weren't touched since this app never grants permissions directly to a user, only via roles (confirmed: every grant goes through `RolePermissionSeeder`).

Verified live against the real MySQL dev DB: deleting a fresh subscriber removed both the `members` and `users` rows; deleting a bureau test account's (`tresorier`) `Member` row left their `User` + role fully intact (`hasRole('tresorier')` still `true` afterward) — then that test bureau member's profile was recreated so the dev environment was left exactly as found. Also cleaned up two pre-existing orphaned `users` rows from an earlier debugging session's own test data (the literal rows the original bug report was about). Replaced the one existing delete test in `tests/Feature/Members/MemberCrudTest.php` with two (subscriber → both rows gone; bureau member → only the profile row gone) — full suite **448/448** (final count, after both the Annual Dues and Member Deletion work — see Tech Stack above).

## Available Funds Formula (`FundsHelper::availableFunds()`)

Single source of truth, called by both `DashboardController::index()` (`available_funds`) and `StoreProjectFundAllocationRequest`'s balance check — these used to be two independent, disagreeing calculations (see Fund Allocations above), now unified into one `app/Helpers/FundsHelper.php`.

Money leaves central (association) funds **only when allocated to a project** — never when a project spends it, since expenses can be funded by that project's own donations as well as its allocation. But unspent money returning when a project closes now applies to **both** sources, not just the allocation: unspent allocation un-does the earlier subtraction (as before); unspent donations are **added on top as new funds**, since a donation-funded project's leftover balance was previously just vanishing — it was never part of `available_funds` to begin with, but nothing credited it back anywhere once the project closed, and the closure report's `returned_to_pool` figure was misleadingly implying it had been. The live formula (recomputed fresh on every call, not cached or snapshotted):

```php
$openProjectAllocations = ProjectFundAllocation::whereHas('project',
    fn ($q) => $q->whereNotIn('status', ['completed', 'cancelled'])
)->sum('amount');

$closedProjectsCommittedAllocation = 0.0;
$closedProjectsReturnedDonations = 0.0;

Project::whereIn('status', ['completed', 'cancelled'])->get()->each(function ($project) use (&$closedProjectsCommittedAllocation, &$closedProjectsReturnedDonations) {
    $allocated = $project->fundAllocations()->sum('amount');
    $donations = $project->donations()->sum('amount');
    $expenses  = $project->expenses()->financiallyCounted()->sum('amount'); // approved/paid only

    // Expenses are covered by the project's own donations first, then its allocation.
    $donationsUsed = min($donations, $expenses);
    $allocationUsed = min($allocated, max(0, $expenses - $donations));

    $closedProjectsCommittedAllocation += $allocationUsed;
    $closedProjectsReturnedDonations += $donations - $donationsUsed;
});

$availableFunds = $verifiedSubscriptionsTotal
    - $openProjectAllocations
    - $closedProjectsCommittedAllocation
    + $closedProjectsReturnedDonations;
```

- **Open (draft/committee_ready/funding_ready/active) projects**: their full allocation stays committed/unavailable, regardless of how much they've spent so far.
- **Completed/cancelled projects**: expenses are covered by that project's own donations first, then its allocation. Whatever allocation wasn't needed to cover expenses returns to `available_funds` (un-subtracted); whatever donation money wasn't spent is **added** to `available_funds` as new funds.
- Verified end-to-end against the live app: a project funded purely by a donation, closed with zero expenses, now correctly adds that donation's full amount to `available_funds` (previously: zero change, despite the closure report claiming it was "returned to pool"); the returned/added balance was then confirmed spendable by successfully allocating the full new total to a fresh project (i.e. it's real money in the formula, not just a display fix — `StoreProjectFundAllocationRequest` uses the identical `FundsHelper` call).
- An earlier version of this formula (before the fix above) already clamped the allocation-side deduction correctly but never touched donations at all; an even earlier version subtracted a closed project's **entire** expense total unclamped and only excluded `status = 'completed'` (not `cancelled`), which could produce large negative `available_funds` values. Both are superseded by the formula above.

## Association Settings

A single, application-wide branding/contact/membership-defaults record — there is only ever one association, so there is only ever one `association_settings` row.

- **Table**: `association_settings` — `association_name`, `association_logo` (storage path, nullable), `address`, `phone`, `email`, `website` (nullable), `annual_subscription_amount` (decimal, default 0), `currency` (default `MAD`), `description` (nullable). No uniqueness constraint enforces the singleton — that's an application-level guarantee (see `SettingsService` below), matching how this project already treats its dev database as having no real "multi-tenant" concerns.
- **`SettingsService`** (`app/Services/SettingsService.php`) — the single source of truth every controller/report/email must go through instead of querying `AssociationSetting` directly:
  - `get(): AssociationSetting` — returns the one row, creating it with sane defaults via `AssociationSetting::firstOrCreate([], $defaults)` on first access (empty conditions array is deliberate: there's only ever one row, so "find any row" is the correct lookup). Never returns null.
  - `refresh(): AssociationSetting` — clears the cache and re-fetches; call after every update.
  - **Caching detail**: `get()` caches the row's raw attributes array (`Cache::rememberForever`), not the Eloquent model instance itself, then rehydrates a model (`forceFill` + `exists = true`) on every call. This project's cache store is the `database` driver, and round-tripping a serialized Eloquent model through it comes back as `__PHP_Incomplete_Class` on a cache hit in a fresh PHP process (verified via tinker) — a plain array serializes/unserializes reliably regardless of cache driver, so that's what's cached.
- **`AssociationSettingPolicy`** — `update(User $user): bool` is `$user->hasRole('president')`, the only gate in the whole feature. There's no `view()` gate: `GET /api/settings` is a public route (see Routes below), so there's no "guest" case to authorize against.
- **Routes**:
  - `GET /api/settings` — **public**, outside the `auth:sanctum` group. The login page and the member-portal login page render association branding (name/logo) before any session exists, so this can't sit behind auth. Returns `AssociationSettingResource`.
  - `PUT /api/settings` — inside `auth:sanctum`; gated by `AssociationSettingPolicy::update` (president-only, 403 for anyone else). Accepts `multipart/form-data` via Laravel's standard method-spoofing (`POST` + `_method=PUT` field) so the optional `logo` file upload works — PHP only populates `$_FILES` for a literal `POST` request method, never a literal `PUT`.
- **`AssociationSettingController`**:
  - `show()` — `SettingsService::get()`, no authorization check (see above).
  - `update(UpdateAssociationSettingRequest $request)` — authorizes, then if a `logo` file is present: deletes the old `association_logo` file from the `public` disk (if any) before storing the new one under `association/`, mirroring the invoice/receipt "delete old file after successful replacement" pattern already used by Expense/Donation. Calls `SettingsService::refresh()` after saving so the cache never serves a stale value on the very next request.
- **`UpdateAssociationSettingRequest`** validation: `association_name`/`address` required strings; `phone` required string; `email` required valid email; `website` nullable string; `annual_subscription_amount` required numeric `min:0`; `currency` required string; `description` nullable string; `logo` nullable file, `mimes:png,jpg,jpeg,svg`, max 5MB.
- **`AssociationSettingResource`**: `id`, `association_name`, `logo` (raw path), `logo_url` (`asset('storage/...')` or `null`), `description`, `address`, `phone`, `email`, `website`, `annual_subscription_amount` (float), `currency`, `created_at`, `updated_at`.
- **Global usage — every place settings are read instead of a hardcoded value**:
  - **Emails** (`MemberPortalAuthController::forgotPasskey`, `SubscriptionController::verify`) — `associationName`/`associationLogoUrl`/`associationAddress`/`associationPhone`/`associationEmail` are now pulled from `SettingsService::get()` (previously `config('app.name')` only) and passed into `emails/welcome.blade.php` / `emails/passkey-reset.blade.php`, which now render the logo (if set) in the header and contact info in the footer.
  - **PDF reports** — a new shared partial, `resources/views/reports/partials/letterhead.blade.php` (logo, association name, address/phone/email/website, "Generated {date}"), is `@include`d at the top of all four report views: `members.blade.php`, `subscriptions.blade.php`, `projects.blade.php`, `project-closure.blade.php`. `ReportController`'s three PDF actions and `ProjectController::close()` all now pass `'settings' => SettingsService::get()` into the view. The logo is referenced via `public_path('storage/...')` (a local filesystem path), not a URL — dompdf renders local paths directly without needing `isRemoteEnabled`.
- **Frontend**: see `frontend/FRONTEND_SUMMARY.md` — `SettingsContext`/`SettingsProvider` fetches the public endpoint once at the app root (outside `AuthProvider`, since the login pages need it pre-auth) and threads branding/currency through the sidebar, login pages, member portal, and a shared `formatCurrency` helper.

## Activity Explorer

Turns the old raw "Audit Log" list (`{description}` + `{causer} • {timestamp}`, no filtering) into a searchable, filterable, paginated browser over the exact same `activity_log` data — **nothing about how activity gets recorded changed this session**; every model's `getActivitylogOptions()` is untouched. Everything here is a read-time transformation.

### The `properties`-is-always-empty constraint

Every activity row's `properties` column (Spatie's old/new attribute diff) is empty (`[]`) for every single row in this app today, verified live via tinker across multiple models/updates — not something this session introduced or could fix without touching the logging config, which is explicitly out of scope ("Do NOT change the logging system"). This shaped the whole design: readable messages and "which status did this update produce" can't come from a diff, so they're inferred from **already-recorded domain data** instead:

- **The subject's own current column values** (e.g. a Donation's live `status`) — but only trusted for the **most recent** `updated` activity row on that subject. `ActivityLogHelper::describe()` checks `abs($activity->created_at->diffInSeconds($subject->updated_at)) <= 5` — if this row is the one immediately before the subject's current `updated_at`, nothing has changed it since, so its live status genuinely reflects what *that* update produced. An older `updated` row on an since-approved donation (e.g. its earlier draft → pending submission) stays a generic "updated", never mislabeled "approved".
- **The `ProjectPhaseRequest` ledger** (already recorded by the Project Phase Approval Workflow, itself deliberately not `LogsActivity`-tracked — see Committee Assignment Lifecycle) — for a Project's `updated` activity, `correlatedPhaseChange()` looks for an *approved* phase request whose `reviewed_at` lands within the same 5-second window as the activity's `created_at`, and if found, builds the exact `"changed \"{name}\" status from \"{From}\" to \"{To}\""` sentence from its real `from_phase`/`to_phase` — not a guess, a join against data that was already being recorded for an unrelated feature.
- The `changes` field in the detail response (`ActivityLogResource::withDetails`) still reads `properties['old']`/`properties['attributes']` honestly — it's `null`/`null` today, but starts working automatically with zero code changes if that ever gets fixed (also out of scope for this task).

### `ActivityLogHelper` (`app/Helpers/ActivityLogHelper.php`)

Single source of truth for everything entity-shaped about this feature:

- **`ENTITIES`** — the 7 browsable subject types (`donation`, `expense`, `project`, `subscription`, `member`, `fund_allocation`, `report`) mapped to their FQCN, table, display label, searchable name columns, their FK column back to a project (`null` for Member/Subscription — they have none; `null` for Project itself, which *is* the project), and status column + value→label map. Drives the Entity/Status/Project filters, the search query, and `describe()`'s formatting — one place, not duplicated across the controller/resource/policy. `member`'s `label` is now `'Subscriber'` (was `'Member'`) — a pure display-string change for the Members → Subscribers UI rename (see `frontend/FRONTEND_SUMMARY.md`); every lookup into `ENTITIES` is keyed by `member` (the array key), never by the label text, so this was safe to change with zero functional impact — the entity filter dropdown, activity search-by-typing-the-label, and auto-generated sentences ("President created Subscriber Jane Doe") all just read the new string.
- **`scopeToAssignedProjects()`** — the permission-scoping query (see Permissions below); generalizes the old inline controller logic to all project-linked entities (previously only Donation/Expense/Project/ProjectFundAllocation were scoped — **ProjectReport was missing and is now included**, a bug fix within this task's explicit "activities related to projects they are assigned to" requirement). Member/Subscription activity is excluded entirely for a scoped viewer — neither has a project to be "related to".
- **`applyFilters()`** — user (`causer_id`), entity (`subject_type`), project (`whereExists` against each project-linked entity's table), status (same pattern), date range (`whereDate`), all combinable, all pure SQL `WHERE`/`whereExists` — nothing is fetched into PHP to be filtered.
- **`applyActionFilter()`** — the trickiest one. `created`/`updated`/`deleted` map straight to the `event` column. `generated` = `subject_type = ProjectReport AND event = 'created'`. The "domain" actions (`approved`/`rejected`/`submitted`/`paid`/`verified`) resolve via `DOMAIN_ACTIONS` (entity → the status value that action produced) and a `whereExists` subquery requiring `event = 'updated'`, the subject's current status to match, **and** the same `TIMESTAMPDIFF(SECOND, subject.updated_at, activity_log.created_at) <= 5` proximity check used in `describe()` — without it, filtering "Action = Approved" on a donation that went draft → pending → approved would match *both* of its `updated` rows, not just the approval. MySQL-specific SQL (matches this project's DB, see CLAUDE.md).
- **`applySearch()`** — one OR'd `where()`: `description` LIKE, causer name LIKE (`orWhereHas('causer', ...)`), a bare number in the search string also matches `subject_id` directly (covers "Expense #42"-style reference search — not scoped to the word "Expense", so a same-numbered row of a different entity can also surface; accepted as normal search fuzziness), each entity's own label matched case-insensitively against the search string (typing "Donation" narrows to `subject_type = Donation`), and per-entity `whereExists` against its `name_columns` (donor_name, supplier_name, invoice_number, project name, ...) plus a nested `whereExists` against `projects.name` for entities with a `project_column`. Member/Subscription search coverage is narrower by design (empty `name_columns` — no direct text field to match a member's name against without an extra join this session didn't add); documented as a scope boundary, not a bug.
- **`describe(Activity $activity)`** — turns one `Activity` (with `subject`/`causer` already eager-loaded, so this never issues a query for those) into `entity_key`, `entity_label`, `reference` (`#42` for most entities, `"Project Name"` for Project, the linked user's name for Member), `action_label`, `message` (the full readable sentence), `status` (`{value, label}` from the subject's **current** state, always honest regardless of which historical row this is — a `created` row on a since-approved donation still shows an "Approved" status badge, it's just the message that stays generic), and `project` (`{id, name}`, resolved via the subject's own `project()` relation, or the subject itself for a Project).
  - **Bug fix**: `actionFor()` required a non-nullable `string $event`, but a manually-logged row (`activity()->log(...)` without `->event(...)` — the `PasskeyService` precedent, and this session's Project deletion/budget-rejection audit lines) leaves the `event` column `null`. The `!$meta` (unrecognized-entity) branch above already null-coalesced `$activity->event ?? 'updated'`, but the normal `$meta` branch didn't — so any manually-logged row on an `ENTITIES`-mapped subject (e.g. `Project`) threw a `TypeError` and 500'd both `GET /activity-logs` and `GET /activity-logs/{id}` the moment one existed. `actionFor()` now accepts `?string $event` and returns `['Updated', 'updated']` immediately when it's `null`, matching the same graceful-fallback intent as the unrecognized-entity branch. Verified live: a budget-rejection's null-event `Project` activity row now renders as `"{causer} updated Project \"...\""` instead of crashing.

### `ActivityLogResource`

Wraps `describe()`'s output plus `id`/`event`/`causer`/`created_at` for the list view. `ActivityLogController::show()` sets a `withDetails` flag that adds `changes` (see the properties note above) and `related` (`project`/`member`/`donation`/`expense` — the last two reuse the existing `DonationResource`/`ExpenseResource` wholesale rather than re-declaring their fields, so the detail panel's "Related Expense/Donation" is always exactly as accurate as the Expenses/Donations pages themselves).

### `ActivityLogController`

- **`index()`** — eager-loads `causer` and `subject` (with `MorphTo::morphWith()` to also eager-load each subject's own `project`/`user` relation in the *same* query — avoids an N+1 across the page's rows for `describe()`'s project/member resolution; `correlatedPhaseChange()`'s `ProjectPhaseRequest` lookup is the one small exception, one extra indexed query per Project-typed row on the page, bounded by page size). Applies `scopeToAssignedProjects()` unless `canViewAllActivities()`, then `applyFilters()`, then `applySearch()` if `?search=` is present, sorts by `created_at` (+`id` tiebreaker) `desc` by default or `asc` via `?sort=asc`, paginates (`?per_page=`, default 25, clamped to 100), and — instead of `ActivityLogResource::collection()`, which would switch the response to Laravel's nested `data`/`links`/`meta` envelope — calls `$paginator->through(fn ($activity) => (new ActivityLogResource($activity))->resolve())`, which transforms each item in place while keeping the exact same flat pagination shape (`current_page`/`data`/`last_page`/...) every other paginated response in this app already uses.
- **`filters()`** — `GET /activity-logs/filters`, scoped identically to `index()`: distinct causers actually present in the activity the caller can see (never every user in the system), the caller's visible projects, and the static entity/status option lists from `ENTITIES`. Populates the frontend's filter dropdowns so they never offer a choice that would just return zero rows (or, worse, leak the existence of a user/project a scoped viewer shouldn't know about).
- **`show()`** — same `morphWith` eager-loading, `withDetails = true`.

### Permissions (widened this session, per the Activity Explorer spec)

`AuthorizationHelper::canViewAllActivities()` (new) = `hasAnyRole(['president', 'vice-president', 'tresorier'])` — unrestricted visibility into every activity, replacing the old "president only" rule. Every other bureau role (vice-trésorier, secrétaire général, vice-secrétaire général, conseiller) is scoped to activity related to projects they're actually assigned to, same as before but now via the shared `scopeToAssignedProjects()` helper and covering one more entity (ProjectReport, see above). A plain `abonne` subscriber is denied outright (`ActivityPolicy::viewAny` returns `false`) — deliberately **not** scoped-but-visible, even if that subscriber happens to be a committee leader on some project, matching the spec's explicit "Subscribers have no access" as its own tier, distinct from "committee members" (read as: bureau roles serving on committees).

### Frontend

See `frontend/FRONTEND_SUMMARY.md`'s Activity Explorer section — the Activity Cards/Timeline/filter bar/detail side-panel UI, all built on the endpoints above.

## Notification Center

Expanded this session from a handful of ad hoc `Notification::notifyRoles()`/`notifyUsers()` calls (subscription submitted/expired, project closed) into a role-aware notification system covering every workflow transition — **the underlying `Notification` model/table is extended, not replaced**: two nullable columns added (`subject_type`/`subject_id`), every existing call site kept working unchanged (the new params are optional and appended at the end).

### What changed on the model

- **`subject_type`/`subject_id`** (migration `2026_07_30_135103_add_subject_to_notifications_table`) — polymorphic, nullable. Lets a notification deep-link to the record it's about (`NotificationResource::link`) and lets scheduled commands avoid re-notifying the same user about the same record.
- **`notifyRoles()`/`notifyUsers()`** gained an optional trailing `?Model $subject = null` param — every pre-existing call site (`SubscriptionController`, `ProjectController::close()`, `ExpireSubscriptions`) was updated to pass one, so old notification types deep-link too, not just new ones.
- **`notifyRolesOnce()`/`notifyUsersOnce()`** (new, `$subject` required) — for the two new scheduled commands below: skips any recipient who already has a notification with the exact same `(type, subject_type, subject_id)`, so a daily cron never sends the same reminder twice.

### Every trigger, and who receives it

| Type | Fired from | Recipients |
|---|---|---|
| `subscription_pending` | `SubscriptionController::store/uploadReceipt` (existing) | `AuthorizationHelper::FINANCIAL_OVERSIGHT_ROLES` |
| `subscription_approved` | `SubscriptionController::verify` (new) | the subscriber |
| `subscription_expiring_30/7/today` | `ExpireSubscriptions` (extended, new) | the subscriber, `notifyUsersOnce` |
| `subscription_expired` | `ExpireSubscriptions` (existing) | the subscriber + `FINANCIAL_OVERSIGHT_ROLES` |
| `due_created` | `DuesService::getOrCreateForMemberYear` (new) | the member |
| `due_paid` | `DuesService::recalculate` (new, only on the pending/partial/overdue → paid transition) | the member + `FINANCIAL_OVERSIGHT_ROLES`-equivalent (président/trésorier/vice-trésorier) |
| `due_overdue` | `MarkOverdueDues` (new command) | the member + président/trésorier/vice-trésorier |
| `expense_pending` / `expense_resubmitted` | `ExpenseController::submit` (new) | `FINANCIAL_OVERSIGHT_ROLES` — same event as "Expenses waiting for approval" |
| `expense_approved` / `expense_rejected` | `ExpenseController::approve/reject` (new) | the expense's creator |
| `donation_pending` / `donation_resubmitted` | `DonationController::submit` (new) | `FINANCIAL_OVERSIGHT_ROLES` |
| `donation_approved` / `donation_rejected` | `DonationController::approve/reject` (new) | the donation's recorder |
| `phase_request_pending` | `ProjectPhaseRequestController::store` (new) | président + vice-président (the actual review authority — see below) |
| `phase_request_approved` / `phase_request_rejected` | `ProjectPhaseRequestController::approve/reject` (new) | the requester |
| `committee_assigned` | `ProjectMemberController::store/replace` (new) | the newly assigned member |
| `committee_removed` | `ProjectMemberController::destroy/replace` (new) | the removed/replaced-out member |
| `project_closed` | `ProjectController::close` (existing, now with `$subject`) | the project's (former) committee + bureau roles |
| `project_completed` / `project_report_generated` | `ProjectController::close` (new, this session — Member Portal "My Projects") | the project's (former) committee only |
| `project_overdue` | `NotifyOverdueProjects` (new command) | the project's committee leader(s) + président, `notifyRolesOnce`/`notifyUsersOnce` |
| `project_deletion_pending` | `ProjectDeletionRequestController::store` (new, this session) | president only (the actual review authority — see Project Deletion Workflow) |
| `project_deletion_approved` / `project_deletion_rejected` | `ProjectDeletionRequestController::approve/reject` (new, this session) | the requester |

`AuthorizationHelper::FINANCIAL_OVERSIGHT_ROLES` (new public constant, `['president', 'tresorier', 'vice-tresorier']` — `isFinancialOversightRole()` now just reads it) is reused at every "waiting for approval" site instead of re-typing the same 3-role array 4+ times.

### Deliberately not implemented (and why)

- **`subscription_rejected`** — the `subscriptions.status` enum has a `rejected` value, but no controller action ever sets it (confirmed — `verify()` is the only status-changing endpoint besides the expiry command). Adding a reject workflow would be a real feature addition, not a notification-system extension — out of scope ("no unrelated refactoring").
- **"Fund allocations are waiting for approval"** — fund allocations have no submit/approve workflow at all: `ProjectFundAllocationPolicy::create` already restricts *creation* to président/trésorier/vice-trésorier only, so the record is created directly by the approval authority — there's no "pending" state to notify about. Inventing one would mean building a new approval workflow, not extending the existing notification system.
- **"Annual report generated"** — the "Annual Report" button in the president dashboard header downloads a PDF synchronously (`GET /reports/*`) — there's no stored artifact or async generation step, so there's no server-side event to notify on (the same user who clicked it already has the file). `project_closed`'s existing message ("... closed — report available") already covers the one real "a report was generated and stored" event (`ProjectReport`), so a second, separate "report generated" type would just duplicate it.
- Notifying the *actual* review authority for phase requests (président + vice-président) rather than the wider financial trio the task's Approvals section lists as an example — `tresorier` has no ability to approve/reject a phase request (`ProjectPhaseRequestPolicy::REVIEW_ROLES`), so including them would be a notification about something they can't act on.

### `NotificationResource`

- **`category`** — one of `membership`/`projects`/`expenses`/`donations`/`committee`, derived from `type` via a static map (`TYPE_CATEGORIES`). No separate "approvals" category: `expense_pending`/`donation_pending`/`phase_request_pending` stay in their entity's own category — an "approvals" bucket would just be a filtered view of those same 3 types spread across 3 other categories, not a distinct 4th one.
- **`link`** — resolved from the eager-loaded `subject` (no extra query beyond loading it): a `Project` subject → `/projects/{id}`; a Donation/Expense/ProjectFundAllocation/ProjectPhaseRequest/ProjectReport subject → `/projects/{its project_id}` (each already has that column directly, no join needed) — **except** `phase_request_pending`, which links to `/phase-requests/pending` (the actual review queue, more useful than the project page for that specific type); a Subscription **or Due** subject → `/subscriptions` (a due's notifications link to the same page a subscriber's payment history/due chip lives on — see Annual Dues below). `null` if there's no subject (older pre-this-session rows, or a system-wide notice).

### `NotificationController`

- **`index()`** — now filters (`status`, `category` — resolved to a list of `type`s via `NotificationResource::typesForCategory()`, `date_from`/`date_to`) and paginates (default 20, max 100) instead of an unfiltered `limit(30)`. Same flat pagination envelope as `ActivityLogController` (`$paginator->through(...)`, not `Resource::collection()`'s nested shape) for consistency across the app's paginated endpoints.
- **`destroy()`** (new) — deletes one notification, 403 if not the owner. Manual only; nothing auto-deletes read notifications (per the task's explicit "keep notification history, do not delete read notifications automatically").

### Two scheduled commands (`bootstrap/app.php`, both daily)

- **`app:expire-subscriptions`** (extended, not replaced) — its existing "mark verified subscriptions expired" pass is untouched; a new `sendExpiryReminders()` pass checks subscriptions expiring in exactly 30/7/0 days (`whereDate('expires_at', $targetDate)`) and reminds the subscriber via `notifyUsersOnce` — safe to run more than once a day, and each of the 3 thresholds is its own `type` so a subscriber gets exactly 3 reminders over the countdown, never a duplicate of the same one. (One real bug caught and fixed during testing: `expires_at` is an uncast `date` column — a plain string, not a Carbon instance — the first draft called `->toDateString()` on it and crashed; fixed to use the string directly.)
- **`app:notify-overdue-projects`** (new) — `Project::whereNotIn('status', ProjectLifecycle::TERMINAL)->whereDate('end_date', '<', today)`, notifies that project's committee leader(s) and the président, both via the `Once` variants so a project stuck overdue for weeks only ever notifies once, not daily.

### "Real-time"

No websocket/broadcasting infrastructure exists in this app (no Reverb/Pusher/queue worker configured) — adding one would be substantial unrelated infrastructure, not a notification-system extension. The frontend polls `GET /notifications` every 45s instead (see `frontend/FRONTEND_SUMMARY.md`) — the practical "updates without a page refresh" mechanism given what's already deployed.

### Frontend

See `frontend/FRONTEND_SUMMARY.md`'s Notification Center section — the dropdown (click-to-navigate, more icons), the dedicated Notifications page (filters, mark-read, delete), and the polling mechanism.

## Action Center (`ActionCenterController`, `GET /action-center`)

One consolidated president-only read for the "what's waiting on me" surfaces — the dashboard's **Action Center card** and PresidentLayout's **Approvals sidebar badge**. Both used to assemble this same picture in the browser, each fetching every subscription / expense / donation / phase request in the whole association (unfiltered, unpaginated) and filtering to `status === 'pending'` client-side — 4–8 full-table reads per page load, over two call sites.

- **Shape**: `{ items, counts }`, each an object keyed `subscriptions`, `expenses`, `donations`, `phase_requests`, `deletion_requests`. `items.*` are the standard Resource collections (`SubscriptionResource`, `ExpenseResource`, `DonationResource`, `ProjectPhaseRequestResource`, `ProjectDeletionRequestResource`) so the card can render rows without a second lookup; `counts.*` are plain integers for the badge.
- **Every query is filtered to `status = 'pending'` server-side** and eager-loads the same relations each Resource needs — so the only data leaving the database is the small pending subset, not the full tables.
- **President-only**, enforced by a hard `abort_unless(auth()->user()->hasRole('president'), 403)` at the top of `index()` (not a policy — there's no model to gate on). This mirrors, not changes, existing access: the five categories combined only make sense for the one role that can act on all of them (a trésorier can't review phase/deletion requests; a vice-président can't approve donations/expenses). Both frontend call sites already gate on `isPresident` before fetching.
- **The Approvals badge counts four of the five categories** — `subscriptions + expenses + phase_requests + deletion_requests` — deliberately **excluding donations** (see `frontend/FRONTEND_SUMMARY.md`); the card still shows all five.
- Backed by the `status`-column indexes added in `2026_08_11_102118_add_performance_indexes_for_dashboard_notifications_action_center` (subscriptions/donations/expenses/project_phase_requests/project_deletion_requests).

## Localization (French, `lang/fr/`)

French is the application's default **and** fallback locale (`config/app.php`: `'locale' => env('APP_LOCALE', 'fr')`, `'fallback_locale' => env('APP_FALLBACK_LOCALE', 'fr')`), so there is no per-request locale switching — the API speaks French to every client. All user-facing text is resolved through Laravel's `__()` translation helper against `lang/fr/`, never hardcoded in a controller/policy/service, so wording stays consistent and lives in one place per concern. `__()` is called broadly — across every `Api/*Controller`, the `Policies/*`, `Helpers/` (`AuthorizationHelper`, `ProjectLifecycle`), `Services/DuesService`, and the scheduled `Console/Commands/*`.

**9 files under `lang/fr/`** — a mix of app-authored namespaces and Laravel's own defaults (all translated to French):

- **`validation.php`** (~241 lines) — Laravel's validation message set, French. Backs every `FormRequest`'s 422 error text (see Validation below). Largest file, since it covers every rule plus per-attribute custom messages.
- **`pdf.php`** (~153 lines) — all report/letterhead strings for the dompdf-generated PDFs (project closure reports, exports' headers). The frontend `pdf` i18n namespace mirrors these — the backend copy is the enforcing one for generated documents.
- **`notifications.php`** (~101 lines) — the title/body templates for every Notification Center event (see Notification Center). One entry per trigger type.
- **`messages.php`** (~65 lines) — generic API success/error text: every controller returning a plain `{"message": "..."}` (not a validation error) pulls it from here (e.g. `messages.auth.invalid_credentials`, `messages.donation.deleted`), so the same action reads identically across endpoints.
- **`policies.php`** (~47 lines) — authorization-denial text surfaced via `Response::deny(__('policies.…'))` from Policy classes and the lock/lifecycle helpers they call (e.g. `policies.project.locked`, `policies.project_deletion_request.only_pending_can_review`). This becomes the 403 response's `message`.
- **`emails.php`** (~26 lines) — copy for the two MailerSend member-portal emails (passkey issued / passkey reset — see Passkey Authentication).
- **`auth.php`, `passwords.php`, `pagination.php`** — Laravel's stock framework message files, French translations, otherwise unchanged.

**Note**: French text also appears as literals in a few non-`__()` spots that aren't request-response strings — the 8 role names are French kebab-case slugs (`tresorier`, `secretaire-general`, …, seeded by `RoleSeeder`, matched by key not display text) and the Demo seeders use French/Moroccan sample data (see Seeders) — those are data, not localizable UI strings, and deliberately don't live in `lang/fr/`.

## Response Conventions

- Resource collections: `{ "data": [...] }`; single resources: `{ "data": {...} }`.
- Raw JSON (not wrapped in a Resource): `/me`, `/projects/{id}/members`, `/dashboard`. `/activity-logs` is a partial exception — the outer pagination envelope is still the plain `current_page`/`data`/`last_page`/... shape (via `LengthAwarePaginator::through()`, not `Resource::collection()`'s nested `data`/`links`/`meta`), but each item in `data` is now `ActivityLogResource`-shaped rather than a raw `Activity` model — see Activity Explorer below.
- File endpoints (reports) return binary streams.
- `ProjectResource` computes `allocated` (sum fund allocations), `collected` (sum of **approved** donations only — see Donation Approval Workflow — **+** `allocated`), `expenses` (sum of **approved/paid** expenses only — see Expense Approval Workflow), `remaining` (collected − expenses) per project at serialization time; `budget` is the raw stored value, never adjusted by allocations. `progress_percentage` is **not** a financial computation — see Project Progress below. Also returns `members_count` **and** a `members` array (`id`, `user_id`, `name`, `committee_role`) — despite the field name, this is a member list, not just a count.
- `SubscriptionResource`'s nested `member` object now includes `user_id` (added so the frontend can check "is this my own subscription" without a separate lookup) alongside `id`/`name`. The resource already exposes `payment_method`, `receipt_number`, `receipt_file`, `notes`, `verified_at` — `frontend/API_CONTRACT.md`'s note that these are trimmed out is stale (it does still omit `verified_by`). Also exposes a new additive `due` field (`{id, year, status, balance}`, `null` if unlinked) — see Annual Dues above.
- File URLs built as `asset('storage/...')` — requires `php artisan storage:link`.

## Validation (FormRequests)

All `authorize()` return `true`; authorization is done in controllers via policies, not FormRequests.

- **Store/UpdateSubscriptionRequest**: member_id, amount, payment_method, receipt_number, receipt_file (string path), payment_date, expires_at, notes. `StoreSubscriptionRequest` additionally accepts an optional `due_id` (`nullable|exists:dues,id`) — see Annual Dues above; `UpdateSubscriptionRequest` doesn't, deliberately (re-linking an existing payment to a different due post-creation was out of scope).
- **Store/UpdateDonationRequest**: `member_id` and `donor_name` are `required_without` each other; project_id required; amount numeric 0.01–99,999,999.99.
- **Store/UpdateExpenseRequest**: project_id, supplier_name, description, amount (same bounds), payment_method required; invoice_number/notes nullable. Both also reject (422, `amount`) if the amount would exceed the target project's remaining funds (approved donations + allocations − existing approved/paid expenses, excluding the expense being edited on update).
- **StoreProjectRequest**: name, dates, budget (`min:0`, `max:99999999.99`), `latitude` (`nullable|numeric|between:-90,90`), `longitude` (`nullable|numeric|between:-180,180`), manager_id — no `status`/`phase` field at all (server always forces `draft`/`planning`, see Project Lifecycle above). **UpdateProjectRequest**: same fields (latitude/longitude both `sometimes|nullable`, so a partial location-only `PUT` validates cleanly) plus `status` (enum of all 6 stages, format-only); a `withValidator`/`after` hook then rejects (422) any status change `ProjectLifecycle::canManuallySetStatus()` doesn't allow — in practice only → `cancelled` from a non-terminal stage, or a same-value no-op.
- **StoreMemberRequest**: name/email, phone/address — no `password` field (see Member Portal above; the account gets an unusable random password, not a bureau-chosen one). **UpdateMemberRequest**: phone/address only.
- **StoreProjectFundAllocationRequest**: amount, allocation_date, proof_file (required file, unlike the other FormRequests where uploads are validated inline) — the one exception to "`authorize()` always returns `true`, no other request-level logic" below: it also carries the balance check described in Fund Allocations above via `withValidator`.
- **StoreProjectPhaseRequestRequest**: `to_phase` (in `ProjectPhaseWorkflow::REQUESTABLE`), `summary` (required), `notes` (nullable), `proofs` (required array, ≥1, each `mimes:pdf,jpg,jpeg,png|max:5120`) — also carries the one-pending-max and sequential-phase business checks via `withValidator`, see Project Phase Approval Workflow above.
- Actual upload files are otherwise validated inline in the upload endpoints (`receipt`/`invoice`/`file` rules), not in the FormRequests.

## File Storage (public disk)

| Content | Directory |
|---|---|
| Subscription receipts | `receipts/` |
| Donation receipts | `donation-receipts/` |
| Expense invoices | `expense-invoices/` |
| Fund allocation proofs | `project-fund-allocations/` |
| Project closure reports | `project-reports/` |
| Phase request proofs | `project-phase-proofs/` |
| Association logo | `association/` |

Old files are deleted on replace/destroy. `php artisan storage:link` must exist for URLs to resolve.

## Seeders

`DatabaseSeeder` runs in two stages. **Core setup** (production baseline): `RoleSeeder` → `PermissionSeeder` → `RolePermissionSeeder` → `PresidentSeeder`. **Then the Demo subsystem** (8 seeders under `database/seeders/Demo/`, see below) runs unconditionally on the same `db:seed` — so `php artisan migrate --seed` produces a full, realistic dev/demo dataset, not a bare baseline. `AssociationSetting`'s singleton row is created by `DemoAssociationSeeder` during a seed; on a DB that was migrated but *not* seeded (or seeded with only the core stage), `SettingsService::get()` still creates it lazily with defaults on first access (see Association Settings above) — so the row always exists either way.

- `RoleSeeder` also ensures every existing user has `abonne`.
- After changing grants, re-run `php artisan db:seed --class=RolePermissionSeeder` and clear the permission cache if needed.
- `backend/app/Console/Commands/CreateBureauAccounts.php` — an `artisan app:create-bureau-accounts` command that seeds one test account per bureau role (`president@association.test`, `vice-president@association.test`, `treasurer@association.test`, `vice-treasurer@association.test`, `secretary@association.test`, `vice-secretary@association.test`, `advisor@association.test`), each with a real `Member` row and password `password`. Useful for testing role-specific behavior since the default seeded president has no member profile.

### Demo subsystem (`database/seeders/Demo/` — 8 seeders + `Support/MoroccanData.php`)

Realistic, spec-driven stress-test data. Each seeder routes its writes through the **real** services/helpers (`DuesService`, `ExpenseBudgetValidator`, `ProjectController::close()`'s summary shape, `Notification::notify*`) rather than hand-writing derived columns — so the seeded state is reachable by the same code paths the app uses at runtime, and edge cases the spec asks for are forced deterministically. Ordered by dependency in `DatabaseSeeder`:

1. **`DemoAssociationSeeder`** — resets and seeds the `AssociationSetting` singleton with realistic French/Moroccan branding ("Association Al Amal pour le Développement Social", Rabat address, `currency: MAD`, `annual_subscription_amount: 200`), then calls `SettingsService::refresh()`.
2. **`DemoUsersMembersSeeder`** — reuses the seeded `president@association.com`, adds one account per bureau role, 5 advisors, and 80 subscribers (all password `password123`); every account — bureau included — also gets a `Member` profile.
3. **`DemoDuesSubscriptionsSeeder`** — 3 years of annual dues + subscription payments per member, every dues-affecting change routed through `DuesService::recalculate()`; subscriber indices 1–10 forced always-paid and 71–80 never-paid as deliberate edge cases.
4. **`DemoProjectsSeeder`** — 20 projects spanning every lifecycle status (draft → committee_ready → funding_ready → active → completed/cancelled), each with a committee except still-draft ones, plus fund allocations, phase requests, and deletion requests.
5. **`DemoDonationsSeeder`** — hundreds of donations across every source (`subscriber`/`association_member`/`state`/`other_association`/`benefactor`/`anonymous`), only on active/completed/cancelled projects (pre-active projects left as a "zero donations" edge case).
6. **`DemoExpensesSeeder`** — expenses respecting `ExpenseBudgetValidator`'s ceiling for every project except two deliberate edge cases (one pushed over budget, one landed exactly on budget).
7. **`DemoProjectFinalizeSeeder`** — runs last; mirrors `ProjectController::close()` (closure report + committee dissolution + notifications) for every `completed` project and dissolves the committee (no report) for every `cancelled` one, once the totals exist to summarize.
8. **`DemoActivityNotificationsSeeder`** — adds the login/logout activity entries the model-event-driven `LogsActivity` trait never produces, then does a realistic read/unread pass over the notifications the domain seeders already fired.

`Support/MoroccanData.php` is a shared static-data helper (not a seeder): localized fake-data pools (male/female first names, last names, cities, street prefixes/names, project names, expense categories, donor pools, payment methods) plus fake PDF/image byte generators and `storeFakePdf`/`storeFakeImage` helpers for populating receipt/invoice/proof file fields on the `public` disk.

## Commands

```bash
composer install && php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve                        # http://localhost:8000
composer dev                             # serve + queue + pail + vite in parallel
composer test                            # PHPUnit
./vendor/bin/pint                        # code style
php artisan app:create-bureau-accounts   # seed one test account per bureau role
php artisan app:expire-subscriptions     # expire due subscriptions + send 30/7/0-day expiry reminders (also scheduled ->daily())
php artisan app:notify-overdue-projects  # notify overdue projects' committee leader(s) + président (also scheduled ->daily())
php artisan app:generate-annual-dues {year?}  # create one due per member for a year, default current year (also scheduled ->yearlyOn(1, 1, '00:30'); idempotent)
php artisan app:mark-overdue-dues        # flip past-due, unpaid dues to overdue + notify (also scheduled ->daily())
php artisan schedule:list                # confirm every scheduled job is registered
php artisan schedule:work                # run the scheduler loop locally (production needs a real cron entry calling `schedule:run` every minute)
```

## Known Gaps / Notes

- `vice-tresorier` previously had zero permissions (old known gap) — fixed; see the role grant table above.
- `project.md` spec features not yet built: end-of-project committee report, annual bureau report, bank-operation justification photos. Subscriber self-service (own subscription history) **is** built (see frontend nav). "Browsing public projects" for subscribers is **no longer a thing** as of the Project Access Control security fix above — a plain `abonne` with no committee assignment now sees an empty project list, by design (this was the actual vulnerability: that "browse" was implemented as full, unscoped `ProjectResource` access to every project for every subscriber). If a genuine "public projects" feature (e.g. name/description/dates only, no financials/committee) is wanted later, it needs its own explicitly-scoped endpoint/resource — not a permission bypass on the real one.
- `PresidentSeeder`'s default seeded president has no `Member` row — several member-dependent code paths (e.g. `committeeRoleFor`) silently degrade to "no assignment" for this exact account. Use `php artisan app:create-bureau-accounts` for realistic testing.
- `ProjectResource` runs 2 sum queries plus `pendingPhaseRequest()` and (as of this session) `pendingDeletionRequest()` per project on every list request (N+1 on large project lists) — not addressed.
- `Project::update` (`PUT /projects/{id}`) can still set `budget` directly for anyone who passes `ProjectPolicy::update` (president/vice-president/committee leader) — the Fund Allocations flow is the *recommended* path (it validates against available funds and requires proof, and no longer touches `budget` at all) but nothing at the model/DB level prevents a direct budget edit from bypassing it.
- `ProjectReport.summary` is a JSON snapshot taken at close time — if a project's donations/expenses/allocations were somehow altered after closing (shouldn't normally happen once `status = 'completed'` locks further writes — see Project Hard Lock — but nothing enforces immutability at the DB level directly), the stored `summary` would drift from what a fresh recomputation would show. The live `available_funds` figure doesn't read from this snapshot, so it stays correct regardless. Also, `returned_to_pool` in that snapshot sums donations+allocations−expenses **unclamped** and doesn't distinguish the two sources — it can legitimately show a larger number than what actually flows into `available_funds`'s allocation/donation split (both now do flow in fully via `FundsHelper`, so this is now just a "sum vs breakdown" cosmetic difference in the PDF, not a discrepancy in what's actually credited).
- `ExpenseController::index` has no `?status=` query param (see API Endpoints above) — the frontend filters client-side instead. Revisit if a future page needs status-filtered expenses without also needing the full list in memory.
- No way to move a project backward in the lifecycle (e.g. `active` back to `funding_ready`) or to un-cancel one — `ProjectLifecycle::canManuallySetStatus()` only allows cancelling forward-of-terminal stages or a same-value no-op; a mis-clicked "Start Project" or a mistaken cancellation has no undo path short of a direct DB edit.
- No cross-origin download forcing: invoice/receipt/proof file URLs are served as plain static assets (`asset('storage/...')`); the frontend's invoice preview "Download" button relies on the HTML `download` attribute, which some browsers only honor same-origin — this is a browser platform limitation, not something fixable without proxying the file through a same-origin endpoint.
- A pending `ProjectPhaseRequest` on a project that gets closed/cancelled directly (bypassing the phase workflow) is never auto-resolved — it just sits as `pending` forever, since `ProjectPhaseRequestPolicy::review` denies once the project is locked. Same for a leader's next request on a now-locked project: `StoreProjectPhaseRequestRequest`'s `withValidator` sequential-order check runs (and fails, 422) before the controller's own lock-aware policy check ever executes, so the error message says "phases advance one step at a time" rather than "this project is read-only" — the request is correctly rejected either way, just with a slightly misleading message in that specific edge case.
- `project.phase` and `project.status` are two independent columns with no cross-validation beyond `close()` setting both to `completed` together — nothing stops (or would even notice) a project sitting at `status: active, phase: completed` if a future code path ever set phase without going through `close()`. Not currently possible via any existing endpoint, but worth knowing if this area is extended.
- **Self-registered `abonne` accounts (`POST /register`) still have a real password and were deliberately left untouched** by the Member Portal work — that endpoint wasn't mentioned in the task describing the Member Portal, which explicitly frames the workflow as "bureau creates subscriber". A self-registered user can still use bureau email/password login (subject to whatever the existing `abonne` policies already allow); they'd *also* get Member Portal passkey access the moment a bureau member verifies one of their subscriptions, same as anyone else — the two access paths aren't mutually exclusive, they're just independent. If subscriber password login should be removed entirely, that's a separate, larger product decision not made here.
- **`outstanding_balance` on `GET /member/dashboard` is still the old derived approximation, kept for backward compatibility** — a real dues/billing-ledger model now exists (`dues`/`Due`, see Annual Dues above); this specific field was deliberately left computing the same subscription-only formula it always did rather than rewired, since changing an existing response field's meaning was avoided in favor of adding `dues_history` alongside it. See Member Portal above for the exact formula.
- No admin UI surfaces `has_portal_access`/passkey status beyond the new `MemberResource` field — a bureau member currently has no way to, say, manually resend a passkey without going through Subscription verify/forgot-passkey; not asked for, not built.
- **No un-waive action for a Due** — once `waived_reason` is set, `recalculate()` treats it as sticky forever; there's no endpoint to clear it and let payment-driven recalculation resume. If a due is waived by mistake, the only fix today is a direct DB edit (`waived_reason = null`, then trigger any recalculation).
- **No manual due create/edit/delete endpoint** — deliberate (see Annual Dues above, "no dead code"), but means a due with a wrong `amount_due` (e.g. the association's annual amount changed mid-year, or a specific member's fee should genuinely differ from the default) can't be corrected through the API at all today, only by direct DB edit or by waiving and manually tracking the difference elsewhere.
- **`DuesService::markOverdue()` is member-notification-only for the overdue *transition*** — a due that's been sitting `overdue` for months only gets the one notification from the run that first crossed it into that status (by design, matching `ExpireSubscriptions`'s exact idempotency precedent — see above), never a repeat/escalating reminder the way subscription *expiry* gets 3 separate advance-warning notifications. If recurring overdue reminders are wanted later, that's a new, separate reminder pass, not a fix to this one.
- Dues generation (`generateForYear`) iterates every `Member` regardless of role — a bureau officer gets an annual due exactly like a plain subscriber, matching how the pre-existing Subscription system already treats every `Member` row identically (no bureau/subscriber distinction there either). Not revisited as part of this feature; flag if bureau accounts should be exempt from dues.
- Keep this file, `backend/DATABASE.md`, and `frontend/API_CONTRACT.md`/`frontend/FRONTEND_SUMMARY.md` in sync when endpoints, policies, or shapes change.
