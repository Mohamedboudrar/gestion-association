// Single source of truth for donation workflow status colors/labels — mirrors
// lib/expenseStatus.js exactly (same colors for the statuses both share) so
// the two workflows read as one consistent system. Donations have no "paid"
// state, so this dictionary is one entry shorter than the expense one.
export const STATUS_STYLES = {
  draft: 'bg-slate-100 text-slate-600',
  pending: 'bg-amber-50 text-amber-700',
  approved: 'bg-emerald-50 text-emerald-700',
  rejected: 'bg-rose-50 text-rose-700',
}

// Display labels are translated — see hooks/useStatusLabel.js (common:status.*)
// instead of a hardcoded English dictionary here.

// Matches backend Donation::FINANCIALLY_COUNTED_STATUSES — the only status
// that represents money actually collected for a project. Every client-side
// sum over a raw donation list must filter through this first;
// draft/pending/rejected donations never count.
export const FINANCIALLY_COUNTED_STATUSES = ['approved']

export function isFinanciallyCountedDonation(donation) {
  return FINANCIALLY_COUNTED_STATUSES.includes(donation?.status)
}
