# Frontend API Contract

This contract documents the API that currently exists in the backend and should be treated as the source of truth for frontend integration.

## Base Rules

- Base API prefix: `/api`
- Auth: Laravel Sanctum personal access token
- Protected routes require header: `Authorization: Bearer <token>`
- Default content type: `application/json`
- File upload endpoints must use `multipart/form-data`

## Authentication

### `POST /api/register`

Creates a user and returns an auth token.

Request body:

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "password123",
  "password_confirmation": "password123"
}
```

Response `201`:

```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "created_at": "2026-07-07T10:00:00.000000Z",
    "updated_at": "2026-07-07T10:00:00.000000Z"
  },
  "token": "..."
}
```

### `POST /api/login`

Request body:

```json
{
  "email": "john@example.com",
  "password": "password123"
}
```

Response `200`:

```json
{
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "created_at": "2026-07-07T10:00:00.000000Z",
    "updated_at": "2026-07-07T10:00:00.000000Z"
  },
  "token": "..."
}
```

Error `401`:

```json
{
  "message": "Invalid credentials"
}
```

### `GET /api/me`

Returns the authenticated user object with roles loaded.

Response `200`:

```json
{
  "id": 1,
  "name": "John Doe",
  "email": "john@example.com",
  "created_at": "2026-07-07T10:00:00.000000Z",
  "updated_at": "2026-07-07T10:00:00.000000Z",
  "roles": [
    {
      "id": 1,
      "name": "president",
      "guard_name": "web",
      "created_at": "2026-07-07T10:00:00.000000Z",
      "updated_at": "2026-07-07T10:00:00.000000Z",
      "pivot": {
        "model_type": "App\\Models\\User",
        "model_id": 1,
        "role_id": 1
      }
    }
  ]
}
```

### `POST /api/logout`

Response `200`:

```json
{
  "message": "Logged out"
}
```

## Passkey Login for Subscribers

A second way to authenticate, alongside the email/password login above — issues the exact same kind of Sanctum token against the exact same `User` model, so it's consumed by the exact same frontend session (`AuthContext`) and lands on the same unified `/dashboard`. A subscriber never has a password they know; see `backend/BACKEND_SUMMARY.md`'s "Passkey Authentication for Subscribers" section for the full issuance/verification workflow. **There is no separate "Member Portal" application anymore** — the routes below are just a second login method plus a self-scoped data endpoint (`GET /member/dashboard`) the unified frontend reuses for a plain subscriber's Dashboard summary and Profile page; they sit in the same `auth:sanctum` group as every other endpoint below. (There is no "My Donations" view for subscribers — see the Donations section's access notes below.)

### `POST /api/member/login`

Public. Request body:

```json
{ "passkey": "123456" }
```

`passkey` must be exactly 6 digits. Response `200` (same shape as `POST /api/login`):

```json
{
  "user": { "id": 1, "name": "Jane Doe", "email": "jane@example.com", "...": "..." },
  "token": "1|abc123..."
}
```

`401` with a generic `{"message": "Invalid passkey."}` if the passkey doesn't match any user — deliberately identical whether it was well-formed-but-wrong or simply didn't belong to anyone. `429` after 5 failed attempts from the same IP within 15 minutes.

### `POST /api/member/forgot-passkey`

Public. Request body:

```json
{ "email": "jane@example.com" }
```

Always responds `200` with the same generic message, regardless of whether the email exists, belongs to a still-pending (never verified) subscriber, or belongs to a fully verified one:

```json
{ "message": "If that email is registered and verified, a new passkey has been sent." }
```

Only for an already-portal-enabled subscriber does this actually issue a new passkey (invalidating the old one) and email it. `429` after 3 requests from the same IP within 15 minutes.

### `GET /api/member/dashboard`

Requires a valid Sanctum token (from either login path). Always scoped to the caller's own `Member` record — there is no id parameter. Response `200`:

```json
{
  "profile": {
    "name": "Jane Doe",
    "email": "jane@example.com",
    "phone": "0600000000",
    "address": "Casablanca"
  },
  "membership_status": "verified",
  "subscription_status": "verified",
  "current_subscription": {
    "id": 12,
    "amount": "100.00",
    "status": "verified",
    "payment_date": "2026-07-01",
    "expires_at": "2027-07-01",
    "receipt_url": null
  },
  "expiration_date": "2027-07-01",
  "outstanding_balance": 0,
  "subscription_history": [ "...same shape as /api/subscriptions..." ],
  "donation_history": [ "...same shape as /api/donations..." ]
}
```

`current_subscription`/`subscription_history` entries use the exact same shape as the `Subscriptions` section below; `donation_history` entries use the exact same shape as `Donations`. `outstanding_balance` is a derived figure (0 while the current subscription is verified and unexpired, otherwise that subscription's amount) — this app has no separate dues/billing ledger, see backend docs. `404` if the authenticated user has no `Member` profile at all.

**Removed** (this session's App Unification): `GET /api/member/projects` and `GET /api/member/project-reports/{id}/download` — a subscriber's "My Projects" now reuses the standard `GET /api/projects` (already scoped to committee-assigned projects for a non-bureau caller) and `GET /api/projects/{id}` / `GET /api/projects/{id}/reports` / `GET /api/project-reports/{id}/download` below, same as every bureau user. The one thing this required: `ProjectReportPolicy` (`viewAny`/`view`, gating the last two) now also accepts `CommitteeAssignment` history, not just the live `member_project` pivot — closing a project detaches that pivot in the same transaction that generates the report, so without this fix a former committee member would `403` trying to see their own completed project's report. See `backend/BACKEND_SUMMARY.md`'s "App Unification" section.

## Members

Member responses are wrapped by Laravel resource collections:

```json
{
  "data": [
    {
      "id": 1,
      "user": {
        "id": 3,
        "name": "Jane Doe",
        "email": "jane@example.com"
      },
      "phone": "0600000000",
      "address": "Casablanca",
      "is_bureau_member": false,
      "has_verified_subscription": true,
      "has_portal_access": true,
      "created_at": "2026-07-07T10:00:00.000000Z"
    }
  ]
}
```

`has_portal_access` (new — Member Portal feature) is `true` once this subscriber has been issued a Member Portal passkey (i.e. a subscription has been verified for them at least once); `false` for a newly created, not-yet-verified subscriber.

### `GET /api/members`

Response `200`: resource collection in `{ "data": [...] }`

### `POST /api/members`

Request body — **no `password` field** (removed as of the Member Portal feature; subscribers created here never sign in with email/password, only a Member Portal passkey issued once a subscription is verified, see Member Portal section above):

```json
{
  "name": "Jane Doe",
  "email": "jane@example.com",
  "phone": "0600000000",
  "address": "Casablanca"
}
```

Response `200`:

```json
{
  "data": {
    "id": 1,
    "user": {
      "id": 3,
      "name": "Jane Doe",
      "email": "jane@example.com"
    },
    "phone": "0600000000",
    "address": "Casablanca",
    "created_at": "2026-07-07T10:00:00.000000Z"
  }
}
```

### `GET /api/members/{id}`

Response `200`: same member resource shape

### `PUT /api/members/{id}`

Allowed fields:

```json
{
  "phone": "0611111111",
  "address": "Rabat"
}
```

Response `200`: same member resource shape

### `DELETE /api/members/{id}`

Response `200`:

```json
{
  "message": "Member deleted"
}
```

## Subscriptions

Collection/item resource shape (`SubscriptionResource`) — includes `payment_method`, `receipt_number`, `receipt_file`, `receipt_url`, and `notes` directly; the only field it omits is `verified_by` (there is no `verifier` object either). As of the Annual Dues system, it also carries a `due` summary object (present whenever the payment is linked to a due — see [Annual Dues](#annual-dues) below; `null` for subscriptions created before this system existed and never backfilled):

```json
{
  "data": {
    "id": 1,
    "member": {
      "id": 1,
      "user_id": 3,
      "name": "Jane Doe"
    },
    "amount": "300.00",
    "payment_method": "cash",
    "receipt_number": "RCPT-001",
    "receipt_file": null,
    "receipt_url": null,
    "status": "pending",
    "payment_date": "2026-07-01",
    "expires_at": "2027-07-01",
    "notes": "Annual fee",
    "verified_at": null,
    "due": {
      "id": 5,
      "year": 2026,
      "status": "partial",
      "balance": 200
    }
  }
}
```

### `GET /api/subscriptions`

Response `200`: resource collection in `{ "data": [...] }`

### `POST /api/subscriptions`

Request body:

```json
{
  "member_id": 1,
  "due_id": null,
  "amount": 300,
  "payment_method": "cash",
  "receipt_number": "RCPT-001",
  "receipt_file": null,
  "payment_date": "2026-07-01",
  "expires_at": "2027-07-01",
  "notes": "Annual fee"
}
```

`due_id` is optional and almost never needs to be sent explicitly: when omitted, the backend auto-links the payment to the member's due for the payment's year (creating that due on the fly via the default `annual_subscription_amount` if it doesn't exist yet). Pass it explicitly only if you already know the exact due this payment should apply to.

Response `200`: subscription resource shape above

### `GET /api/subscriptions/{id}`

Response `200`: subscription resource shape above

### `PUT /api/subscriptions/{id}`

Allowed fields are partial updates of:

```json
{
  "member_id": 1,
  "amount": 300,
  "payment_method": "cash",
  "receipt_number": "RCPT-001",
  "receipt_file": null,
  "payment_date": "2026-07-01",
  "expires_at": "2027-07-01",
  "notes": "Updated note"
}
```

Response `200`: subscription resource shape above

### `DELETE /api/subscriptions/{id}`

Response `200`:

```json
{
  "message": "Subscription deleted"
}
```

### `POST /api/subscriptions/{id}/verify`

Marks the subscription as verified.

Request body: none

Response `200`: subscription resource shape above with `status = "verified"`

### `POST /api/subscriptions/{id}/receipt`

Uploads or replaces a receipt file for the subscription.

Request body: `multipart/form-data`

- `receipt`: required file
- Allowed types: `pdf`, `jpg`, `jpeg`, `png`
- Max size: `5120 KB`

Response `200`: subscription resource shape above

Possible error `403`:

```json
{
  "message": "You are not allowed to upload a receipt for this subscription."
}
```

## Annual Dues

A per-member, per-year billing ledger layered on top of Subscriptions — extends the payment system rather than replacing it. A `Due` (`member_id` + `year`, unique) tracks `amount_due`/`amount_paid`/`balance`/`status`; one or more `Subscription` payments can apply toward the same due via `Subscription.due_id` (see the `due` field on the subscription resource above). `amount_paid`/`balance`/`status` are recomputed automatically — from the due's linked `verified` payments — every time a linked payment is verified, edited, deleted, or has its receipt replaced; there is no manual "record a due payment" endpoint, you record a `Subscription` payment as usual and it flows through.

Status enum: `pending` (no payment yet) · `partial` (some payment, balance remaining) · `paid` (balance is 0) · `overdue` (due date passed with a balance still owed) · `waived` (manually excused by président/trésorier/vice-trésorier — sticky; payment activity no longer changes it).

Dues are generated by `php artisan app:generate-annual-dues {year?}` (idempotent — safe to re-run, scheduled yearly on Jan 1st) or on-demand the first time a payment is made for a member+year that has no due yet. Overdue detection runs daily via `php artisan app:mark-overdue-dues`.

Resource shape (`DueResource`):

```json
{
  "data": {
    "id": 5,
    "member": {
      "id": 1,
      "user_id": 3,
      "name": "Jane Doe"
    },
    "year": 2026,
    "amount_due": 300,
    "amount_paid": 100,
    "balance": 200,
    "status": "partial",
    "due_date": "2026-12-31",
    "paid_at": null,
    "waived_reason": null,
    "created_at": "2026-01-05T10:00:00.000000Z"
  }
}
```

### `GET /api/dues`

Query params (all optional): `member_id`, `year`, `status`. A plain `abonne` always sees only their own dues (query params other than the implicit self-scope are ignored for them, same rule as `GET /api/subscriptions`); any bureau role sees every due and may filter with the params above.

Response `200`: resource collection in `{ "data": [...] }`

### `GET /api/dues/{id}`

Response `200`: resource shape above. `403` unless the caller is that due's own member or a bureau role.

### `POST /api/dues/{id}/waive`

Président/trésorier/vice-trésorier only (same tier as `subscriptions/{id}/verify`).

Request body:

```json
{
  "reason": "Financial hardship, approved by the board."
}
```

Response `200`: resource shape above with `status: "waived"` and `waived_reason` set. `422` if `reason` is missing.

### Reports

`GET /api/reports/dues` (PDF) and `GET /api/reports/dues/excel` — same bureau-only gating as the other report endpoints, with a summary (expected/collected/outstanding/collection rate) plus one row per due.

### Dashboard

`GET /api/dashboard`'s response includes an additive `dues` object (current-year scoped, alongside the existing `subscriptions`/`available_funds` figures, which are unchanged):

```json
{
  "dues": {
    "year": 2026,
    "expected": 4500,
    "collected": 3100,
    "outstanding": 1200,
    "overdue_members": 2,
    "collection_rate": 68.9
  }
}
```

### Member Portal

`GET /api/member/dashboard`'s response gains an additive `dues_history` array (every due for the caller's own member, newest year first) — `outstanding_balance` is unchanged for backward compatibility (it's still the older subscription-only derivation; `dues_history` is the accurate per-year ledger).

## Donations

Donation resource shape:

```json
{
  "id": 1,
  "project_id": 1,
  "donor_name": "John Doe",
  "amount": "5000.00",
  "payment_method": "cash",
  "receipt_number": "RCPT-001",
  "receipt_file": "donation-receipts/abc123.pdf",
  "receipt_url": "http://localhost/storage/donation-receipts/abc123.pdf",
  "donation_date": "2026-07-01",
  "notes": "Annual contribution",
  "status": "pending",
  "member": {
    "id": 1,
    "name": "Jane Doe"
  },
  "project": {
    "id": 1,
    "name": "Community Cleanup"
  },
  "recorded_by": {
    "id": 1,
    "name": "Admin User"
  },
  "approved_by": null,
  "approved_at": null,
  "rejected_by": null,
  "rejected_at": null,
  "rejection_reason": null,
  "rejection_type": null,
  "created_at": "2026-07-07T10:00:00.000000Z"
}
```

`donor_name` is the member's name when `member_id` was provided, otherwise the free-text donor name. `status` is one of `draft`, `pending`, `approved`, `rejected` — see Donation Approval Workflow in `backend/BACKEND_SUMMARY.md` for the full state machine (deliberately identical to the Expense workflow below, minus the `paid` state). `approved_by`/`rejected_by` are `{ id, name }` objects (null until set). **Only `approved` donations count toward a project's `collected`/`remaining` totals, `available_funds` and reports/dashboard figures** — draft, pending, and rejected donations never increase collected funds.

`rejection_type` is only set once `status = rejected`: `"receipt"` (leader can Replace Receipt + Submit Again — same row returns to `pending`) or `"details"` (donation becomes permanently read-only; leader must `POST /api/donations` a brand-new one).

### `GET /api/donations`

Response `200`: resource collection in `{ "data": [...] }`

Note: President/trésorier/vice-trésorier see every donation. Everyone else is scoped to projects where they hold the `leader`/`treasurer` committee role — **not** every committee assignment. `403` for a plain committee member (any other committee role) or an unassigned subscriber; that audience can still see a project's aggregate `collected`/`budget`/`expenses`/`remaining` via `GET /api/projects/{id}`, just not individual donation records/donor names. No `?status=` filter — the frontend filters the full list client-side. `?project_id=` filters to one project (still subject to the same scoping).

### `POST /api/donations`

Request body:

```json
{
  "member_id": 1,
  "project_id": 1,
  "amount": 5000,
  "payment_method": "cash",
  "receipt_number": "RCPT-001",
  "donation_date": "2026-07-01",
  "notes": "Annual contribution",
  "donor_name": "John Doe"
}
```

Note: Either `member_id` or `donor_name` should be provided. If `member_id` is provided, the member's name will be used automatically. Always created with `status: "draft"` regardless of request body.

Response `200`: donation resource shape above

### `GET /api/donations/{id}`

Response `200`: donation resource shape above

### `PUT /api/donations/{id}`

Allows partial update using the same fields as create. Only permitted while `status` is `draft` or `pending` — 403 once a donation has been decided (approved/rejected).

Response `200`: donation resource shape above

### `DELETE /api/donations/{id}`

Only permitted while `status` is `draft` or `pending`, same as update.

Response `200`:

```json
{
  "message": "Donation deleted successfully."
}
```

### `POST /api/donations/{id}/receipt`

Uploads or replaces a receipt file for the donation. Permitted while `status` is `draft`/`pending`, or on a `rejected` donation with `rejection_type: "receipt"` (the "Replace Receipt" action) — 403 otherwise (e.g. a `details`-rejected donation).

Request body: `multipart/form-data`

- `receipt`: required file
- Allowed types: `pdf`, `jpg`, `jpeg`, `png`
- Max size: `5120 KB`

Response `200`: donation resource shape above

### `POST /api/donations/{id}/submit`

Draft → pending. Also doubles as "Submit Again" for a `rejected` donation with `rejection_type: "receipt"` (→ pending). Committee leader/treasurer only.

Response `200`: donation resource shape above

### `POST /api/donations/{id}/approve`

Pending → approved. President/tresorier/vice-tresorier only; the donation's own recorder cannot approve it.

Response `200`: donation resource shape above

### `POST /api/donations/{id}/reject`

Pending → rejected. Same authority as approve.

Request body:

```json
{
  "reason": "Receipt is illegible",
  "rejection_type": "receipt"
}
```

- `reason`: required string, max 500 chars
- `rejection_type`: required, one of `receipt` | `details`

Response `200`: donation resource shape above

## Expenses

Expense resource shape:

```json
{
  "id": 1,
  "project_id": 1,
  "project": {
    "id": 1,
    "name": "Community Cleanup"
  },
  "created_by": {
    "id": 1,
    "name": "Admin User"
  },
  "supplier_name": "Office Supplies Co",
  "description": "Office supplies for project kickoff",
  "amount": "2500.00",
  "payment_method": "bank_transfer",
  "invoice_number": "INV-001",
  "invoice_file": "expense-invoices/abc123.pdf",
  "invoice_url": "http://localhost/storage/expense-invoices/abc123.pdf",
  "expense_date": "2026-07-01",
  "notes": "Urgent purchase",
  "status": "pending",
  "approved_by": null,
  "approved_at": null,
  "rejected_by": null,
  "rejected_at": null,
  "rejection_reason": null,
  "rejection_type": null,
  "paid_by": null,
  "paid_at": null,
  "created_at": "2026-07-07T10:00:00.000000Z",
  "updated_at": "2026-07-07T10:00:00.000000Z"
}
```

`status` is one of `draft`, `pending`, `approved`, `rejected`, `paid` — see Expense Approval Workflow in `backend/BACKEND_SUMMARY.md` for the full state machine. `approved_by`/`rejected_by`/`paid_by` are `{ id, name }` objects (null until set). **Only `approved` and `paid` expenses count toward a project's `expenses`/`remaining` totals, `available_funds`, and reports/dashboard figures** — draft, pending, and rejected expenses never reduce funds.

`rejection_type` is only set once `status = rejected`: `"invoice"` (leader can Replace Invoice + Submit Again — same row returns to `pending`) or `"details"` (expense becomes permanently read-only; leader must `POST /api/expenses` a brand-new one).

### `GET /api/expenses`

Response `200`: resource collection in `{ "data": [...] }`

Note: Committee members only see expenses for their assigned projects. No `?status=` filter — the frontend filters the full list client-side.

### `POST /api/expenses`

Request body:

```json
{
  "project_id": 1,
  "supplier_name": "Office Supplies Co",
  "description": "Office supplies for project kickoff",
  "amount": 2500,
  "payment_method": "bank_transfer",
  "invoice_number": "INV-001",
  "expense_date": "2026-07-01",
  "notes": "Urgent purchase"
}
```

Always created with `status: "draft"` regardless of request body. `amount` is rejected (422, on the `amount` field) if **either**: it would exceed the project's remaining funds (donations + allocations − existing approved/paid expenses), **or** it would push `draft + pending + approved + paid` expenses on this project over `project.budget` — two independent checks, both must pass; the second one's error message includes the full breakdown (Project Budget / Already Allocated / Remaining Budget / New Total / Exceeded By).

Response `200`: expense resource shape above

### `GET /api/expenses/{id}`

Response `200`: expense resource shape above

### `PUT /api/expenses/{id}`

Allows partial update using the same fields as create. Only permitted while `status` is `draft` or `pending` — 403 once an expense has been decided (approved/rejected/paid). Same two `amount` validations as create, recalculated with this expense's own prior amount excluded from the "already allocated"/"remaining funds" totals.

Response `200`: expense resource shape above

### `DELETE /api/expenses/{id}`

Only permitted while `status` is `draft` or `pending`, same as update.

Response `200`:

```json
{
  "message": "Expense deleted successfully."
}
```

### `POST /api/expenses/{id}/invoice`

Uploads or replaces an invoice file for the expense. Permitted while `status` is `draft`/`pending`, or on a `rejected` expense with `rejection_type: "invoice"` (the "Replace Invoice" action) — 403 otherwise (e.g. a `details`-rejected expense).

Request body: `multipart/form-data`

- `invoice`: required file
- Allowed types: `pdf`, `jpg`, `jpeg`, `png`
- Max size: `5120 KB`

Response `200`: expense resource shape above

### `POST /api/expenses/{id}/submit`

Draft → pending. Also doubles as "Submit Again" for a `rejected` expense with `rejection_type: "invoice"` (→ pending). Committee leader/treasurer only.

Response `200`: expense resource shape above

### `POST /api/expenses/{id}/approve`

Pending → approved. President/tresorier/vice-tresorier only; the expense's own creator cannot approve it.

Response `200`: expense resource shape above

### `POST /api/expenses/{id}/reject`

Pending → rejected. Same authority as approve.

Request body:

```json
{
  "reason": "Invoice is illegible",
  "rejection_type": "invoice"
}
```

- `reason`: required string, max 500 chars
- `rejection_type`: required, one of `invoice` | `details`

Response `200`: expense resource shape above

### `POST /api/expenses/{id}/mark-paid`

Approved → paid. Same authority as approve/reject. Terminal — nothing can follow it.

Response `200`: expense resource shape above

### `POST /api/expenses/import`

New — atomic batch import (replaces posting each row to `POST /api/expenses` one at a time). Requires the same authority as a single create (committee leader/treasurer on the target project). Request body:

```json
{
  "project_id": 1,
  "expenses": [
    {
      "supplier_name": "IKEA",
      "description": "desks",
      "amount": 12000,
      "payment_method": "cash",
      "invoice_number": null,
      "expense_date": "2026-08-01",
      "notes": null
    }
  ]
}
```

Every row needs `supplier_name`, `description`, `amount`, `payment_method`, `expense_date` (`invoice_number`/`notes` optional) — structural validation only, `422` per-field like a normal create if any row fails it. Once every row passes structural validation, the whole batch's summed `amount` is checked against the project's remaining budget in one shot (same rule as a single create's budget check above, applied to the sum) — **all or nothing**: if the batch would exceed the budget, `422` and **nothing is created**, not even the rows that would have individually fit:

```json
{
  "message": "Import exceeds remaining project budget. No expenses were imported.",
  "budget": {
    "project_budget": 100000,
    "already_allocated": 80000,
    "remaining_budget": 20000,
    "new_total": 35000,
    "exceeded_by": 15000,
    "within_budget": false
  }
}
```

On success, every row is created with `status: "draft"` (same as a single create). Response `200`: expense resource collection (`{ "data": [...] }`).

## Projects

Project resource shape:

```json
{
  "data": {
    "id": 1,
    "name": "Community Cleanup",
    "description": "Monthly field activity",
    "start_date": "2026-07-01",
    "end_date": "2026-07-30",
    "budget": "5000.00",
    "status": "active",
    "phase": "planning",
    "progress_percentage": 0,
    "pending_phase_request_id": null,
    "pending_deletion_request_id": null,
    "latitude": 33.5731,
    "longitude": -7.5898,
    "manager": {
      "id": 1,
      "name": "Admin User",
      "email": "admin@example.com"
    },
    "members": [
      {
        "id": 1,
        "user_id": 3,
        "phone": "0600000000",
        "address": "Casablanca",
        "created_at": "2026-07-07T10:00:00.000000Z",
        "updated_at": "2026-07-07T10:00:00.000000Z",
        "user": {
          "id": 3,
          "name": "Jane Doe",
          "email": "jane@example.com"
        },
        "pivot": {
          "project_id": 1,
          "member_id": 1,
          "role": "participant"
        }
      }
    ],
    "created_at": "2026-07-07T10:00:00.000000Z"
  }
}
```

`progress_percentage` is **derived, not stored** — it's computed from `phase` alone (`planning`=0, `preparation`=25, `in_progress`=50, `finishing`=75, `completed`=100) on every response. It is never accepted as request input on any endpoint and changes automatically the moment `phase` changes (e.g. after a phase request is approved, see Project Phase Requests below) — no separate call is needed to refresh it.

### `GET /api/projects`

Response `200`: resource collection in `{ "data": [...] }`

### `POST /api/projects`

Request body:

```json
{
  "name": "Community Cleanup",
  "description": "Monthly field activity",
  "start_date": "2026-07-01",
  "end_date": "2026-07-30",
  "budget": 5000,
  "latitude": 33.5731,
  "longitude": -7.5898,
  "manager_id": 1
}
```

`latitude`/`longitude` are both optional — omit them, or send `null` for both, if no location was picked; either way they're stored as `null`. When present, `latitude` must be between -90 and 90 and `longitude` between -180 and 180 (422 otherwise). No `status` field is accepted here — every new project is created as `draft` regardless of request body. Projects progress through a fixed lifecycle: `draft → committee_ready → funding_ready → active → completed`, plus `cancelled` from any non-terminal stage:

- `draft` — no committee yet; no donations/expenses/allocations.
- `committee_ready` — reached automatically once the first committee member is assigned; still no donations/expenses; fund allocations become possible.
- `funding_ready` — reached automatically once the first fund allocation is recorded; still no donations/expenses.
- `active` — reached via `POST /api/projects/{id}/start` (only from `funding_ready`); donations and expenses can now be created.
- `completed` — reached via `POST /api/projects/{id}/close` (only from `active`); read-only from here on.
- `cancelled` — reached via `PUT /api/projects/{id}` with `{"status": "cancelled"}`, allowed from any non-terminal stage; read-only from here on.

Response `200`: project resource shape above

### `GET /api/projects/{id}`

Response `200`: project resource shape above

### `PUT /api/projects/{id}`

Allows partial update using the same fields as create. The only `status` value this endpoint accepts is `cancelled` (and only from a non-terminal stage) — any other status change is rejected `422` with `{"errors": {"status": ["Cannot change project status from X to Y directly."]}}`. Use `/start`/`/close` below for the `active`/`completed` transitions. A location-only edit — `{"latitude": ..., "longitude": ...}` with no other fields, or `{"latitude": null, "longitude": null}` to clear it — is a valid partial update too.

Response `200`: project resource shape above

### `POST /api/projects/{id}/start`

Manual `funding_ready → active` transition ("Start Project"). Same authority as `/close` (président or that project's committee leader). `403` if the project isn't `funding_ready` or is locked (`completed`/`cancelled`).

Response `200`: project resource shape above

### `POST /api/projects/{id}/close`

Manual `active → completed` transition. `403` if the project isn't `active` or is already locked. Response body is the project resource shape above plus a top-level `report` key holding the freshly generated closure report record (not documented elsewhere in this file yet).

### `DELETE /api/projects/{id}`

**President only.** `403` if the caller isn't président, or if the project is locked (`completed`/`cancelled` — deletion is only allowed for `draft`/`committee_ready`/`funding_ready`/`active`). A committee leader who isn't président gets the same `403` — they must use `POST /api/projects/{id}/deletion-requests` instead (see Project Deletion Requests below). Deletes the project and everything that references it (committee assignments, donations, expenses, fund allocations, reports, phase requests — all cascade at the DB level) plus their uploaded files (invoices/receipts/proofs/reports) from storage. Logs `'Project deleted.'` to the activity log.

Response `200`:

```json
{
  "message": "Project deleted successfully."
}
```

## Project Members

### `GET /api/projects/{projectId}/members`

Response `200`:

```json
[
  {
    "id": 1,
    "user_id": 3,
    "phone": "0600000000",
    "address": "Casablanca",
    "created_at": "2026-07-07T10:00:00.000000Z",
    "updated_at": "2026-07-07T10:00:00.000000Z",
    "user": {
      "id": 3,
      "name": "Jane Doe",
      "email": "jane@example.com",
      "created_at": "2026-07-07T10:00:00.000000Z",
      "updated_at": "2026-07-07T10:00:00.000000Z"
    },
    "pivot": {
      "project_id": 1,
      "member_id": 1,
      "role": "participant"
    }
  }
]
```

### `POST /api/projects/{projectId}/members`

Request body:

```json
{
  "member_id": 1,
  "role": "participant"
}
```

Response `200`:

```json
{
  "message": "Member assigned successfully."
}
```

### `DELETE /api/projects/{projectId}/members/{memberId}`

Response `200`:

```json
{
  "message": "Member removed successfully."
}
```

## Project Phase Requests

Committee leaders request a project's execution phase to advance one step at a time (`planning → preparation → in_progress → finishing → completed`); a president/vice-président approves or rejects. `completed` is never a valid request target — it's set automatically when the project is closed (`POST /api/projects/{id}/close`).

Phase request resource shape:

```json
{
  "data": {
    "id": 1,
    "project_id": 1,
    "project": { "id": 1, "name": "Community Cleanup" },
    "from_phase": "planning",
    "to_phase": "preparation",
    "summary": "Site survey complete, supplies ordered.",
    "notes": "Awaiting delivery confirmation.",
    "status": "pending",
    "requested_by": { "id": 5, "name": "Jane Doe" },
    "requested_at": "2026-07-22T16:22:32.000000Z",
    "reviewed_by": null,
    "reviewed_at": null,
    "rejection_reason": null,
    "proofs": [
      {
        "id": 1,
        "original_name": "site-photo.png",
        "mime_type": "image/png",
        "size": 40213,
        "url": "http://localhost:8000/storage/project-phase-proofs/abc123.png"
      }
    ],
    "created_at": "2026-07-22T16:22:32.000000Z"
  }
}
```

### `GET /api/phase-requests/pending`

Association-wide pending queue, across every project. Requires president or vice-président. Response `200`: resource collection.

### `GET /api/projects/{projectId}/phase-requests`

Full history for one project (never hard-deleted). Response `200`: resource collection, newest-first by `requested_at`.

### `POST /api/projects/{projectId}/phase-requests`

Requires the project's committee leader. Multipart request body:

- `to_phase` — required, must be exactly the project's current phase's next step (`preparation`, `in_progress`, or `finishing` only — never `completed`)
- `summary` — required string
- `notes` — optional string
- `proofs[]` — optional, zero or more files, each `pdf`/`jpg`/`jpeg`/`png` ≤5MB

`422` if the project already has a pending request, or if `to_phase` isn't the exact next phase. `403` if the project is locked (`completed`/`cancelled`) or the caller isn't that project's committee leader.

Response `200`: phase request resource shape above.

### `POST /api/phase-requests/{id}/approve`

Requires president or vice-président; the request must still be `pending`. Sets the request to `approved`, stamps `reviewed_by`/`reviewed_at`, and updates the project's `phase` to the request's `to_phase`.

Response `200`: phase request resource shape above.

### `POST /api/phase-requests/{id}/reject`

Same authority/precondition as approve. Request body:

```json
{ "reason": "Insufficient proof of completed work." }
```

`422` if `reason` is missing or blank. Sets the request to `rejected`, stamps `reviewed_by`/`reviewed_at`/`rejection_reason`. The project's `phase` is left unchanged.

Response `200`: phase request resource shape above.

## Project Deletion Requests

A committee leader can't delete a project directly (see `DELETE /api/projects/{id}` above) — they submit a deletion request instead, which only the président can approve or reject. Approving actually deletes the project; rejecting leaves it unchanged.

Deletion request resource shape:

```json
{
  "data": {
    "id": 1,
    "project_id": 1,
    "project_name": "Community Cleanup",
    "project": { "id": 1, "name": "Community Cleanup", "status": "active" },
    "reason": "Project cancelled by stakeholders.",
    "status": "pending",
    "requested_by": { "id": 5, "name": "Jane Doe" },
    "requested_at": "2026-07-31T23:25:40.000000Z",
    "reviewed_by": null,
    "reviewed_at": null,
    "rejection_reason": null,
    "created_at": "2026-07-31T23:25:40.000000Z"
  }
}
```

`project_id`/`project` become `null` once the project is actually deleted (an approved request) — `project_name` is a snapshot taken at request time, so the history stays readable even then. A rejected request's `project`/`project_id` stay populated, since the project is still alive.

### `GET /api/deletion-requests/pending`

Association-wide pending queue, across every project. Requires président. Response `200`: resource collection.

### `GET /api/projects/{projectId}/deletion-requests`

Full history for one project (never hard-deleted). Response `200`: resource collection, newest-first by `requested_at`.

### `POST /api/projects/{projectId}/deletion-requests`

Requires the project's committee leader. Request body:

```json
{ "reason": "Project cancelled by stakeholders." }
```

`422` if `reason` is missing/blank, or if the project already has a pending deletion request. `403` if the project is locked (`completed`/`cancelled`) or the caller isn't that project's committee leader.

Response `200`: deletion request resource shape above.

### `POST /api/deletion-requests/{id}/approve`

**President only**; the request must still be `pending`. Sets the request to `approved`, stamps `reviewed_by`/`reviewed_at`, then **deletes the project** (same cascade/file-cleanup behavior as `DELETE /api/projects/{id}`) in the same transaction.

Response `200`: deletion request resource shape above (`project`/`project_id` will be `null`).

### `POST /api/deletion-requests/{id}/reject`

Same authority/precondition as approve. Request body:

```json
{ "reason": "Not enough justification yet." }
```

`422` if `reason` is missing or blank. Sets the request to `rejected`, stamps `reviewed_by`/`reviewed_at`/`rejection_reason`. The project is left completely unchanged.

Response `200`: deletion request resource shape above.

## Dashboard

### `GET /api/dashboard`

Response `200`:

```json
{
  "overview": {
    "active_subscribers": 80,
    "board_members": 5,
    "active_projects": 5,
    "collected_this_year": 15000.00,
    "spent_this_year": null,
    "remaining_funds": null,
    "unsupported_metrics": [
      "spent_this_year",
      "remaining_funds"
    ]
  },
  "stats": {
    "members": {
      "value": 120,
      "change": "+10%",
      "trend": "up"
    },
    "projects": {
      "value": 5,
      "change": "+20%",
      "trend": "up"
    },
    "subscriptions": {
      "value": 10,
      "change": "-5%",
      "trend": "down"
    },
    "revenue": {
      "value": 24000.00,
      "change": "+15%",
      "trend": "up"
    }
  },
  "subscriptions": {
    "verified": 80,
    "pending": 10,
    "expired": 20,
    "revenue": 24000.00
  },
  "donations": {
    "total": 45,
    "revenue": 15000.00
  },
  "projects": {
    "total": 12,
    "in_setup": 2,
    "active": 5,
    "completed": 4,
    "cancelled": 1
  },
  "members": {
    "total": 120
  },
  "charts": {
    "donations_by_month": [
      {
        "month": "Jan",
        "donations": 2000.00
      },
      {
        "month": "Feb",
        "donations": 2500.00
      }
    ],
    "revenue_vs_project_budgets": [
      {
        "month": "Jan",
        "revenue": 5000.00,
        "project_budgets": 4000.00
      },
      {
        "month": "Feb",
        "revenue": 6000.00,
        "project_budgets": 5000.00
      }
    ]
  },
  "recent": {
    "member_registrations": [
      {
        "id": 1,
        "name": "Jane Doe",
        "email": "jane@example.com",
        "phone": "0600000000",
        "created_at": "2026-07-07T10:00:00.000000Z"
      }
    ],
    "subscription_payments": [
      {
        "id": 1,
        "subscriber": "Jane Doe",
        "amount": 300.00,
        "status": "verified",
        "payment_date": "2026-07-01",
        "receipt_number": "RCPT-001"
      }
    ],
    "donations": [
      {
        "id": 1,
        "donor_name": "John Doe",
        "amount": 5000.00,
        "project": "Community Cleanup",
        "donation_date": "2026-07-01",
        "receipt_url": "http://localhost/storage/donation-receipts/abc123.pdf"
      }
    ],
    "financial_operations": [],
    "projects": [
      {
        "id": 1,
        "name": "Community Cleanup",
        "status": "active",
        "budget": 5000.00,
        "manager": "Admin User",
        "created_at": "2026-07-07T10:00:00.000000Z"
      }
    ]
  },
  "notifications": [
    {
      "type": "pending_subscriptions",
      "title": "Pending subscriptions require review",
      "count": 10
    },
    {
      "type": "unfinished_projects",
      "title": "Projects still in progress",
      "count": 7
    },
    {
      "type": "missing_reports",
      "title": "Missing reports",
      "count": null,
      "requires_endpoint": true
    }
  ]
}
```

## Reports

All report endpoints return file downloads, not JSON.

### PDF

- `GET /api/reports/members`
- `GET /api/reports/subscriptions`
- `GET /api/reports/projects`

### Excel

- `GET /api/reports/members/excel`
- `GET /api/reports/subscriptions/excel`
- `GET /api/reports/projects/excel`

Frontend note:

- Use `responseType: 'blob'`
- Read `Content-Disposition` when you want the backend filename

## Activity Logs (Activity Explorer)

Rewritten this session — same underlying `activity_log` data (logging itself is unchanged), but the endpoints now support filtering/search/sort, and each row is shaped by `ActivityLogResource` instead of being a raw Spatie `Activity` model. **Permissions**: président/vice-président/trésorier see every activity; every other bureau role only activity related to projects they're assigned to (possibly none); `abonne` subscribers get `403` on all three endpoints below, even if they happen to be a committee member somewhere.

### `GET /api/activity-logs`

Query parameters (all optional, all combinable — applied as AND):

| Param | Type | Notes |
|---|---|---|
| `search` | string | Matches user name, entity name/reference, project name, description. A bare number also matches by ID (e.g. `"Expense #42"` matches expense id 42). |
| `user_id` | int | Filters by causer. |
| `entity` | string | One of `donation`, `expense`, `project`, `subscription`, `member`, `fund_allocation`, `report` (see `GET /api/activity-logs/filters` below). |
| `action` | string | One of `created`, `updated`, `deleted`, `submitted`, `approved`, `rejected`, `paid`, `verified`, `generated`. |
| `date_from`, `date_to` | date (`YYYY-MM-DD`) | Inclusive range on `created_at`. |
| `project_id` | int | Only activity whose subject belongs to (or is) this project. |
| `status` | string | The subject's current status value (e.g. `approved`, `pending`) — only meaningful combined with `entity`, or matches any entity whose status column has that value. |
| `sort` | `desc` \| `asc` | Default `desc` (newest first). |
| `page` | int | Default `1`. |
| `per_page` | int | Default `25`, max `100`. |

Response `200` — same flat pagination envelope every other paginated endpoint in this app uses (unchanged shape), `data` items now resource-shaped:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 501,
      "event": "updated",
      "causer": { "id": 1, "name": "Mohamed" },
      "entity_key": "expense",
      "entity_label": "Expense",
      "reference": "#42",
      "action_label": "Approved",
      "message": "Mohamed approved Expense #42",
      "status": { "value": "approved", "label": "Approved" },
      "project": { "id": 28, "name": "Water Well Construction" },
      "created_at": "2026-07-29T11:50:00.000000Z"
    }
  ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 1,
  "last_page_url": "...",
  "links": [],
  "next_page_url": null,
  "path": "...",
  "per_page": 25,
  "prev_page_url": null,
  "to": 1,
  "total": 1
}
```

