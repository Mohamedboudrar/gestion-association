import { FolderKanban } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { getRelativeTime } from '../committee/committeeUtils'
import { statusToneClass } from '../../lib/activityLog'

function getInitials(name) {
  return String(name ?? 'S')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((value) => value[0])
    .join('')
    .toUpperCase()
}

export default function ActivityCard({ activity, onOpen }) {
  const { t } = useTranslation('association')
  const causerName = activity.causer?.name ?? t('activityLog.card.system')

  return (
    <button
      type="button"
      onClick={() => onOpen(activity)}
      className="flex w-full items-start gap-3 px-6 py-4 text-left transition hover:bg-[#fbfcff]"
    >
      <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 to-indigo-600 text-xs font-semibold text-white">
        {getInitials(causerName)}
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <p className="font-semibold text-slate-800">{activity.message}</p>
          {activity.status ? (
            <span className={`inline-flex shrink-0 items-center rounded-full px-2.5 py-1 text-xs font-semibold leading-none ${statusToneClass(activity.status.value)}`}>
              {activity.status.label}
            </span>
          ) : null}
        </div>

        <p className="mt-1 text-sm text-slate-500">{getRelativeTime(activity.created_at)}</p>

        {activity.project ? (
          <p className="mt-2 flex items-center gap-1.5 text-xs font-medium text-slate-500">
            <FolderKanban size={13} className="text-slate-400" />
            {t('activityLog.card.project')} <span className="text-slate-700">{activity.project.name}</span>
          </p>
        ) : null}
      </div>
    </button>
  )
}
