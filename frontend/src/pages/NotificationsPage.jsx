import { Bell, ChevronLeft, ChevronRight, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import { getRelativeTime } from '../components/committee/committeeUtils'
import {
  deleteNotification,
  getNotifications,
  markAllNotificationsRead,
  markNotificationRead,
} from '../api/notifications.api'
import { getCategoryOptions, iconForCategory } from '../lib/notifications'

const PER_PAGE = 20

const selectClass =
  'h-11 w-full rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm text-slate-700 outline-none transition focus:border-blue-400'

export default function NotificationsPage() {
  const { t } = useTranslation('notifications')
  const navigate = useNavigate()

  const STATUS_TABS = [
    { value: '', label: t('page.statusTabs.all') },
    { value: 'unread', label: t('page.statusTabs.unread') },
    { value: 'read', label: t('page.statusTabs.read') },
  ]
  const CATEGORY_OPTIONS = getCategoryOptions()

  const [status, setStatus] = useState('')
  const [category, setCategory] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [page, setPage] = useState(1)

  const [notifications, setNotifications] = useState([])
  const [total, setTotal] = useState(0)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')

  useEffect(() => {
    setPage(1)
  }, [status, category, dateFrom, dateTo])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setErrorMessage('')

    getNotifications({
      status: status || undefined,
      category: category || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      page,
      per_page: PER_PAGE,
    })
      .then((response) => {
        if (cancelled) return
        setNotifications(response.data ?? [])
        setTotal(response.total ?? 0)
        setLastPage(response.last_page ?? 1)
      })
      .catch((error) => {
        if (!cancelled) setErrorMessage(error.response?.data?.message ?? t('page.loadError'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, category, dateFrom, dateTo, page])

  function updateLocal(id, patch) {
    setNotifications((current) => current.map((n) => (n.id === id ? { ...n, ...patch } : n)))
  }

  async function handleOpen(notification) {
    if (!notification.is_read) {
      updateLocal(notification.id, { is_read: true, read_at: new Date().toISOString() })
      await markNotificationRead(notification.id)
    }

    if (notification.link) navigate(notification.link)
  }

  async function handleMarkRead(event, notification) {
    event.stopPropagation()
    updateLocal(notification.id, { is_read: true, read_at: new Date().toISOString() })
    await markNotificationRead(notification.id)
  }

  async function handleMarkAllRead() {
    setNotifications((current) => current.map((n) => ({ ...n, is_read: true, read_at: n.read_at ?? new Date().toISOString() })))
    await markAllNotificationsRead()
  }

  async function handleDelete(event, notificationId) {
    event.stopPropagation()
    setNotifications((current) => current.filter((n) => n.id !== notificationId))
    setTotal((current) => Math.max(0, current - 1))
    await deleteNotification(notificationId)
  }

  const unreadCount = notifications.filter((n) => !n.is_read).length

  return (
    <PresidentLayout
      title={t('title')}
      description={t('page.description')}
      headerActions={
        unreadCount > 0 ? (
          <button
            type="button"
            onClick={handleMarkAllRead}
            className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            {t('markAllAsRead')}
          </button>
        ) : null
      }
    >
      {errorMessage ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{errorMessage}</div>
      ) : null}

      <section className="rounded-3xl border border-[#dfe5ff] bg-white p-5 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="flex flex-wrap items-center gap-4">
          <div className="flex items-center rounded-full border border-[#dfe5ff] bg-[#f7f9ff] p-1 text-xs font-semibold">
            {STATUS_TABS.map((tab) => (
              <button
                key={tab.value}
                type="button"
                onClick={() => setStatus(tab.value)}
                className={`rounded-full px-3 py-1.5 transition ${status === tab.value ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'}`}
              >
                {tab.label}
              </button>
            ))}
          </div>

          <label className="block">
            <span className="sr-only">{t('page.typeLabel')}</span>
            <select value={category} onChange={(event) => setCategory(event.target.value)} className={selectClass}>
              {CATEGORY_OPTIONS.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
          </label>

          <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">
            {t('page.from')}
            <input
              type="date"
              value={dateFrom}
              onChange={(event) => setDateFrom(event.target.value)}
              className="h-11 rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-400"
            />
          </label>

          <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">
            {t('page.to')}
            <input
              type="date"
              value={dateTo}
              onChange={(event) => setDateTo(event.target.value)}
              className="h-11 rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm text-slate-700 outline-none focus:border-blue-400"
            />
          </label>
        </div>
      </section>

      <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#eef2ff] px-6 py-5">
          <p className="text-sm text-slate-500">{t('page.count', { count: total })}</p>

          {lastPage > 1 ? (
            <div className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setPage((current) => Math.max(1, current - 1))}
                disabled={page <= 1}
                className="rounded-full border border-[#dfe5ff] p-2 text-slate-500 transition hover:bg-slate-50 disabled:opacity-40"
                aria-label={t('page.previousPage')}
              >
                <ChevronLeft size={16} />
              </button>
              <span className="text-xs font-semibold text-slate-500">
                {t('page.pageOf', { page, lastPage })}
              </span>
              <button
                type="button"
                onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
                disabled={page >= lastPage}
                className="rounded-full border border-[#dfe5ff] p-2 text-slate-500 transition hover:bg-slate-50 disabled:opacity-40"
                aria-label={t('page.nextPage')}
              >
                <ChevronRight size={16} />
              </button>
            </div>
          ) : null}
        </div>

        {loading ? (
          <div className="space-y-3 px-6 py-8">
            <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
          </div>
        ) : notifications.length === 0 ? (
          <div className="flex flex-col items-center gap-2 px-6 py-12 text-center text-sm text-slate-500">
            <Bell size={22} className="text-slate-300" />
            {t('page.empty')}
          </div>
        ) : (
          <div className="divide-y divide-[#eef2ff]">
            {notifications.map((notification) => {
              const Icon = iconForCategory(notification.category)

              return (
                <div
                  key={notification.id}
                  role="button"
                  tabIndex={0}
                  onClick={() => handleOpen(notification)}
                  onKeyDown={(event) => event.key === 'Enter' && handleOpen(notification)}
                  className={`flex cursor-pointer items-start gap-3 px-6 py-4 text-left transition hover:bg-[#fbfcff] ${
                    notification.is_read ? '' : 'bg-[#fbfcff]'
                  }`}
                >
                  <div className="mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-blue-50 text-blue-600">
                    <Icon size={18} />
                  </div>

                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-semibold text-slate-800">{notification.title}</p>
                      {!notification.is_read ? <span className="h-2 w-2 shrink-0 rounded-full bg-blue-600" /> : null}
                    </div>
                    {notification.message ? <p className="mt-0.5 text-sm text-slate-500">{notification.message}</p> : null}
                    <p className="mt-1 text-xs text-slate-400">{getRelativeTime(notification.created_at)}</p>
                  </div>

                  <div className="flex shrink-0 items-center gap-2">
                    {!notification.is_read ? (
                      <button
                        type="button"
                        onClick={(event) => handleMarkRead(event, notification)}
                        className="rounded-full border border-[#dfe5ff] bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                      >
                        {t('page.markRead')}
                      </button>
                    ) : null}
                    <button
                      type="button"
                      onClick={(event) => handleDelete(event, notification.id)}
                      aria-label={t('page.deleteAria')}
                      className="rounded-full border border-[#dfe5ff] bg-white p-2 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                    >
                      <Trash2 size={14} />
                    </button>
                  </div>
                </div>
              )
            })}
          </div>
        )}
      </section>
    </PresidentLayout>
  )
}
