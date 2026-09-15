import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { getRelativeTime } from '../committee/committeeUtils'
import { iconForCategory } from '../../lib/notifications'

function SkeletonRow() {
  return (
    <div className="flex items-start gap-3 px-4 py-3">
      <div className="mt-0.5 h-9 w-9 shrink-0 animate-pulse rounded-full bg-slate-100" />
      <div className="min-w-0 flex-1">
        <div className="h-3.5 w-2/3 animate-pulse rounded bg-slate-200" />
        <div className="mt-2 h-3 w-full animate-pulse rounded bg-slate-100" />
        <div className="mt-2 h-2.5 w-1/4 animate-pulse rounded bg-slate-100" />
      </div>
    </div>
  )
}

export default function NotificationDropdown({ open, notifications, loading, onMarkRead, onMarkAllRead, onClose }) {
  const navigate = useNavigate()
  const { t } = useTranslation('notifications')
  const unreadCount = notifications.filter((n) => !n.is_read).length

  function handleOpen(notification) {
    if (!notification.is_read) onMarkRead(notification.id)
    onClose?.()

    if (notification.link) navigate(notification.link)
  }

  return (
    <div
      className={`absolute right-0 top-full z-30 mt-2 w-[400px] max-w-[calc(100vw-2rem)] origin-top-right rounded-2xl border border-[#dfe5ff] bg-white shadow-[0_20px_45px_rgba(85,100,180,0.18)] transition-all duration-150 ease-out ${
        open
          ? 'pointer-events-auto translate-y-0 opacity-100'
          : 'pointer-events-none -translate-y-2 opacity-0'
      }`}
      role="menu"
      aria-hidden={!open}
    >
      <div className="flex items-center justify-between border-b border-[#eef2ff] px-4 py-3">
        <div className="flex items-center gap-2">
          <h3 className="text-sm font-semibold text-slate-900">{t('title')}</h3>
          {unreadCount > 0 ? (
            <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1.5 text-[11px] font-semibold text-white">
              {unreadCount > 9 ? '9+' : unreadCount}
            </span>
          ) : null}
        </div>

        {unreadCount > 0 ? (
          <button
            type="button"
            onClick={onMarkAllRead}
            className="text-xs font-semibold text-blue-600 hover:text-blue-700"
          >
            {t('markAllAsRead')}
          </button>
        ) : null}
      </div>

      <div className="max-h-[500px] overflow-y-auto">
        {loading ? (
          <div className="divide-y divide-[#eef2ff]">
            <SkeletonRow />
            <SkeletonRow />
            <SkeletonRow />
          </div>
        ) : notifications.length === 0 ? (
          <p className="px-4 py-6 text-sm text-slate-500">{t('empty')}</p>
        ) : (
          <ul className="divide-y divide-[#eef2ff]">
            {notifications.map((notification) => {
              const Icon = iconForCategory(notification.category)

              return (
                <li key={notification.id}>
                  <button
                    type="button"
                    onClick={() => handleOpen(notification)}
                    className={`flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-[#f7f9ff] ${
                      notification.is_read ? '' : 'bg-[#fbfcff]'
                    }`}
                  >
                    <div className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                      <Icon size={16} />
                    </div>

                    <div className="min-w-0 flex-1">
                      <p className="text-sm font-semibold text-slate-800">{notification.title}</p>
                      {notification.message ? (
                        <p className="mt-0.5 text-sm text-slate-500">{notification.message}</p>
                      ) : null}
                      <p className="mt-1 text-xs text-slate-400">{getRelativeTime(notification.created_at)}</p>
                    </div>

                    {!notification.is_read ? (
                      <span className="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-blue-600" />
                    ) : null}
                  </button>
                </li>
              )
            })}
          </ul>
        )}
      </div>

      <div className="border-t border-[#eef2ff] px-4 py-2.5 text-center">
        <button
          type="button"
          onClick={() => {
            onClose?.()
            navigate('/notifications')
          }}
          className="text-xs font-semibold text-blue-600 hover:text-blue-700"
        >
          {t('viewAll')}
        </button>
      </div>
    </div>
  )
}
