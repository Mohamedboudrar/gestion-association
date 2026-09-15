import { Banknote, FolderKanban, ShieldCheck, Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { formatCurrency } from '../committee/committeeUtils'

function ProgressRow({ label, value, total, tone }) {
  const pct = total > 0 ? Math.round((value / total) * 100) : 0

  return (
    <div className="flex items-center gap-3 text-xs">
      <span className="w-20 shrink-0 text-slate-500">{label}</span>
      <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-slate-100">
        <div className={`h-full rounded-full ${tone}`} style={{ width: `${pct}%` }} />
      </div>
      <span className="w-8 shrink-0 text-right font-semibold text-slate-700">{value}</span>
    </div>
  )
}

// Top-tier "important" card — the strongest shadow on the page, reserved for
// the KPI row and the Revenue/Action Center row right below it. Everything
// further down the page uses the flatter `shadow-sm` "normal card" tier.
function KpiCard({ icon: Icon, iconTone, title, headline, headlineTone, children }) {
  return (
    <div className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)] transition duration-200 hover:shadow-[0_14px_30px_rgba(148,163,184,0.14)]">
      <div className="mb-4 flex items-center justify-between">
        <div className={`flex h-11 w-11 items-center justify-center rounded-2xl ${iconTone}`}>
          <Icon size={20} />
        </div>
      </div>
      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{title}</p>
      <h3 className={`mt-2 text-3xl font-bold leading-tight tracking-tight ${headlineTone}`}>{headline}</h3>
      <div className="mt-5 space-y-2">{children}</div>
    </div>
  )
}

export default function ExecutiveKPIs({ availableFunds, revenueTrend, projectBreakdown, subscriptionStats, committeeSummary }) {
  const { t } = useTranslation(['dashboard', 'common'])
  const trendUp = revenueTrend?.trend === 'up'
  const fundsKnown = availableFunds != null
  const fundsNegative = fundsKnown && availableFunds < 0

  return (
    <section className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
      <KpiCard
        icon={Banknote}
        iconTone={fundsNegative ? 'bg-rose-50 text-rose-600' : 'bg-emerald-50 text-emerald-600'}
        title={t('kpis.availableFunds')}
        headline={formatCurrency(availableFunds)}
        headlineTone={!fundsKnown ? 'text-slate-400' : fundsNegative ? 'text-rose-600' : 'text-emerald-600'}
      >
        <p className="text-xs text-slate-500">
          {fundsNegative ? t('kpis.availableFundsNegative') : t('kpis.availableFundsPositive')}
        </p>
        {revenueTrend?.change ? (
          <p className={`text-xs font-semibold ${trendUp ? 'text-emerald-600' : 'text-rose-600'}`}>
            {trendUp ? '▲' : '▼'} {revenueTrend.change} {t('kpis.revenueVsLastMonth')}
          </p>
        ) : null}
      </KpiCard>

      <KpiCard
        icon={FolderKanban}
        iconTone="bg-blue-50 text-blue-600"
        title={t('kpis.projects')}
        headline={projectBreakdown.total}
        headlineTone="text-slate-900"
      >
        <ProgressRow label={t('kpis.inSetup')} value={projectBreakdown.inSetup} total={projectBreakdown.total} tone="bg-amber-400" />
        <ProgressRow label={t('kpis.active')} value={projectBreakdown.active} total={projectBreakdown.total} tone="bg-blue-500" />
        <ProgressRow label={t('kpis.completed')} value={projectBreakdown.completed} total={projectBreakdown.total} tone="bg-emerald-500" />
        <ProgressRow label={t('kpis.delayed')} value={projectBreakdown.delayed} total={projectBreakdown.total} tone="bg-rose-500" />
      </KpiCard>

      <KpiCard
        icon={Users}
        iconTone="bg-indigo-50 text-indigo-600"
        title={t('kpis.subscribers')}
        headline={subscriptionStats.total}
        headlineTone="text-slate-900"
      >
        <ProgressRow label={t('common:status.verified')} value={subscriptionStats.verified} total={subscriptionStats.total} tone="bg-emerald-500" />
        <ProgressRow label={t('common:status.pending')} value={subscriptionStats.pending} total={subscriptionStats.total} tone="bg-amber-400" />
        <ProgressRow label={t('common:status.expired')} value={subscriptionStats.expired} total={subscriptionStats.total} tone="bg-rose-500" />
      </KpiCard>

      <KpiCard
        icon={ShieldCheck}
        iconTone="bg-purple-50 text-purple-600"
        title={t('kpis.committees')}
        headline={committeeSummary.total}
        headlineTone="text-slate-900"
      >
        <ProgressRow label={t('kpis.working')} value={committeeSummary.green} total={committeeSummary.total} tone="bg-emerald-500" />
        <ProgressRow label={t('kpis.attention')} value={committeeSummary.yellow} total={committeeSummary.total} tone="bg-amber-400" />
        <ProgressRow label={t('kpis.blocked')} value={committeeSummary.red} total={committeeSummary.total} tone="bg-rose-500" />
        {/* TODO(backend): "missing final report" isn't tracked — no report_submitted field exists on projects. */}
      </KpiCard>
    </section>
  )
}
