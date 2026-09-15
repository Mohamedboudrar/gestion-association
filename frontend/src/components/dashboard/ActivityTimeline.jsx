import { History } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { describeActivity, groupActivityByRecency } from '../../lib/dashboardMetrics'
import { getRelativeTime } from '../committee/committeeUtils'

export default function ActivityTimeline({ logs }) {
  const { t } = useTranslation('dashboard')
  const todaysActivity = groupActivityByRecency(logs).Today.slice(0, 5)

  return (
    <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-sm">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-slate-900">{t('activityTimeline.title')}</h2>
        <p className="text-xs text-slate-400">{t('activityTimeline.today')}</p>
      </div>

      {todaysActivity.length === 0 ? (
        <div className="flex flex-col items-center gap-2 py-8 text-center">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-50 text-slate-400">
            <History size={20} />
          </div>
          <p className="text-sm font-semibold text-slate-700">{t('activityTimeline.empty')}</p>
          <p className="text-xs text-slate-400">{t('activityTimeline.emptyNote')}</p>
        </div>
      ) : (
        <div className="space-y-3">
          {todaysActivity.map((log) => (
            <div key={log.id} className="flex items-center justify-between gap-3 text-sm">
              <p className="min-w-0 truncate text-slate-700">{describeActivity(log)}</p>
              <span className="shrink-0 text-xs text-slate-400">{getRelativeTime(log.created_at)}</span>
            </div>
          ))}
        </div>
      )}

      <Link
        to="/activity-logs"
        className="mt-5 flex items-center justify-center rounded-xl border border-[#dfe5ff] px-4 py-2.5 text-sm font-semibold text-blue-600 transition duration-150 hover:bg-blue-50"
      >
        {t('activityTimeline.seeMore')}
      </Link>
    </section>
  )
}
