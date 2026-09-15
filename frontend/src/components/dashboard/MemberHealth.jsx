import { useTranslation } from 'react-i18next'

function StatTile({ label, value, tone }) {
  return (
    <div className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-4">
      <p className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-400">{label}</p>
      <p className={`mt-1 text-2xl font-bold ${tone}`}>{value}</p>
    </div>
  )
}

export default function MemberHealth({ newThisMonth, expiringCount, inactiveCount }) {
  const { t } = useTranslation('dashboard')

  return (
    <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-sm">
      <h2 className="mb-4 text-lg font-semibold text-slate-900">{t('memberHealth.title')}</h2>

      <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
        <StatTile label={t('memberHealth.newThisMonth')} value={newThisMonth} tone="text-blue-700" />
        <StatTile label={t('memberHealth.expiring30d')} value={expiringCount} tone="text-amber-600" />
        <StatTile label={t('memberHealth.inactive')} value={inactiveCount} tone="text-rose-600" />
      </div>

      {/* TODO(backend): there's no dues/fee-schedule model, so "outstanding balance" per member
          can't be computed — subscriptions only record payments already made, not what's owed. */}
      <p className="mt-4 border-t border-dashed border-[#eef2ff] pt-3 text-xs text-slate-400">
        {t('memberHealth.footnote')}
      </p>
    </section>
  )
}
