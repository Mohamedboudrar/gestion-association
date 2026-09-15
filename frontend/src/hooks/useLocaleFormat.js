const CURRENCY_CODE = 'MAD'
const DEFAULT_DATE_OPTIONS = { day: 'numeric', month: 'long', year: 'numeric' }
const locale = 'fr-FR'

// Centralizes French-locale date/number/currency formatting (Intl APIs,
// no extra dependency) so every page renders dates/amounts the same way —
// "11 août 2026", "1 234", "1 234 MAD" — instead of each component calling
// toLocaleDateString()/manual string building with English defaults.
export function useLocaleFormat() {
  function formatDate(value, options = DEFAULT_DATE_OPTIONS) {
    if (!value) return ''
    const date = value instanceof Date ? value : new Date(value)
    if (Number.isNaN(date.getTime())) return ''
    return new Intl.DateTimeFormat(locale, options).format(date)
  }

  function formatDateTime(value) {
    return formatDate(value, { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
  }

  function formatShortDate(value) {
    return formatDate(value, { day: 'numeric', month: 'short', year: 'numeric' })
  }

  function formatNumber(value, options) {
    if (value === null || value === undefined || value === '') return ''
    const number = Number(value)
    if (Number.isNaN(number)) return ''
    return new Intl.NumberFormat(locale, options).format(number)
  }

  function formatCurrency(value) {
    if (value === null || value === undefined || value === '') return ''
    return `${formatNumber(value, { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${CURRENCY_CODE}`
  }

  return { locale, formatDate, formatDateTime, formatShortDate, formatNumber, formatCurrency }
}