`status`/`project` are `null` when not applicable to that entity (e.g. Member, Fund Allocation). `causer` is `null` for system-triggered activity (e.g. the daily subscription-expiry job) — the frontend shows "System" in that case.

### `GET /api/activity-logs/filters`

Options for the filter bar's dropdowns, scoped identically to `GET /api/activity-logs` (a scoped viewer is never offered a user/project outside what they can already see):

```json
{
  "users": [{ "id": 1, "name": "Mohamed" }],
  "projects": [{ "id": 28, "name": "Water Well Construction" }],
  "entities": [
    {
      "key": "donation",
      "label": "Donation",
      "statuses": [{ "value": "draft", "label": "Draft" }, { "value": "pending", "label": "Pending" }, "..."]
    }
  ]
}
```

### `GET /api/activity-logs/{id}`

Response `200`: the same shape as a list item, plus:

```json
{
  "data": {
    "...": "all list-item fields",
    "changes": { "old": null, "new": null },
    "related": {
      "project": { "id": 28, "name": "Water Well Construction", "status": "active" },
      "member": null,
      "donation": null,
      "expense": { "...": "full ExpenseResource shape" }
    }
  }
}
```

`changes.old`/`changes.new` come from Spatie's `properties.old`/`properties.attributes` — in this app's current data these are always `null` (no field-level diff has ever been recorded, a pre-existing condition explicitly left unchanged this session; see `backend/BACKEND_SUMMARY.md`). `related.donation`/`related.expense` are only present when the activity's subject is that type, and reuse the exact `DonationResource`/`ExpenseResource` shapes documented above.

