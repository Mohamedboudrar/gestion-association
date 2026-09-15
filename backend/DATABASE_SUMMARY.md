# Database Schema Summary

This document provides a comprehensive overview of the association management system's database schema, including all tables, relationships, and constraints.

## Entity Relationship Diagram

```
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│     users       │──────▶│    members      │──────▶│  subscriptions  │
│                 │ 1:1   │                 │ 1:N   │                 │
│ - id            │       │ - id            │       │ - id            │
│ - name          │       │ - user_id       │       │ - member_id     │
│ - email         │       │ - phone         │       │ - due_id        │
│ - password      │       │ - address       │       │ - amount        │
│ - passkey_hash  │       │                 │       │ - status        │
│ - ...           │       │                 │       │ - ...           │
└─────────────────┘       └─────────────────┘       └─────────────────┘
         │                                                    │
         │                                                    │
         │                                                    │
         ▼                                                    ▼
┌─────────────────┐       ┌─────────────────┐       ┌─────────────────┐
│     roles       │       │    projects     │◀──────│      dues       │
│                 │       │                 │       │                 │
│ - id            │       │ - id            │       │ - id            │
│ - name          │       │ - name          │       │ - member_id     │
│ - guard_name    │       │ - manager_id    │       │ - year          │
│ - ...           │       │ - status        │       │ - amount_due    │
└─────────────────┘       │ - phase         │       │ - status        │
         │                │ - budget        │       └─────────────────┘
         │                │ - latitude      │
         │                │ - longitude     │
         │                │ - ...          │
         │                └─────────────────┘
         │                         │
         │                         │
         │                         │
         ▼                         ▼
┌─────────────────┐       ┌─────────────────┐
│  permissions    │       │ member_project │
│                 │       │                 │
│ - id            │       │ - id            │
│ - name          │       │ - member_id     │
│ - guard_name    │       │ - project_id    │
│ - ...           │       │ - role          │
└─────────────────┘       │ - committee_role│
                           │ - responsibility│
                           │ - assigned_by   │
                           │ - assigned_at   │
                           └─────────────────┘
                                    │
                                    │
                                    │
                                    ▼
                           ┌─────────────────┐
                           │  donations      │
                           │                 │
                           │ - id            │
                           │ - member_id     │
                           │ - project_id    │
                           │ - amount        │
                           │ - status        │
                           │ - approved_by   │
                           │ - rejected_by   │
                           │ - ...           │
                           └─────────────────┘
                                    │
                                    │
                                    │
                                    ▼
                           ┌─────────────────┐
                           │   expenses      │
                           │                 │
                           │ - id            │
                           │ - project_id    │
                           │ - amount        │
                           │ - status        │
                           │ - approved_by   │
                           │ - rejected_by   │
                           │ - paid_by       │
                           │ - ...           │
                           └─────────────────┘
```

## Core Tables

### users

Authentication and user management table with passkey support for member portal access.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | User identifier |
| name | string | - | User full name |
| email | string | Unique | User email address |
| email_verified_at | timestamp | Nullable | Email verification timestamp |
| password | string | Hashed | User password (hashed) |
| remember_token | string | Nullable | Remember me token |
| passkey_hash | string | Nullable, Unique | Deterministic hash for member portal access |
| passkey_created_at | timestamp | Nullable | Passkey creation timestamp |
| last_passkey_sent_at | timestamp | Nullable | Last passkey sent timestamp |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `hasOne` Member
- `hasMany` Project (as manager)
- `hasMany` Donation (as recorder)
- `hasMany` Expense (as creator)
- `morphMany` ActivityLog (as causer)
- `morphToMany` Role (via Spatie Permission)

### members

