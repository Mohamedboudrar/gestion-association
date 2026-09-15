import { isFundingReady } from './projectLifecycle'

export function getUserRoles(user) {
  if (!user) return []

  const roles = Array.isArray(user.roles)
    ? user.roles.map((role) => role?.name).filter(Boolean)
    : [user.role].filter(Boolean)

  return roles.map((role) => String(role).toLowerCase())
}

export function hasRole(user, role) {
  return getUserRoles(user).includes(role)
}

function hasAnyRole(user, roles) {
  const owned = getUserRoles(user)
  return roles.some((role) => owned.includes(role))
}

export function getCommitteeRole(user, project) {
  if (!user?.id || !Array.isArray(project?.members)) return null

  const entry = project.members.find(
    (member) => member?.user?.id === user.id || member?.user_id === user.id,
  )

  return entry?.pivot?.committee_role ?? entry?.committee_role ?? null
}

// --- Global capabilities (spatie roles from GET /me) ---

// Any bureau role (everything except the plain "abonne" subscriber role).
// Mirrors backend AuthorizationHelper::isBureauMember() — keep in sync.
export function isBureauMember(user) {
  return hasAnyRole(user, [
    'president',
    'vice-president',
    'tresorier',
    'vice-tresorier',
    'secretaire-general',
    'vice-secretaire-general',
    'conseiller',
  ])
}

export function isTreasurerRole(user) {
  return hasAnyRole(user, ['tresorier', 'vice-tresorier'])
}

export function canManageMembers(user) {
  return hasRole(user, 'president')
}

// Matches backend members.create grants: president, secretaire-general.
export function canCreateMember(user) {
  return hasAnyRole(user, ['president', 'secretaire-general'])
}

// Matches backend members.update grants: president, vice-president, secretaire-general, vice-secretaire-general.
export function canEditMemberRecord(user) {
  return hasAnyRole(user, ['president', 'vice-president', 'secretaire-general', 'vice-secretaire-general'])
}

export function canDeleteMember(user) {
  return hasRole(user, 'president')
}

export function canManageProjects(user) {
  return hasAnyRole(user, ['president', 'vice-president'])
}

export function canAssignCommittee(user) {
  return hasAnyRole(user, ['president', 'vice-president'])
}

// Removing a committee member is president-only — matches ProjectPolicy::removeCommittee,
// distinct from canAssignCommittee (president + vice-president) used for adding/editing.
export function canRemoveCommitteeMember(user) {
  return hasRole(user, 'president')
}

export function canVerifySubscription(user) {
  return hasAnyRole(user, ['president', 'tresorier', 'vice-tresorier'])
}

// Matches backend DuePolicy::waive — same financial-approval tier as
// subscription verification.
export function canWaiveDue(user) {
  return hasAnyRole(user, ['president', 'tresorier', 'vice-tresorier'])
}

// Matches backend SubscriptionPolicy::uploadReceipt: verify-capable roles, or the
// subscriber uploading a receipt for their own subscription.
export function canUploadSubscriptionReceipt(user, subscription) {
  return canVerifySubscription(user) || subscription?.member?.user_id === user?.id
}

// Matches backend subscriptions.create grants: president, tresorier.
export function canCreateSubscriptionEntry(user) {
  return hasAnyRole(user, ['president', 'tresorier'])
}

export function canManageSubscriptions(user) {
  return hasRole(user, 'president')
}

// Matches backend ReportController gating: any bureau role, not plain abonne.
export function canViewReports(user) {
  return isBureauMember(user)
}

export function canGenerateAssociationReport(user) {
  return hasRole(user, 'president')
}

export function canViewSubscriberHistory(user) {
  return Boolean(user)
}

// --- Committee capabilities (project.members[].committee_role) ---

export function isCommitteePresident(user, project) {
  return getCommitteeRole(user, project) === 'leader'
}

export function isCommitteeTreasurer(user, project) {
  return getCommitteeRole(user, project) === 'treasurer'
}

export function isCommitteeSecretary(user, project) {
  return getCommitteeRole(user, project) === 'secretary'
}

function canManageProjectFinances(user, project) {
  return ['leader', 'treasurer'].includes(getCommitteeRole(user, project))
}

export function canManageCommittee(user, project) {
  return canAssignCommittee(user) || isCommitteePresident(user, project)
}

export function canGenerateProjectReport(user, project) {
  return hasRole(user, 'president') || isCommitteePresident(user, project)
}

// Matches backend ProjectPolicy::start — same authority as closing a project
// (president or committee leader), only shown once the project is funding_ready.
export function canStartProject(user, project) {
  return (hasRole(user, 'president') || isCommitteePresident(user, project)) && isFundingReady(project)
}

export function canEnterDonation(user, project) {
  return canManageProjectFinances(user, project)
}

// Matches backend DonationPolicy::update/delete: only editable/deletable
// before a decision has been made (draft or pending).
export function canEditDonation(user, project, donation) {
  return canManageProjectFinances(user, project) && ['draft', 'pending'].includes(donation?.status)
}

export function canDeleteDonation(user, project, donation) {
  return canManageProjectFinances(user, project) && ['draft', 'pending'].includes(donation?.status)
}

// Matches backend DonationPolicy::canEditReceipt: editable while draft/pending,
// or on a rejected donation specifically when the rejection was a receipt
// issue ("Replace Receipt") — a details-issue rejection is permanently locked.
export function canUploadReceipt(user, project, donation) {
  if (!canManageProjectFinances(user, project)) return false

  return (
    ['draft', 'pending'].includes(donation?.status) ||
    (donation?.status === 'rejected' && donation?.rejection_type === 'receipt')
  )
}