## Notifications (Notification Center)

**This section was previously undocumented** — the endpoints existed before this session but had no entry here. Expanded this session to cover every workflow transition (see `backend/BACKEND_SUMMARY.md`'s Notification Center for the full trigger list); the endpoints/shapes below reflect the current, complete contract.

Every notification is already scoped to the user it belongs to server-side — there is no cross-user notification data ever returned by these endpoints.

### `GET /api/notifications`

Query parameters (all optional, combinable):

| Param | Type | Notes |
|---|---|---|
| `status` | `unread` \| `read` | Omit for all. |
| `category` | string | One of `membership`, `projects`, `expenses`, `donations`, `committee`. |
| `date_from`, `date_to` | date (`YYYY-MM-DD`) | Inclusive range on `created_at`. |
| `page` | int | Default `1`. |
| `per_page` | int | Default `20`, max `100`. |

Response `200` — same flat pagination envelope as `GET /api/activity-logs`:

```json
{
  "current_page": 1,
  "data": [
    {
      "id": 501,
      "type": "expense_approved",
      "category": "expenses",
      "title": "Expense approved",
      "message": "Expense #42 (Water Well Construction) was approved.",
      "read_at": null,
      "is_read": false,
      "link": "/projects/28",
      "created_at": "2026-07-30T14:06:59.000000Z"
    }
  ],
  "first_page_url": "...",
  "from": 1,
  "last_page": 1,
  "last_page_url": "...",
  "links": [],
  "next_page_url": null,
  "path": "...",
  "per_page": 20,
  "prev_page_url": null,
  "to": 1,
  "total": 1
}
```

`link` is a frontend route to navigate to on click, or `null` if there's nothing to deep-link to (a notification created before this session, or a system-wide notice with no specific record behind it).

### `POST /api/notifications/{id}/read`

Marks one notification read. Response `200`: `{ "data": { ...same shape as a list item... } }`. `403` if it isn't the caller's own notification.

### `POST /api/notifications/read-all`

Marks every one of the caller's unread notifications read. Response `200`: `{ "message": "All notifications marked as read." }`.

### `DELETE /api/notifications/{id}`

New this session. Deletes one notification. Response `200`: `{ "message": "Notification deleted." }`. `403` if it isn't the caller's own notification. Manual only — nothing auto-deletes read notifications.

## Association Settings

Singleton config for the whole application — there is only ever one association, so there is only ever one settings record (id is always `1` in practice). Never returns null: the backend auto-creates the row with defaults on first access.

Resource shape (`AssociationSettingResource`):

```json
{
  "data": {
    "id": 1,
    "association_name": "Amicale Test",
    "logo": "association/abc123.png",
    "logo_url": "http://localhost:8000/storage/association/abc123.png",
    "description": "A short description of the association.",
    "address": "123 Rue Test",
    "phone": "+212600000000",
    "email": "contact@amicale-test.org",
    "website": "https://amicale-test.org",
    "annual_subscription_amount": 250,
    "currency": "EUR",
    "created_at": "2026-07-30T11:48:04.000000Z",
    "updated_at": "2026-07-30T12:54:47.000000Z"
  }
}
```

`logo` is the raw storage path (or `null`); `logo_url` is the ready-to-use `asset('storage/...')` URL (or `null`) — always prefer `logo_url` on the frontend, `logo` only exists for completeness.

### `GET /api/settings`

**Public — not behind `auth:sanctum`.** This is deliberate: the login page and the member-portal login page render the association's name/logo before any session exists, so this route can't require a token. It exposes no sensitive data (name/logo/contact/subscription default only).

Response `200`: the resource shape above.

### `PUT /api/settings`

Requires authentication **and** the `president` role — every other authenticated role gets `403`.

Because an optional logo **file** can be attached, this must be sent as `multipart/form-data` with Laravel's standard method-spoofing: `POST` to `/api/settings` with a `_method=PUT` field, not a literal HTTP `PUT` (PHP only populates `$_FILES` for a literal `POST`). Body fields:

```
association_name: string, required
description: string, nullable
logo: file, nullable — png/jpg/jpeg/svg, max 5MB. Replaces (and deletes) any existing logo.
address: string, required
phone: string, required
email: string, required, valid email
website: string, nullable
annual_subscription_amount: numeric, required, min 0
currency: string, required
```

Response `200`: the updated resource shape above. Response `422` on validation failure (see Validation and Error Shape below). Response `403` if the caller isn't president.

Frontend note: send **every** field on every save (not just changed ones) — this is a full-record `PUT`, not a partial `PATCH`. Only append `logo` when the user actually picked a new file; omitting it leaves the existing logo untouched.

## Validation and Error Shape

Validation failures follow Laravel default shape:

```json
{
  "message": "The given data was invalid.",
  "errors": {
    "email": [
      "The email field is required."
    ]
  }
}
```

Authorization failures return `403`.

Unauthenticated requests to protected endpoints return `401`.

## Frontend Integration Notes

- Resource collections use `{ data: [...] }`
- Single resources use `{ data: {...} }`
- `GET /api/me` returns a raw user object with roles loaded
- Project members return raw JSON, not a Laravel API resource. Activity logs are resource-shaped (`ActivityLogResource`) but keep the plain pagination envelope (`current_page`/`data`/... at the top level) rather than switching to `Resource::collection()`'s nested `data`/`links`/`meta` — see Activity Logs above
- Report endpoints return files, not JSON
- Subscription responses include `member.user`, `verifier`, `verified_by`, and `verified_at`
- Project responses include the full `members` array with pivot data
- Donation responses include `member.user`, `project`, and `recorder`
- Expense responses include `project` and `creator`
- Committee members have filtered access: they only see donations, expenses, and activity logs for their assigned projects. For activity logs specifically, président/vice-président/trésorier are exempt from this scoping (see Activity Logs above); `abonne` subscribers have no access to activity logs at all
- All endpoints use Laravel Sanctum authentication with role-based authorization policies, **except** `GET /api/settings`, which is intentionally public (see Association Settings above)

## Recommended Frontend API Modules

- `auth.api.js`
- `members.api.js`
- `subscriptions.api.js`
- `dues.api.js`
- `donations.api.js`
- `expenses.api.js`
- `projects.api.js`
- `dashboard.api.js`
- `reports.api.js`
- `activityLogs.api.js`
- `settings.api.js`
- `notifications.api.js`