Association member profiles linked to user accounts.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Member identifier |
| user_id | bigint | Foreign Key, Cascade Delete | Reference to users table |
| phone | string | Nullable | Member phone number |
| address | string | Nullable | Member address |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` User
- `hasMany` Subscription
- `hasMany` Donation
- `belongsToMany` Project (via member_project)
- `morphMany` ActivityLog (as subject)

### subscriptions

Annual subscription payments for association membership.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Subscription identifier |
| member_id | bigint | Foreign Key, Cascade Delete | Reference to members table |
| due_id | bigint | Foreign Key, Nullable, Null On Delete | Reference to dues table |
| amount | decimal(10,2) | - | Subscription amount |
| payment_method | string | - | Payment method used |
| receipt_number | string | Nullable | Receipt reference number |
| receipt_file | string | Nullable | Receipt file path |
| payment_date | date | - | Payment date |
| expires_at | date | - | Subscription expiry date |
| status | enum | Default: 'pending' | pending, verified, rejected, expired |
| verified_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| verified_at | timestamp | Nullable | Verification timestamp |
| notes | text | Nullable | Additional notes |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Member
- `belongsTo` Due
- `belongsTo` User (as verifier)
- `morphMany` ActivityLog (as subject)

### projects

Association projects with lifecycle management and geographic tracking.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Project identifier |
| name | string | - | Project name |
| description | text | Nullable | Project description |
| start_date | date | - | Project start date |
| end_date | date | Nullable | Project end date |
| budget | decimal(10,2) | Default: 0 | Project budget |
| status | enum | Default: 'draft' | draft, committee_ready, funding_ready, active, completed, cancelled |
| phase | enum | Default: 'planning' | planning, preparation, in_progress, finishing, completed |
| latitude | decimal(10,8) | Nullable | Project location latitude |
| longitude | decimal(11,8) | Nullable | Project location longitude |
| manager_id | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` User (as manager)
- `belongsToMany` Member (via member_project)
- `hasMany` Donation
- `hasMany` Expense
- `hasMany` Document
- `hasMany` ProjectFundAllocation
- `hasMany` ProjectReport
- `hasMany` ProjectPhaseRequest
- `hasMany` ProjectDeletionRequest
- `morphMany` ActivityLog (as subject)

### member_project

Many-to-many relationship table for project committee assignments with role tracking.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Assignment identifier |
| member_id | bigint | Foreign Key, Cascade Delete | Reference to members table |
| project_id | bigint | Foreign Key, Cascade Delete | Reference to projects table |
| role | string | Nullable | General role description |
| committee_role | string | Default: 'member' | leader, treasurer, secretary, member |
| responsibility | string | Nullable | Specific responsibility |
| assigned_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| assigned_at | timestamp | Nullable | Assignment timestamp |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Member
- `belongsTo` Project
- `belongsTo` User (as assigned_by)

### donations

Donation records with approval workflow and project attribution.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Donation identifier |
| member_id | bigint | Foreign Key, Nullable, Null On Delete | Reference to members table |
| project_id | bigint | Foreign Key, Nullable, Null On Delete | Reference to projects table |
| donor_name | string | Nullable | Donor name (if not member) |
| amount | decimal(10,2) | - | Donation amount |
| payment_method | string | - | Payment method used |
| receipt_number | string | Nullable | Receipt reference number |
| receipt_file | string | Nullable | Receipt file path |
| donation_date | date | - | Donation date |
| notes | text | Nullable | Additional notes |
| recorded_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| status | enum | Default: 'draft' | draft, pending, approved, rejected |
| approved_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| approved_at | timestamp | Nullable | Approval timestamp |
| rejected_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| rejected_at | timestamp | Nullable | Rejection timestamp |
| rejection_reason | text | Nullable | Rejection reason |
| rejection_type | enum | Nullable | receipt, details |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Member
- `belongsTo` Project
- `belongsTo` User (as recorded_by, approved_by, rejected_by)
- `morphMany` ActivityLog (as subject)

### expenses

Project expense records with approval workflow and invoice tracking.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Expense identifier |
| project_id | bigint | Foreign Key, Cascade Delete | Reference to projects table |
| supplier_name | string | - | Supplier name |
| description | text | - | Expense description |
| amount | decimal(10,2) | - | Expense amount |
| payment_method | string | - | Payment method used |
| invoice_number | string | Nullable | Invoice reference number |
| invoice_path | string | Nullable | Invoice file path |
| expense_date | date | - | Expense date |
| notes | text | Nullable | Additional notes |
| created_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| status | enum | Default: 'draft' | draft, pending, approved, rejected, paid |
| approved_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| approved_at | timestamp | Nullable | Approval timestamp |
| rejected_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| rejected_at | timestamp | Nullable | Rejection timestamp |
| rejection_reason | text | Nullable | Rejection reason |
| rejection_type | enum | Nullable | invoice, details |
| paid_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| paid_at | timestamp | Nullable | Payment timestamp |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Project
- `belongsTo` User (as created_by, approved_by, rejected_by, paid_by)
- `morphMany` ActivityLog (as subject)

### dues

Annual dues system for tracking member subscription obligations.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Due identifier |
| member_id | bigint | Foreign Key, Cascade Delete | Reference to members table |
| year | unsignedSmallInteger | - | Due year |
| amount_due | decimal(10,2) | - | Amount due for the year |
| amount_paid | decimal(10,2) | Default: 0 | Amount paid so far |
| balance | decimal(10,2) | - | Remaining balance |
| status | enum | Default: 'pending' | pending, partial, paid, overdue, waived |
| due_date | date | - | Payment due date |
| paid_at | timestamp | Nullable | Payment completion timestamp |
| waived_reason | text | Nullable | Reason for waiver |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Constraints:**
- Unique constraint on (member_id, year) - one due per member per year