// Draft -> pending. Same authority as creating (committee leader/treasurer).
// Also covers "Submit Again": a receipt-issue rejection returning to pending.
export function canSubmitDonation(user, project, donation) {
  if (!canManageProjectFinances(user, project)) return false

  return (
    donation?.status === 'draft' ||
    (donation?.status === 'rejected' && donation?.rejection_type === 'receipt')
  )
}

// Matches backend DonationPolicy::APPROVAL_ROLES — separate from the committee
// leader/treasurer roles that create/submit/edit donations.
export function isDonationApprovalRole(user) {
  return hasAnyRole(user, ['president', 'tresorier', 'vice-tresorier'])
}

// `project` is optional — pass it when available (e.g. the project workspace)
// to also hide the action on a locked (completed/cancelled) project; the
// cross-project donation list doesn't have the full project record, and the
// backend still enforces the lock regardless.
export function canApproveDonation(user, donation, project = null) {
  if (project && isProjectLocked(project)) return false

  return isDonationApprovalRole(user) && donation?.status === 'pending' && donation?.recorded_by?.id !== user?.id
}

export function canRejectDonation(user, donation, project = null) {
  return canApproveDonation(user, donation, project)
}

export function canEnterExpense(user, project) {
  return canManageProjectFinances(user, project)
}

// Matches backend ExpensePolicy::update/delete: only editable/deletable
// before a decision has been made (draft or pending).
export function canEditExpense(user, project, expense) {
  return canManageProjectFinances(user, project) && ['draft', 'pending'].includes(expense?.status)
}

export function canDeleteExpense(user, project, expense) {
  return canManageProjectFinances(user, project) && ['draft', 'pending'].includes(expense?.status)
}

// Matches backend ExpensePolicy::canEditInvoice: editable while draft/pending,
// or on a rejected expense specifically when the rejection was an invoice
// issue ("Replace Invoice") — a details-issue rejection is permanently locked.
export function canUploadInvoice(user, project, expense) {
  if (!canManageProjectFinances(user, project)) return false

  return (
    ['draft', 'pending'].includes(expense?.status) ||
    (expense?.status === 'rejected' && expense?.rejection_type === 'invoice')
  )
}

// Draft -> pending. Same authority as creating (committee leader/treasurer).
// Also covers "Submit Again": an invoice-issue rejection returning to pending.
export function canSubmitExpense(user, project, expense) {
  if (!canManageProjectFinances(user, project)) return false

  return (
    expense?.status === 'draft' ||
    (expense?.status === 'rejected' && expense?.rejection_type === 'invoice')
  )
}

// Matches backend ExpensePolicy::APPROVAL_ROLES — separate from the committee
// leader/treasurer roles that create/submit/edit expenses.
export function isExpenseApprovalRole(user) {
  return hasAnyRole(user, ['president', 'tresorier', 'vice-tresorier'])
}

// `project` is optional — pass it when available (e.g. the project workspace)
// to also hide the action on a locked (completed/cancelled) project; the
// cross-project Pending Approvals list doesn't have the full project record,
// and the backend still enforces the lock regardless.
export function canApproveExpense(user, expense, project = null) {
  if (project && isProjectLocked(project)) return false

  return isExpenseApprovalRole(user) && expense?.status === 'pending' && expense?.created_by?.id !== user?.id
}

export function canRejectExpense(user, expense, project = null) {
  return canApproveExpense(user, expense, project)
}

export function canMarkExpensePaid(user, expense, project = null) {
  if (project && isProjectLocked(project)) return false

  return isExpenseApprovalRole(user) && expense?.status === 'approved'
}

// Matches backend Project::isLocked() — completed/cancelled projects are read-only.
export function isProjectLocked(project) {
  return project?.status === 'completed' || project?.status === 'cancelled'
}

// Matches backend ProjectPolicy::update's authority (president/vice-president
// or that project's committee leader), lock-gated — used to show/hide the
// project details page's "Edit Location" control.
export function canEditProjectLocation(user, project) {
  return (canManageProjects(user) || isCommitteePresident(user, project)) && !isProjectLocked(project)
}

// Matches backend ProjectPhaseRequestPolicy::create — committee leader only,
// never directly settable, and only one pending request per project at a time.
export function canRequestProjectPhase(user, project) {
  return isCommitteePresident(user, project) && !isProjectLocked(project) && !project?.pending_phase_request_id
}

// Matches backend ProjectPhaseRequestPolicy's REVIEW_ROLES — same authority
// tier as assigning a committee (president or vice-president).
export function canReviewPhaseRequest(user) {
  return hasAnyRole(user, ['president', 'vice-president'])
}

// Matches backend ProjectPolicy::delete — president only, and only while the
// project isn't locked (draft/committee_ready/funding_ready/active; not
// completed/cancelled).
export function canDeleteProjectDirectly(user, project) {
  return hasRole(user, 'president') && !isProjectLocked(project)
}

// Matches backend ProjectDeletionRequestPolicy::create — committee leader
// only, never directly settable, and only one pending request per project
// at a time (mirrors canRequestProjectPhase's pending-request guard).
export function canRequestProjectDeletion(user, project) {
  return isCommitteePresident(user, project) && !isProjectLocked(project) && !project?.pending_deletion_request_id
}

// Matches backend ProjectDeletionRequestPolicy::REVIEW_ROLES — president
// only (unlike phase requests, vice-president has no say over deletion).
export function canReviewProjectDeletionRequest(user) {
  return hasRole(user, 'president')
}
