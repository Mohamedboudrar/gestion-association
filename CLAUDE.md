# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Association management platform for an association that needs to manage members/subscribers, projects, subscriptions/donations, and association governance. The system is built as a decoupled SPA + REST API.

- **Backend**: Laravel 13 (PHP 8.3) — REST API, Sanctum auth, Spatie roles/permissions, activity log
- **Frontend**: React 19 + Vite 8, Tailwind CSS 4, React Router 7, Axios, Recharts, Oxlint
- **Database**: MySQL (per `.env`) — local dev name `association_db`, root/rootroot
- **Default seeded president**: `president@association.com` / `password123` (role `president`)

## Common Commands

### Backend (`/backend`)

```bash
# Install / setup
composer install
php artisan key:generate
php artisan migrate --seed          # creates schema + roles/permissions + president user
php artisan storage:link            # exposes storage/app/public via public/storage (needed for receipt/document URLs)

# Run dev server
php artisan serve                   # http://localhost:8000
php artisan queue:listen --tries=1 --timeout=0
php artisan pail --timeout=0        # tail logs

# Or run all four in parallel
composer dev

# Tests (PHPUnit)
composer test                       # = php artisan test
php artisan test --filter=ClassName # single class
php artisan test --filter=test_method # single test

# Code style
./vendor/bin/pint                   # Laravel Pint (PSR-12 style)
```

### Frontend (`/frontend`)

```bash
npm install
npm run dev                         # vite dev server
npm run build                       # production build → dist/
npm run preview                     # preview built bundle
npm run lint                        # oxlint
```

### Pointing the frontend at the backend

`frontend/src/api/http.js` reads `VITE_API_BASE_URL` and defaults to `http://localhost:8000/api`. For local dev, the default is correct. Create `frontend/.env.local` to override per machine.

## Architecture

### Backend layers

```
backend/app/
├── Http/
│   ├── Controllers/Api/    # REST endpoints, one per resource
│   ├── Requests/           # FormRequest classes for validation (Store*/Update*)
│   ├── Resources/          # JsonResource classes — response shape
│   └── Middleware/         # (none custom yet)
├── Models/                 # Eloquent models. Member, Project, Subscription, Donation, Document, User
├── Policies/               # MemberPolicy, ProjectPolicy, etc. — gate every authorize() call
├── Exports/                # Maatwebsite/Excel export classes
└── Helpers/

database/
├── migrations/             # 14 migrations. Latest: donations + project_id required, member_project pivot responsibility fields
├── seeders/                # DatabaseSeeder → RoleSeeder → PermissionSeeder → RolePermissionSeeder → PresidentSeeder
└── factories/              # only UserFactory exists
```

Routes live in `routes/api.php` and are prefixed `/api`. Almost every route is inside the `auth:sanctum` middleware group. Exceptions: `POST /api/register` and `POST /api/login`.

### Frontend layout

```
frontend/src/
├── api/                    # One *.api.js per backend domain. All use shared `http` axios client.
│   └── http.js             # Axios instance + Bearer token interceptor
├── context/
│   ├── auth-context.js     # React Context object
│   └── AuthContext.jsx     # AuthProvider — login/logout, bootstrap, persistAuth
├── lib/
│   ├── authStorage.js      # localStorage vs sessionStorage (remember-me)
│   └── roles.js            # getUserRoles, isPresidentUser, isCommitteeWorkspaceUser
├── routes/ProtectedRoute.jsx  # Auth gate + boot loader
├── config/presidentNavigation.js  # Nav structure for president vs committee workspace
├── components/{layout,dashboard,committee}/   # PresidentLayout, dashboard charts, etc.
├── pages/                  # One page per route. MembersPage, SubscriptionsPage, ProjectsPage accept a `mode` prop (e.g. "all"/"pending"/"receipts") and share a single component.
└── hooks/                  # useDashboard, useCommitteeProjects
```

### Key design choices