**Relationships:**
- `belongsTo` Member
- `hasMany` Subscription

## Project Management Tables

### project_fund_allocations

Fund allocation records for projects.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Allocation identifier |
| project_id | bigint | Foreign Key, Cascade Delete | Reference to projects table |
| amount | decimal(10,2) | - | Allocated amount |
| allocation_date | date | - | Allocation date |
| proof_file | string | Nullable | Proof document path |
| recorded_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Project
- `belongsTo` User (as recorded_by)

### project_reports

Generated project reports with summary data.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Report identifier |
| project_id | bigint | Foreign Key, Cascade Delete | Reference to projects table |
| generated_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| file_path | string | - | Report file path |
| summary | json | - | Report summary data |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Project
- `belongsTo` User (as generated_by)

### project_phase_requests

Project phase transition requests with approval workflow.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Request identifier |
| project_id | bigint | Foreign Key, Cascade Delete | Reference to projects table |
| from_phase | string | - | Current phase |
| to_phase | string | - | Requested phase |
| summary | text | - | Phase transition summary |
| notes | text | Nullable | Additional notes |
| requested_by | bigint | Foreign Key, Cascade Delete | Reference to users table |
| requested_at | timestamp | - | Request timestamp |
| status | enum | Default: 'pending' | pending, approved, rejected |
| reviewed_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| reviewed_at | timestamp | Nullable | Review timestamp |
| rejection_reason | text | Nullable | Rejection reason |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Project
- `belongsTo` User (as requested_by, reviewed_by)
- `hasMany` ProjectPhaseRequestProof

### project_phase_request_proofs

Supporting documents for phase transition requests.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Proof identifier |
| project_phase_request_id | bigint | Foreign Key, Cascade Delete | Reference to project_phase_requests table |
| file_path | string | - | File path |
| original_name | string | - | Original file name |
| mime_type | string | - | File MIME type |
| size | unsignedBigInteger | - | File size in bytes |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` ProjectPhaseRequest

### project_deletion_requests

Project deletion requests with audit trail.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Request identifier |
| project_id | bigint | Foreign Key, Nullable, Null On Delete | Reference to projects table |
| project_name | string | - | Project name snapshot |
| reason | text | - | Deletion reason |
| status | enum | Default: 'pending' | pending, approved, rejected |
| requested_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| requested_at | timestamp | - | Request timestamp |
| reviewed_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| reviewed_at | timestamp | Nullable | Review timestamp |
| rejection_reason | text | Nullable | Rejection reason |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Project
- `belongsTo` User (as requested_by, reviewed_by)

### committee_assignments

Historical tracking of committee member assignments and changes.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Assignment identifier |
| project_id | bigint | Foreign Key, Cascade Delete | Reference to projects table |
| member_id | bigint | Foreign Key, Cascade Delete | Reference to members table |
| role | string | Nullable | General role description |
| committee_role | string | Nullable | Committee role (leader, treasurer, secretary, member) |
| responsibility | string | Nullable | Specific responsibility |
| assigned_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| assigned_at | timestamp | Nullable | Assignment timestamp |
| removed_by | bigint | Foreign Key, Nullable, Null On Delete | Reference to users table |
| removed_at | timestamp | Nullable | Removal timestamp |
| reason | text | Nullable | Change reason |
| action | enum | Default: 'assigned' | assigned, replaced, resigned, removed, dissolved |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` Project
- `belongsTo` Member
- `belongsTo` User (as assigned_by, removed_by)

## System Tables

### activity_log

Audit trail for all system activities (Spatie Activitylog).

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Log identifier |
| log_name | string | Nullable, Indexed | Log name/category |
| description | text | - | Activity description |
| subject_type | string | Nullable, Morph | Subject model type |
| subject_id | unsignedBigInteger | Nullable, Morph | Subject model ID |
| event | string | Nullable | Event name |
| causer_type | string | Nullable, Morph | Causer model type |
| causer_id | unsignedBigInteger | Nullable, Morph | Causer model ID |
| attribute_changes | json | Nullable | Changed attributes |
| properties | json | Nullable | Additional properties |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `morphTo` Subject (polymorphic)
- `morphTo` Causer (polymorphic)

### notifications

