import { useTranslation } from 'react-i18next'
import { formatCurrency, formatDate } from '../committee/committeeUtils'

function StatList({ title, items, renderItem, emptyText }) {
  return (
    <div className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-4">
      <p className="mb-3 text-xs font-semibold uppercase tracking-[0.1em] text-slate-400">{title}</p>
      {items.length === 0 ? (
        <p className="text-sm text-slate-500">{emptyText}</p>
      ) : (
        <div className="space-y-2">{items.map(renderItem)}</div>
      )}
    </div>
  )
}

export default function AssociationStatistics({
  topFunded,
  topDonorList,
  topSpending,
  finishingThisMonth,
  newestMembers,
}) {
  const { t } = useTranslation('dashboard')

  return (
    <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-sm">
      <h2 className="mb-4 text-lg font-semibold text-slate-900">{t('quickStats.title')}</h2>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
        <StatList
          title={t('quickStats.topFundedProjects')}
          items={topFunded}
          emptyText={t('quickStats.noProjectsYet')}
          renderItem={(p) => (
            <div key={p.id} className="flex items-center justify-between text-sm">
              <span className="truncate text-slate-700">{p.name}</span>
              <span className="shrink-0 tabular-nums text-blue-700">{formatCurrency(p.collected)}</span>
            </div>
          )}
        />

        <StatList
          title={t('quickStats.topDonors')}
          items={topDonorList}
          emptyText={t('quickStats.noDonationsYet')}
          renderItem={(d) => (
            <div key={d.name} className="flex items-center justify-between text-sm">
              <span className="truncate text-slate-700">{d.name}</span>
              <span className="shrink-0 tabular-nums text-emerald-600">{formatCurrency(d.total)}</span>
            </div>
          )}
        />

        <StatList
          title={t('quickStats.highestSpending')}
          items={topSpending}
          emptyText={t('quickStats.noExpensesYet')}
          renderItem={(p) => (
            <div key={p.id} className="flex items-center justify-between text-sm">
              <span className="truncate text-slate-700">{p.name}</span>
              <span className="shrink-0 tabular-nums text-rose-600">{formatCurrency(p.expenses)}</span>
            </div>
          )}
        />

        <StatList
          title={t('quickStats.finishingThisMonth')}
          items={finishingThisMonth}
          emptyText={t('quickStats.nothingFinishing')}
          renderItem={(p) => (
            <div key={p.id} className="flex items-center justify-between text-sm">
              <span className="truncate text-slate-700">{p.name}</span>
              <span className="shrink-0 text-xs text-slate-400">{formatDate(p.end_date)}</span>
            </div>
          )}
        />

        <StatList
          title={t('quickStats.newestSubscribers')}
          items={newestMembers}
          emptyText={t('quickStats.noSubscribersYet')}
          renderItem={(m) => (
            <div key={m.id} className="flex items-center justify-between text-sm">
              <span className="truncate text-slate-700">{m.user?.name ?? t('quickStats.unknown')}</span>
              <span className="shrink-0 text-xs text-slate-400">{formatDate(m.created_at)}</span>
            </div>
          )}
        />
      </div>
    </section>
  )
}
