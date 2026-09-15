import i18n from '../i18n'

// Single source of truth for the Activity Explorer's static filter options
// and status colors — mirrors the union of every entity's status dictionary
// (lib/expenseStatus.js, lib/donationStatus.js, and the project lifecycle
// tones from committeeUtils.js) plus the two workflow statuses no other
// dictionary already covers (verified, expired — subscriptions only).
export const STATUS_STYLES = {
  draft: 'bg-slate-100 text-slate-600',
  pending: 'bg-amber-50 text-amber-700',
  committee_ready: 'bg-amber-50 text-amber-700',
  funding_ready: 'bg-indigo-50 text-indigo-700',
  active: 'bg-emerald-50 text-emerald-700',
  approved: 'bg-emerald-50 text-emerald-700',
  verified: 'bg-emerald-50 text-emerald-700',
  completed: 'bg-blue-50 text-blue-700',
  paid: 'bg-blue-50 text-blue-700',
  rejected: 'bg-rose-50 text-rose-700',
  cancelled: 'bg-rose-50 text-rose-700',
  expired: 'bg-rose-50 text-rose-700',
}

export function statusToneClass(status) {
  return STATUS_STYLES[status] ?? 'bg-slate-100 text-slate-600'
}

// Matches the "domain" action keys ActivityLogHelper::applyActionFilter()
// understands on the backend, plus the 3 raw Spatie events. Functions (not
// static arrays) so the labels resolve in whichever language is active at
// call time, rather than being frozen in English at module-load time.
export function getActionOptions() {
  return [
    { value: 'created', label: i18n.t('activityLog.actions.created', { ns: 'association' }) },
    { value: 'updated', label: i18n.t('activityLog.actions.updated', { ns: 'association' }) },
    { value: 'deleted', label: i18n.t('activityLog.actions.deleted', { ns: 'association' }) },
    { value: 'submitted', label: i18n.t('activityLog.actions.submitted', { ns: 'association' }) },
    { value: 'approved', label: i18n.t('activityLog.actions.approved', { ns: 'association' }) },
    { value: 'rejected', label: i18n.t('activityLog.actions.rejected', { ns: 'association' }) },
    { value: 'paid', label: i18n.t('activityLog.actions.paid', { ns: 'association' }) },
    { value: 'verified', label: i18n.t('activityLog.actions.verified', { ns: 'association' }) },
    { value: 'generated', label: i18n.t('activityLog.actions.generated', { ns: 'association' }) },
  ]
}

export function getDatePresets() {
  return [
    { value: '', label: i18n.t('activityLog.datePresets.allTime', { ns: 'association' }) },
    { value: '7', label: i18n.t('activityLog.datePresets.last7Days', { ns: 'association' }) },
    { value: '30', label: i18n.t('activityLog.datePresets.last30Days', { ns: 'association' }) },
    { value: '90', label: i18n.t('activityLog.datePresets.last90Days', { ns: 'association' }) },
  ]
}

export function presetToDateFrom(days) {
  if (!days) return ''

  const date = new Date()
  date.setDate(date.getDate() - Number(days))
  return date.toISOString().slice(0, 10)
}

function dayKey(dateValue) {
  const date = new Date(dateValue)
  return date.toISOString().slice(0, 10)
}

function dayLabel(dateValue) {
  const date = new Date(dateValue)
  const today = new Date()
  const yesterday = new Date()
  yesterday.setDate(today.getDate() - 1)

  if (dayKey(date) === dayKey(today)) return i18n.t('activityLog.today', { ns: 'association' })
  if (dayKey(date) === dayKey(yesterday)) return i18n.t('activityLog.yesterday', { ns: 'association' })

  return date.toLocaleDateString('fr-FR', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' })
}

// Groups an already-sorted (newest-first or oldest-first, doesn't matter —
// order is preserved, only consecutive same-day items are grouped) list of
// activities into day buckets for the Timeline view.
export function groupActivitiesByDay(activities) {
  const groups = []
  let current = null

  for (const activity of activities) {
    const key = dayKey(activity.created_at)

    if (!current || current.key !== key) {
      current = { key, label: dayLabel(activity.created_at), items: [] }
      groups.push(current)
    }

    current.items.push(activity)
  }

  return groups
}