- **Resource-based response shape**: most controllers return API Resources, so list responses are `{ "data": [...] }` and item responses are `{ "data": {...} }`. Frontend API helpers unwrap with `data.data ?? data`. `GET /api/me`, project members, and activity logs return raw JSON — see `frontend/API_CONTRACT.md` for the full list of exceptions.
- **Auth**: Sanctum personal access tokens. `AuthController` returns `{ user, token }` on login/register. The token is stored client-side by `lib/authStorage.js` (localStorage if "remember me", otherwise sessionStorage) and sent via the axios interceptor in `api/http.js`.
- **Authorization**: every controller calls `$this->authorize(...)` against a `*Policy` class. Policies check Spatie permissions like `members.view`, `subscriptions.verify`. Add new permissions in `PermissionSeeder` and assign them to roles in `RolePermissionSeeder`.
- **Roles** (8 total, defined in `RoleSeeder`): `president`, `vice-president`, `tresorier`, `vice-tresorier`, `secretaire-general`, `vice-secretaire-general`, `conseiller`, `abonne`. `president` has the broadest access. New public signups default to `abonne` (`AuthController::register`).
- **Two workspaces**: the frontend splits users into president vs committee workspace. `isPresidentUser(user)` toggles navigation between `presidentNavigation` and `committeeNavigation` and changes the dashboard scope. Committee users see only projects they're assigned to (filtered server-side in `ProjectController::index` and `DonationController::index`).
- **Activity log**: `spatie/laravel-activitylog`. Models `Member`, `Project`, `Subscription`, `Donation` use the `LogsActivity` trait and log fillable + dirty changes. Read via `GET /api/activity-logs` (paginated, filtered for non-presidents).
- **File uploads**: receipts (subscriptions, donations) and project documents land in `storage/app/public/` on the `public` disk. URLs are returned as `asset('storage/...')`. The `php artisan storage:link` symlink must exist for those URLs to resolve.
- **Reports**: `ReportController` returns PDF (`barryvdh/laravel-dompdf`) and XLSX (`maatwebsite/excel`) streams. Frontend uses `responseType: 'blob'` for these.

### Important contracts and conventions

- **Subscription resource is intentionally trimmed** — see the `SubscriptionResource` and the note in `frontend/API_CONTRACT.md`: it does NOT currently expose `payment_method`, `receipt_number`, `receipt_file`, `notes`, `verified_by`, `verified_at`. Add fields by editing `SubscriptionResource` and updating the contract doc.
- **Project resource returns `members_count` not a member list.** Use `GET /api/projects/{id}/members` to get members.
- **Donations now require `project_id`** (`2026_07_13_100000_make_project_id_required_in_donations` migration) — the field is non-null in the DB even though the migration originally made it nullable.
- **Pivot table `member_project`** has been extended with `responsibility` and `assigned_by`/`assigned_at` columns by `2026_07_13_160000_add_responsibility_fields_to_member_project`.
- **French module copy**: the app copy in the frontend nav and module placeholders is in French (e.g. "Tableau de bord", "Adhérents"). Keep new user-facing strings in French to match.
- **Module placeholders**: many routes in `App.jsx` (members/board, subscriptions/receipts, financial-operations, settings/*, etc.) currently render `ModulePlaceholderPage` — they have a working URL but no real UI yet. The `backendStatus` field in the `moduleRoutes` array tracks which ones have backend support.

### Where to add a new resource (e.g. "Expenses")

1. Migration in `database/migrations/`, model in `app/Models/`, factory in `database/factories/`.
2. `FormRequest` classes (`Store*Request`, `Update*Request`) under `app/Http/Requests/`.
3. `JsonResource` under `app/Http/Resources/` for the response shape.
4. `Policy` under `app/Policies/` and add permission strings to `PermissionSeeder`, role grants to `RolePermissionSeeder`.
5. Controller under `app/Http/Controllers/Api/` using `$this->authorize(...)` and `Resource` responses.
6. Register the route inside the `auth:sanctum` group in `routes/api.php`.
7. Update `frontend/API_CONTRACT.md` with the new endpoints.
8. Add `*.api.js` under `frontend/src/api/`, a page under `pages/`, register the route in `App.jsx`, and add a nav entry to `config/presidentNavigation.js` if it should be visible to the president.
