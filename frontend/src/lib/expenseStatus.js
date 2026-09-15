// Single source of truth for expense workflow status colors/labels — shared
// by the status badge and the status filter tabs so they never drift apart.
export const STATUS_STYLES = {
  draft: 'bg-slate-100 text-slate-600',
  pending: 'bg-amber-50 text-amber-700',
  approved: 'bg-emerald-50 text-emerald-700',
  rejected: 'bg-rose-50 text-rose-700',
  paid: 'bg-blue-50 text-blue-700',
}

// Display labels are translated — see hooks/useStatusLabel.js (common:status.*)
// instead of a hardcoded English dictionary here.

// Matches backend Expense::FINANCIALLY_COUNTED_STATUSES — the only statuses
// that represent money actually committed against a project's funds. Every
// client-side sum over a raw expense list must filter through this first;
// rejected/pending/draft expenses never count.
export const FINANCIALLY_COUNTED_STATUSES = ['approved', 'paid']

export function isFinanciallyCountedExpense(expense) {
  return FINANCIALLY_COUNTED_STATUSES.includes(expense?.status)
}
