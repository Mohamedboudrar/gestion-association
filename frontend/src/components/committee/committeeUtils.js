import i18n from '../../i18n'

// Module-level, not React state: formatCurrency is a plain formatter used
// by ~18 files (many nested several components deep), so threading the
// association's currency through props/context everywhere isn't practical.
// SettingsProvider calls setActiveCurrency() once on load/refresh, and every
// formatCurrency() call — with no caller changes needed — picks it up.
let activeCurrency = 'MAD'

export function setActiveCurrency(currency) {
  if (currency) activeCurrency = currency
}

// French locale number formatting (space thousands separator, e.g. "1 234 MAD").
const LOCALE = 'fr-FR'

export function formatCurrency(value, currency = activeCurrency) {
  if (value == null || value === '') return i18n.t('currencyUnavailable', { ns: 'common' })

  const amount = new Intl.NumberFormat(LOCALE, { maximumFractionDigits: 0 }).format(Number(value))

  return `${amount} ${currency}`
}

export function formatDate(value) {
  if (!value) return i18n.t('dateUnknown', { ns: 'common' })

  const timestamp = new Date(value)

  if (Number.isNaN(timestamp.getTime())) {
    return i18n.t('dateUnknown', { ns: 'common' })
  }

  return new Intl.DateTimeFormat(LOCALE, { day: 'numeric', month: 'long', year: 'numeric' }).format(timestamp)
}

export function getStatusTone(status) {
  const value = String(status ?? '').toLowerCase()

  if (value.includes('draft')) return 'bg-slate-100 text-slate-600'
  if (value.includes('committee_ready')) return 'bg-amber-50 text-amber-700'
  if (value.includes('funding_ready')) return 'bg-indigo-50 text-indigo-700'
  if (value.includes('active')) return 'bg-emerald-50 text-emerald-700'
  if (value.includes('completed')) return 'bg-blue-50 text-blue-700'
  if (value.includes('cancelled')) return 'bg-rose-50 text-rose-700'

  return 'bg-slate-100 text-slate-600'
}

// Same padding/height/radius/font as every other status badge in the app
// (DonationStatusBadge, ExpenseStatusBadge) — only the color varies per
// call site via getStatusTone(status). Keeps every badge visually identical
// without touching the color mapping itself.
export const BADGE_BASE_CLASS = 'inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold leading-none'

export function statusBadgeClass(status) {
  return `${BADGE_BASE_CLASS} ${getStatusTone(status)}`
}

// Matches the capitalized options already offered in the committee-role
// assignment <select> (CommitteesPage) — every place that displays an
// already-assigned member's role should show this, not the raw lowercase
// pivot value. Backend pivot value stays the same English slug
// ('leader'/'treasurer'/'secretary'/'member') — only the display label
// is translated.
export function committeeRoleLabel(role) {
  return i18n.t(`committeeRoles.${role}`, { ns: 'common', defaultValue: i18n.t('committeeRoles.member', { ns: 'common' }) })
}

export function getRelativeTime(dateValue) {
  const timestamp = new Date(dateValue).getTime()

  if (Number.isNaN(timestamp)) return i18n.t('relativeTime.recently', { ns: 'common' })

  const diffMinutes = Math.max(1, Math.round((Date.now() - timestamp) / 60000))

  if (diffMinutes < 60) return i18n.t('relativeTime.minutesAgo', { ns: 'common', count: diffMinutes })

  const diffHours = Math.round(diffMinutes / 60)

  if (diffHours < 24) return i18n.t('relativeTime.hoursAgo', { ns: 'common', count: diffHours })

  return i18n.t('relativeTime.daysAgo', { ns: 'common', count: Math.round(diffHours / 24) })
}
