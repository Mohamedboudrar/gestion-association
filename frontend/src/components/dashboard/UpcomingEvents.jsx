import { CalendarClock } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { formatDate } from '../committee/committeeUtils'

export default function UpcomingEvents({ expiringSubs, endingProjects }) {
  const { t } = useTranslation('dashboard')

  const items = [
    ...expiringSubs.map((s) => ({
      id: `sub-${s.id}`,
      label: t('upcoming.subscriptionRenewal', { name: s.member?.user?.name ?? t('upcoming.subscriberFallback') }),
      date: s.expires_at,
    })),
    ...endingProjects.map((p) => ({
      id: `proj-${p.id}`,
      label: t('upcoming.projectDeadline', { name: p.name }),
      date: p.end_date,
    })),
  ].sort((a, b) => new Date(a.date) - new Date(b.date))

  return (
    <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-sm">
      <h2 className="mb-1 text-lg font-semibold text-slate-900">{t('upcoming.title')}</h2>
      <p className="mb-4 text-sm text-slate-500">{t('upcoming.next30Days')}</p>

      {items.length === 0 ? (
        <div className="flex flex-col items-center gap-2 py-8 text-center">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-50 text-slate-400">
            <CalendarClock size={20} />
          </div>
          <p className="text-sm font-semibold text-slate-700">{t('upcoming.empty')}</p>
          <p className="text-xs text-slate-400">{t('upcoming.emptyNote')}</p>
        </div>
      ) : (
        <div className="space-y-3">
          {items.map((item) => (
            <div key={item.id} className="flex items-center gap-3 text-sm">
              <CalendarClock size={16} className="shrink-0 text-slate-400" />
              <p className="min-w-0 flex-1 truncate text-slate-700">{item.label}</p>
              <span className="shrink-0 text-xs text-slate-400">{formatDate(item.date)}</span>
            </div>
          ))}
        </div>
      )}

      {/* TODO(backend): no events/calendar model exists yet, so annual assembly dates and
          committee meetings can't be listed here — only real subscription/project dates. */}
      <p className="mt-4 border-t border-dashed border-[#eef2ff] pt-3 text-xs text-slate-400">
        {t('upcoming.footnote')}
      </p>
    </section>
  )
}
