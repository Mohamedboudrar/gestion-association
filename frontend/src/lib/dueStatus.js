// Single source of truth for annual-due status colors/labels — mirrors
// lib/donationStatus.js / lib/expenseStatus.js exactly, so every workflow
// reads as one consistent system.
export const STATUS_STYLES = {
  pending: 'bg-slate-100 text-slate-600',
  partial: 'bg-amber-50 text-amber-700',
  paid: 'bg-emerald-50 text-emerald-700',
  overdue: 'bg-rose-50 text-rose-700',
  waived: 'bg-indigo-50 text-indigo-700',
}

// Display labels are translated — see hooks/useStatusLabel.js (common:status.*)
// instead of a hardcoded English dictionary here.
