<?php

use App\Http\Controllers\Api\ActionCenterController;
use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AssociationSettingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DonationController;
use App\Http\Controllers\Api\DueController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\MemberController;
use App\Http\Controllers\Api\MemberPortalAuthController;
use App\Http\Controllers\Api\MemberPortalController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectDeletionRequestController;
use App\Http\Controllers\Api\ProjectFundAllocationController;
use App\Http\Controllers\Api\ProjectMemberController;
use App\Http\Controllers\Api\ProjectPhaseRequestController;
use App\Http\Controllers\Api\ProjectReportController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// Public association branding (name/logo/contact/subscription amount) — the
// login page and the member-portal login page render this before any
// session exists, so it can't sit behind auth:sanctum. Read-only; changing
// it is still gated by the "update" policy, president-only.
Route::get('/settings', [AssociationSettingController::class, 'show']);

// Passkey login for subscribers — a second way to obtain the same kind of
// Sanctum token as /login above, against the same User model. The app
// itself is unified (one dashboard, one nav, permission-driven) — only the
// authentication mechanism differs by audience. See MemberPortalAuthController.
Route::post('/member/login', [MemberPortalAuthController::class, 'login']);
Route::post('/member/forgot-passkey', [MemberPortalAuthController::class, 'forgotPasskey']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    // Own profile/subscription/donation summary — reused by the unified
    // dashboard's Dashboard/My Donations/Profile pages for a plain
    // subscriber, in place of the bureau-wide list endpoints those pages
    // use for bureau/committee users. Always scoped to auth()->user()->member.
    Route::get('/member/dashboard', [MemberPortalController::class, 'dashboard']);
    Route::apiResource('members', MemberController::class);
    Route::apiResource('subscriptions', SubscriptionController::class);
    Route::apiResource('donations', DonationController::class);
    Route::apiResource('expenses', ExpenseController::class);

    Route::post(
        'subscriptions/{subscription}/verify',
        [SubscriptionController::class, 'verify']
    );
    Route::get('dues', [DueController::class, 'index']);
    Route::get('dues/{due}', [DueController::class, 'show']);
    Route::post('dues/{due}/waive', [DueController::class, 'waive']);
    Route::post(
        'donations/{donation}/receipt',
        [DonationController::class, 'uploadReceipt']
    );
    Route::post(
        'donations/{donation}/submit',
        [DonationController::class, 'submit']
    );
    Route::post(
        'donations/{donation}/approve',
        [DonationController::class, 'approve']
    );
    Route::post(
        'donations/{donation}/reject',
        [DonationController::class, 'reject']
    );
    Route::post(
        'expenses/import',
        [ExpenseController::class, 'import']
    );
    Route::post(
        'expenses/{expense}/invoice',
        [ExpenseController::class, 'uploadInvoice']
    );
    Route::post(
        'expenses/{expense}/submit',
        [ExpenseController::class, 'submit']
    );
    Route::post(
        'expenses/{expense}/approve',
        [ExpenseController::class, 'approve']
    );
    Route::post(
        'expenses/{expense}/reject',
        [ExpenseController::class, 'reject']
    );
    Route::post(
        'expenses/{expense}/mark-paid',
        [ExpenseController::class, 'markPaid']
    );
    Route::apiResource('projects', ProjectController::class);
    Route::post(
        'projects/{project}/start',
        [ProjectController::class, 'start']
    );
    Route::post(
        'projects/{project}/close',
        [ProjectController::class, 'close']
    );
    Route::get(
        'projects/{project}/members',
        [ProjectMemberController::class, 'index']
    );

    Route::post(
        'projects/{project}/members',
        [ProjectMemberController::class, 'store']
    );

    Route::delete(
        'projects/{project}/members/{member}',
        [ProjectMemberController::class, 'destroy']
    );

    Route::post(
        'projects/{project}/members/{member}/replace',
        [ProjectMemberController::class, 'replace']
    );

    Route::post(
        'projects/{project}/members/{member}/resign',
        [ProjectMemberController::class, 'resign']
    );

    Route::get(
        'projects/{project}/committee-history',
        [ProjectMemberController::class, 'history']
    );

    Route::get(
        'projects/{project}/allocations',
        [ProjectFundAllocationController::class, 'index']
    );
    Route::post(
        'projects/{project}/allocations',
        [ProjectFundAllocationController::class, 'store']
    );

    Route::get(
        'projects/{project}/reports',
        [ProjectReportController::class, 'index']
    );
    Route::get(
        'project-reports/{projectReport}/download',
        [ProjectReportController::class, 'download']
    );

    Route::get(
        'phase-requests/pending',
        [ProjectPhaseRequestController::class, 'pending']
    );
    Route::get(
        'projects/{project}/phase-requests',
        [ProjectPhaseRequestController::class, 'index']
    );
    Route::post(
        'projects/{project}/phase-requests',
        [ProjectPhaseRequestController::class, 'store']
    );
    Route::post(
        'phase-requests/{phaseRequest}/approve',
        [ProjectPhaseRequestController::class, 'approve']
    );
    Route::post(
        'phase-requests/{phaseRequest}/reject',
        [ProjectPhaseRequestController::class, 'reject']
    );

    Route::get(
        'deletion-requests/pending',
        [ProjectDeletionRequestController::class, 'pending']
    );
    Route::get(
        'projects/{project}/deletion-requests',
        [ProjectDeletionRequestController::class, 'index']
    );
    Route::post(
        'projects/{project}/deletion-requests',
        [ProjectDeletionRequestController::class, 'store']
    );
    Route::post(
        'deletion-requests/{deletionRequest}/approve',
        [ProjectDeletionRequestController::class, 'approve']
    );
    Route::post(
        'deletion-requests/{deletionRequest}/reject',
        [ProjectDeletionRequestController::class, 'reject']
    );

    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/action-center', [ActionCenterController::class, 'index']);

    Route::get('/reports/members', [ReportController::class, 'members']);
    Route::get('/reports/subscriptions', [ReportController::class, 'subscriptions']);
    Route::get('/reports/projects', [ReportController::class, 'projects']);
    Route::get('/reports/dues', [ReportController::class, 'dues']);

    Route::get('/reports/members/excel', [ReportController::class, 'membersExcel']);
    Route::get('/reports/subscriptions/excel', [ReportController::class, 'subscriptionsExcel']);
    Route::get('/reports/projects/excel', [ReportController::class, 'projectsExcel']);
    Route::get('/reports/dues/excel', [ReportController::class, 'duesExcel']);
    Route::post(
        'subscriptions/{subscription}/receipt',
        [SubscriptionController::class, 'uploadReceipt']
    );

    Route::get('/activity-logs', [ActivityLogController::class, 'index']);
    Route::get('/activity-logs/filters', [ActivityLogController::class, 'filters']);
    Route::get('/activity-logs/{activity}', [ActivityLogController::class, 'show']);

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);
    Route::delete('/notifications/{notification}', [NotificationController::class, 'destroy']);

    Route::put('/settings', [AssociationSettingController::class, 'update']);

});
