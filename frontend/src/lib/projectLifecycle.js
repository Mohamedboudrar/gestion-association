import i18n from '../i18n'

// Single source of truth for the project lifecycle stage labels and the
// frontend-side gating that mirrors backend/app/Helpers/ProjectLifecycle.php.
// The backend is still the enforcing authority — these helpers only decide
// what to show/hide/disable so the UI doesn't offer actions the API will reject.

// Reuses the same 'common:status.*' keys as every other status badge in the
// app (draft/committee_ready/funding_ready/active/completed/cancelled) —
// one translation, not a duplicate.
export function stageLabel(status) {
  return i18n.t(`status.${status}`, { ns: 'common', defaultValue: status })
}

export function isProjectActive(project) {
  return project?.status === 'active'
}

// Matches backend ProjectLifecycle::canReceiveAllocation — committee_ready,
// funding_ready, and active projects can receive fund allocations; the first
// allocation on a committee_ready project is what advances it to funding_ready.
export function canReceiveAllocation(project) {
  return ['committee_ready', 'funding_ready', 'active'].includes(project?.status)
}

// Matches backend ProjectPolicy::start's stage requirement — only a
// funding_ready project is eligible for "Start Project". Role authority is
// checked separately, see lib/roles.js::canStartProject.
export function isFundingReady(project) {
  return project?.status === 'funding_ready'
}