User notifications with subject linking for deep-linking.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Notification identifier |
| user_id | bigint | Foreign Key, Cascade Delete | Reference to users table |
| type | string | - | Notification type |
| title | string | - | Notification title |
| message | text | Nullable | Notification message |
| subject_type | string | Nullable, Indexed | Subject model type |
| subject_id | unsignedBigInteger | Nullable, Indexed | Subject model ID |
| read_at | timestamp | Nullable | Read timestamp |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Relationships:**
- `belongsTo` User
- `morphTo` Subject (polymorphic)

### association_settings

Association-wide configuration settings (singleton pattern enforced in application code).

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Settings identifier |
| association_name | string | - | Association name |
| association_logo | string | Nullable | Logo file path |
| address | string | - | Association address |
| phone | string | - | Association phone |
| email | string | - | Association email |
| website | string | Nullable | Association website |
| annual_subscription_amount | decimal(10,2) | Default: 0 | Annual subscription amount |
| currency | string(10) | Default: 'MAD' | Currency code |
| description | text | Nullable | Association description |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

## Permission Tables (Spatie Permission)

### permissions

System permissions for role-based access control.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Permission identifier |
| name | string | - | Permission name |
| guard_name | string | - | Guard name |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Constraints:**
- Unique constraint on (name, guard_name)

### roles

System roles for role-based access control.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Role identifier |
| team_foreign_key | unsignedBigInteger | Nullable, Indexed | Team foreign key (if teams enabled) |
| name | string | - | Role name |
| guard_name | string | - | Guard name |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

**Constraints:**
- Unique constraint on (team_foreign_key, name, guard_name) or (name, guard_name) depending on team configuration

### model_has_permissions

Polymorphic relationship between models and permissions.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| permission_id | unsignedBigInteger | Foreign Key, Cascade Delete | Reference to permissions table |
| model_type | string | - | Model type |
| model_id | unsignedBigInteger | - | Model ID |
| team_foreign_key | unsignedBigInteger | Nullable, Indexed | Team foreign key (if teams enabled) |

**Constraints:**
- Primary key on (team_foreign_key, permission_id, model_id, model_type) or (permission_id, model_id, model_type)
- Foreign key to permissions table

### model_has_roles

Polymorphic relationship between models and roles.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| role_id | unsignedBigInteger | Foreign Key, Cascade Delete | Reference to roles table |
| model_type | string | - | Model type |
| model_id | unsignedBigInteger | - | Model ID |
| team_foreign_key | unsignedBigInteger | Nullable, Indexed | Team foreign key (if teams enabled) |

**Constraints:**
- Primary key on (team_foreign_key, role_id, model_id, model_type) or (role_id, model_id, model_type)
- Foreign key to roles table

### role_has_permissions

Many-to-many relationship between roles and permissions.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| permission_id | unsignedBigInteger | Foreign Key, Cascade Delete | Reference to permissions table |
| role_id | unsignedBigInteger | Foreign Key, Cascade Delete | Reference to roles table |

**Constraints:**
- Primary key on (permission_id, role_id)
- Foreign key to permissions table
- Foreign key to roles table

## Laravel System Tables

### personal_access_tokens

Laravel Sanctum personal access tokens for API authentication.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Token identifier |
| tokenable_type | string | - | Tokenable model type |
| tokenable_id | unsignedBigInteger | - | Tokenable model ID |
| name | string | - | Token name |
| token | string | Unique | Token value |
| abilities | json | Nullable | Token abilities |
| last_used_at | timestamp | Nullable | Last used timestamp |
| expires_at | timestamp | Nullable | Expiration timestamp |
| created_at | timestamp | - | Record creation timestamp |
| updated_at | timestamp | - | Record update timestamp |

### cache

Laravel cache table for caching system data.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| key | string | Primary Key | Cache key |
| value | text | - | Cache value |
| expiration | int | Nullable | Expiration timestamp |

### jobs

Laravel job queue table for background job processing.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | bigint | Primary Key | Job identifier |
| queue | string | - | Queue name |
| payload | longText | - | Job payload |
| attempts | unsignedTinyInt | - | Number of attempts |
| reserved_at | int | Nullable | Reserved timestamp |
| available_at | int | - | Available timestamp |
| created_at | int | - | Creation timestamp |

### sessions

Laravel session table for session management.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| id | string | Primary Key | Session ID |
| user_id | bigint | Nullable, Indexed | User ID |
| ip_address | string(45) | Nullable | IP address |
| user_agent | text | Nullable | User agent string |
| payload | longText | - | Session payload |
| last_activity | int | Indexed | Last activity timestamp |

### password_reset_tokens

Laravel password reset tokens table.

