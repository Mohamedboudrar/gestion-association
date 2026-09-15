import { useTranslation } from 'react-i18next'
import { formatCurrency } from '../committee/committeeUtils'

function StatTile({ label, value, tone }) {
  return (
    <div className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-5">
      <p className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-400">{label}</p>
      <p className={`mt-1.5 text-2xl font-bold ${tone}`}>{value}</p>
    </div>
  )
}

// Reuses the exact `dues` block DashboardController::index() already
// returns (GET /api/dashboard, fetched by useDashboard()) — no separate
// fetch, purely a display of figures the page already has.
export default function DuesOverview({ dues }) {
  const { t } = useTranslation('dashboard')

  if (!dues) return null

  return (
    <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-sm">
      <h2 className="mb-4 text-lg font-semibold text-slate-900">{t('dues.title', { year: dues.year })}</h2>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-3 xl:grid-cols-5">
        <StatTile label={t('dues.expected')} value={formatCurrency(dues.expected)} tone="text-slate-900" />
        <StatTile label={t('dues.collected')} value={formatCurrency(dues.collected)} tone="text-emerald-600" />
        <StatTile label={t('dues.outstanding')} value={formatCurrency(dues.outstanding)} tone="text-amber-600" />
        <StatTile label={t('dues.overdueMembers')} value={dues.overdue_members} tone="text-rose-600" />
        <StatTile label={t('dues.collectionRate')} value={`${dues.collection_rate}%`} tone="text-blue-700" />
      </div>
    </section>
  )
}
