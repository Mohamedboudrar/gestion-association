# Association Management Platform

A French-first platform for managing an association's subscribers, annual dues, projects, committees, finances, reporting, and governance. It is a decoupled React single-page application backed by a Laravel REST API.

## What it does

- Manages subscribers, their profiles, subscriptions, receipts, annual dues, and passkey access.
- Runs projects through a controlled lifecycle: committee assignment, funding, execution phases, closure, and stored closure reports.
- Records project donations, expenses, invoices, fund allocations, and approval workflows.
- Provides role-aware dashboards, an action center, notification center, activity explorer, and PDF/Excel reports.
- Applies granular server-side authorization for eight association roles, with self-scoped views for subscribers.
- Centralizes association identity, contact details, logo, annual subscription amount, and currency in Settings.

## Technology

| Area | Stack |
| --- | --- |
| Frontend | React 19, Vite 8, React Router 7, Tailwind CSS 4, Axios, i18next, Recharts |
| Backend | PHP 8.3, Laravel 13, Sanctum, Spatie Permission, Spatie Activitylog |
| Data | MySQL in local development (Laravel also supports its standard configured drivers) |
| Reports | Dompdf for PDF and Laravel Excel for XLSX |
| Quality | Pest 4 / PHPUnit 12, Laravel Pint, Oxlint |

## Roles

The system supports `president`, `vice-president`, `tresorier`, `vice-tresorier`, `secretaire-general`, `vice-secretaire-general`, `conseiller`, and `abonne`.

Bureau members also retain the `abonne` role. Permissions are enforced in Laravel policies; the interface adapts to the authenticated user's roles and committee assignments, but never replaces server-side authorization.

## Architecture

```text
frontend/  React + Vite SPA
    │  HTTPS / JSON API (Bearer token)
    ▼
backend/   Laravel REST API
    │
    ▼
MySQL      Association, finance, project, and audit data
```

- `frontend/` contains the SPA, API client, role-aware layouts, pages, localization, and reusable UI.
- `backend/` contains the API, policies, Eloquent models, database migrations/seeders, exports, report views, scheduled commands, and Pest tests.
- `backend/DATABASE.md` contains the detailed schema and ER diagram.

## Core workflows

### Subscribers and annual dues

Subscribers can be created and managed by authorized bureau members. Verified subscription payments can be linked automatically to an annual due; dues move through `pending`, `partial`, `paid`, `overdue`, and `waived` states. Daily and annual Artisan commands handle expiry, overdue checks, and yearly due generation.

Subscribers authenticate with a passkey flow that results in the same Sanctum-backed, permission-driven application session as email/password login.

### Projects and committees

Projects use separate lifecycle and execution-phase state machines. A committee can be assigned to a project, with an append-only assignment history. Committee members work only within their assigned projects and roles; terminal projects are hard-locked. Project phase changes and project deletion requests follow review workflows, and closure produces a stored financial report.

### Finance, notifications, and reporting

Donations and expenses use draft, submission, approval/rejection, and payment flows with supporting receipt or invoice uploads. Approved records drive financial totals and budget validation. The platform provides role-aware notifications, an activity explorer, project-level fund allocation records, and PDF/XLSX exports.

## Getting started

### Prerequisites

- PHP 8.3+ and Composer
- Node.js 20+ and npm
- MySQL 8+ (recommended for the documented local setup)

### 1. Configure and start the backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Set your MySQL connection in `backend/.env`:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=association_db
DB_USERNAME=root
DB_PASSWORD=
```

Then create the database, run the schema and seeders, and expose public uploads:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

The API is then available at `http://localhost:8000/api`. The seeded development president is `president@association.com` with password `password123`; change or remove this account outside local development.

In another terminal, run scheduled work when needed:

```bash
cd backend
php artisan queue:listen --tries=1 --timeout=0
php artisan schedule:work
```

### 2. Configure and start the frontend

```bash
cd frontend
npm install
cp .env.example .env.local
npm run dev
```

The SPA starts at `http://localhost:5173`. It defaults to `http://localhost:8000/api`; set `VITE_API_BASE_URL` in `frontend/.env.local` only when the API runs elsewhere.

`VITE_TOMTOM_API_KEY` is optional but required for the interactive project location picker and viewer. Without it, the application shows a safe unavailable-state instead of a map.

### Optional email configuration

Subscriber welcome and passkey-reset messages use MailerSend when `MAILERSEND_API_KEY`, `MAILERSEND_FROM_EMAIL`, and `MAILERSEND_FROM_NAME` are configured in `backend/.env`. In local development, leaving these values unset causes the service to log rather than send the messages.

## Common commands

Run these from the indicated directory.

| Directory | Command | Purpose |
| --- | --- | --- |
| `backend` | `composer test` | Run the Pest/PHPUnit suite |
| `backend` | `composer test-coverage` | Generate coverage report (minimum 80%) |
| `backend` | `./vendor/bin/pint` | Format PHP code |
| `backend` | `composer dev` | Start Laravel server, queue listener, logs, and Vite for backend assets |
| `backend` | `php artisan app:generate-annual-dues` | Generate annual dues (optional year argument) |
| `backend` | `php artisan app:mark-overdue-dues` | Mark overdue annual dues |
| `backend` | `php artisan app:expire-subscriptions` | Process subscription expiration and reminders |
| `frontend` | `npm run lint` | Run Oxlint |
| `frontend` | `npm run build` | Build the production SPA |
| `frontend` | `npm run preview` | Preview the production build |

## Documentation

- [Backend summary](backend/BACKEND_SUMMARY.md) — API behavior, workflows, policies, commands, localization, notifications, and implementation notes.
- [Database summary](backend/DATABASE_SUMMARY.md) — tables, relationships, enums, indexes, and integrity rules.
- [Database schema and ER diagram](backend/DATABASE.md) — column-level schema reference.
- [Frontend summary](frontend/FRONTEND_SUMMARY.md) — routes, pages, API modules, contexts, role capabilities, and UI behavior.
- [Frontend API contract](frontend/API_CONTRACT.md) — endpoint response-shape conventions and frontend integration notes.
- [Project brief](project.md) — original association-management requirements.

## Security and operational notes

- Do not commit `.env`, API keys, database credentials, or production tokens.
- Run `php artisan storage:link` before relying on uploaded receipt, invoice, proof, or report URLs.
- Configure a production scheduler to run Laravel's scheduler every minute and a queue worker for queued work.
- The current user-facing language and backend locale are French; keep new user-facing copy localized accordingly.