| Column | Type | Constraints | Description |
|--------|------|-------------|-------------|
| email | string | Primary Key | User email |
| token | string | - | Reset token |
| created_at | timestamp | Nullable | Creation timestamp |

## Key Relationships Summary

### User Relationships
- User → Member (1:1)
- User → Project (1:N, as manager)
- User → Donation (1:N, as recorder)
- User → Expense (1:N, as creator)
- User → Role (N:M, via Spatie Permission)

### Member Relationships
- Member → User (N:1)
- Member → Subscription (1:N)
- Member → Donation (1:N)
- Member → Project (N:M, via member_project)
- Member → Due (1:N)

### Project Relationships
- Project → User (N:1, as manager)
- Project → Member (N:M, via member_project)
- Project → Donation (1:N)
- Project → Expense (1:N)
- Project → ProjectFundAllocation (1:N)
- Project → ProjectReport (1:N)
- Project → ProjectPhaseRequest (1:N)
- Project → ProjectDeletionRequest (1:N)

### Subscription Relationships
- Subscription → Member (N:1)
- Subscription → Due (N:1, optional)
- Subscription → User (N:1, as verifier)

### Donation Relationships
- Donation → Member (N:1, optional)
- Donation → Project (N:1, optional)
- Donation → User (N:1, as recorded_by, approved_by, rejected_by)

### Expense Relationships
- Expense → Project (N:1)
- Expense → User (N:1, as created_by, approved_by, rejected_by, paid_by)

### Due Relationships
- Due → Member (N:1)
- Due → Subscription (1:N)

## Enum Values

### Subscription Status
- `pending` - Awaiting verification
- `verified` - Payment verified
- `rejected` - Payment rejected
- `expired` - Subscription expired

### Project Status
- `draft` - Initial draft state
- `committee_ready` - Committee assigned and ready
- `funding_ready` - Funding allocated
- `active` - Project is active
- `completed` - Project completed
- `cancelled` - Project cancelled

### Project Phase
- `planning` - Planning phase
- `preparation` - Preparation phase
- `in_progress` - In progress phase
- `finishing` - Finishing phase
- `completed` - Completed phase

### Donation Status
- `draft` - Initial draft
- `pending` - Awaiting approval
- `approved` - Donation approved
- `rejected` - Donation rejected

### Expense Status
- `draft` - Initial draft
- `pending` - Awaiting approval
- `approved` - Expense approved
- `rejected` - Expense rejected
- `paid` - Expense paid

### Due Status
- `pending` - Payment pending
- `partial` - Partial payment made
- `paid` - Fully paid
- `overdue` - Payment overdue
- `waived` - Payment waived

### Committee Role
- `leader` - Committee leader
- `treasurer` - Committee treasurer
- `secretary` - Committee secretary
- `member` - Regular committee member

### Rejection Type
- `receipt` - Rejected due to receipt issues (can be resubmitted)
- `details` - Rejected due to incorrect details (must create new)

### Committee Assignment Action
- `assigned` - Member assigned to committee
- `replaced` - Member replaced in committee
- `resigned` - Member resigned from committee
- `removed` - Member removed from committee
- `dissolved` - Committee dissolved

## Indexes and Performance

### Performance Indexes
- `users.email` - Unique index for email lookups
- `users.passkey_hash` - Unique index for passkey authentication
- `activity_log.log_name` - Index for log filtering
- `activity_log.subject_type, subject_id` - Composite index for subject lookups
- `notifications.subject_type, subject_id` - Composite index for subject linking
- `dues.member_id, year` - Unique composite index for annual dues
- `sessions.user_id` - Index for user session lookups
- `sessions.last_activity` - Index for session cleanup

### Foreign Key Constraints
All foreign key relationships are properly constrained with appropriate cascade or null-on-delete behaviors to maintain data integrity.

## Data Integrity Notes

### Cascade Delete Behavior
- Deleting a User cascades to delete their Member record
- Deleting a Member cascades to delete their Subscriptions and Dues
- Deleting a Project cascades to delete related Expenses, Fund Allocations, Reports, Phase Requests, and Deletion Requests
- Deleting a Member from member_project cascades to delete the assignment

### Null on Delete Behavior
- User references in approvals/rejections are set to NULL when the user is deleted (preserves audit trail)
- Project references in Deletion Requests are set to NULL (preserves deletion history after project is deleted)

### Business Logic Constraints
- One due per member per year (enforced by unique constraint)
- Project status lifecycle is managed through application code with phase transition requests
- Committee role assignments are tracked historically through committee_assignments table
- Passkey authentication uses deterministic hashing for secure member portal access
