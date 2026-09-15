import { ChevronLeft, ChevronRight, Search, SlidersHorizontal, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import ActivityCard from '../components/activity/ActivityCard'
import ActivityDetailPanel from '../components/activity/ActivityDetailPanel'
import { getActivityLogFilters, getActivityLogs } from '../api/activityLogs.api'
import { useAuth } from '../context/auth-context'
import { isBureauMember } from '../lib/roles'
import { getActionOptions, getDatePresets, groupActivitiesByDay, presetToDateFrom } from '../lib/activityLog'

const PER_PAGE = 25

const selectClass =
  'h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm text-slate-700 outline-none transition focus:border-blue-300'

export default function ActivityLogsPage() {
  const { t } = useTranslation('association')
  const { user } = useAuth()

  const ACTION_OPTIONS = getActionOptions()
  const DATE_PRESETS = getDatePresets()

  const [searchInput, setSearchInput] = useState('')
  const [search, setSearch] = useState('')
  const [userId, setUserId] = useState('')
  const [entity, setEntity] = useState('')
  const [action, setAction] = useState('')
  const [datePreset, setDatePreset] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [projectId, setProjectId] = useState('')
  const [status, setStatus] = useState('')
  const [sort, setSort] = useState('desc')
  const [page, setPage] = useState(1)

  const [filterOptions, setFilterOptions] = useState({ users: [], projects: [], entities: [] })
  const [activities, setActivities] = useState([])
  const [total, setTotal] = useState(0)
  const [lastPage, setLastPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')
  const [showFilters, setShowFilters] = useState(false)
  const [openActivityId, setOpenActivityId] = useState(null)

  // Debounce the search box — reload 400ms after the user stops typing,
  // rather than firing a request per keystroke.
  useEffect(() => {
    const timeout = setTimeout(() => setSearch(searchInput.trim()), 400)
    return () => clearTimeout(timeout)
  }, [searchInput])

  // Any filter change resets back to page 1 — an active filter combination
  // rarely still has enough pages for the current page number to make sense.
  useEffect(() => {
    setPage(1)
  }, [search, userId, entity, action, dateFrom, dateTo, projectId, status, sort])

  useEffect(() => {
    getActivityLogFilters()
      .then(setFilterOptions)
      .catch(() => {
        // Filter dropdowns just stay empty — the page itself still works.
      })
  }, [])

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    setErrorMessage('')

    getActivityLogs({
      search: search || undefined,
      user_id: userId || undefined,
      entity: entity || undefined,
      action: action || undefined,
      date_from: dateFrom || undefined,
      date_to: dateTo || undefined,
      project_id: projectId || undefined,
      status: status || undefined,
      sort,
      page,
      per_page: PER_PAGE,
    })
      .then((response) => {
        if (cancelled) return
        setActivities(response.data ?? [])
        setTotal(response.total ?? 0)
        setLastPage(response.last_page ?? 1)
      })
      .catch((error) => {
        if (cancelled) return
        setErrorMessage(error.response?.data?.message ?? t('activityLog.page.loadError'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search, userId, entity, action, dateFrom, dateTo, projectId, status, sort, page])

  const groups = useMemo(() => groupActivitiesByDay(activities), [activities])

  if (!isBureauMember(user)) {
    return <Navigate to="/dashboard" replace />
  }

  const activeEntity = filterOptions.entities.find((item) => item.key === entity)
  const statusOptions = activeEntity?.statuses ?? []

  const activeFilterCount = [userId, entity, action, dateFrom, dateTo, projectId, status].filter(Boolean).length

  function handleDatePreset(value) {
    setDatePreset(value)
    setDateFrom(presetToDateFrom(value))
    setDateTo('')
  }

  function handleClearFilters() {
    setSearchInput('')
    setSearch('')
    setUserId('')
    setEntity('')
    setAction('')
    setDatePreset('')
    setDateFrom('')
    setDateTo('')
    setProjectId('')
    setStatus('')
  }

  return (
    <PresidentLayout
      title={t('activityLog.page.title')}
      description={t('activityLog.page.description')}
      headerActions={
        <button
          type="button"
          onClick={() => setShowFilters((current) => !current)}
          className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
        >
          <SlidersHorizontal size={16} />
          {t('activityLog.page.filters')}
          {activeFilterCount > 0 ? (
            <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-600 px-1 text-[10px] font-semibold text-white">
              {activeFilterCount}
            </span>
          ) : null}
        </button>
      }
    >
      {errorMessage ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{errorMessage}</div>
      ) : null}

      <section className="rounded-3xl border border-[#dfe5ff] bg-white p-5 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="relative">
          <Search size={16} className="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-slate-400" />
          <input
            type="text"
            value={searchInput}
            onChange={(event) => setSearchInput(event.target.value)}
            placeholder={t('activityLog.page.searchPlaceholder')}
            className="h-12 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] pl-11 pr-4 text-sm text-slate-800 outline-none transition focus:border-blue-400"
          />
        </div>

        {showFilters ? (
          <div className="mt-5 grid grid-cols-1 gap-4 border-t border-[#eef2ff] pt-5 sm:grid-cols-2 lg:grid-cols-4">
            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{t('activityLog.page.user')}</span>
              <select value={userId} onChange={(event) => setUserId(event.target.value)} className={selectClass}>
                <option value="">{t('activityLog.page.allUsers')}</option>
                {filterOptions.users.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{t('activityLog.page.entity')}</span>
              <select
                value={entity}
                onChange={(event) => {
                  setEntity(event.target.value)
                  setStatus('')
                }}
                className={selectClass}
              >
                <option value="">{t('activityLog.page.allEntities')}</option>
                {filterOptions.entities.map((option) => (
                  <option key={option.key} value={option.key}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{t('activityLog.page.action')}</span>
              <select value={action} onChange={(event) => setAction(event.target.value)} className={selectClass}>
                <option value="">{t('activityLog.page.allActions')}</option>
                {ACTION_OPTIONS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{t('activityLog.page.project')}</span>
              <select value={projectId} onChange={(event) => setProjectId(event.target.value)} className={selectClass}>
                <option value="">{t('activityLog.page.allProjects')}</option>
                {filterOptions.projects.map((option) => (
                  <option key={option.id} value={option.id}>
                    {option.name}
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{t('activityLog.page.dateRange')}</span>
              <select value={datePreset} onChange={(event) => handleDatePreset(event.target.value)} className={selectClass}>
                {DATE_PRESETS.map((option) => (
                  <option key={option.value} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>

            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{t('activityLog.page.from')}</span>
              <input
                type="date"
                value={dateFrom}
                onChange={(event) => {
                  setDatePreset('')
                  setDateFrom(event.target.value)
                }}
                className={selectClass}
              />
            </label>

            <label className="block">
              <span className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{t('activityLog.page.to')}</span>
              <input
                type="date"
                value={dateTo}
                onChange={(event) => {
                  setDatePreset('')
                  setDateTo(event.target.value)
                }}
                className={selectClass}
              />
            </label>

            {statusOptions.length > 0 ? (
              <label className="block">
                <span className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.08em] text-slate-400">{t('activityLog.page.status')}</span>
                <select value={status} onChange={(event) => setStatus(event.target.value)} className={selectClass}>
                  <option value="">{t('activityLog.page.anyStatus')}</option>
                  {statusOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </label>
            ) : null}

            {activeFilterCount > 0 ? (
              <div className="flex items-end">
                <button
                  type="button"
                  onClick={handleClearFilters}
                  className="inline-flex h-11 items-center gap-2 rounded-xl border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                >
                  <X size={14} />
                  {t('activityLog.page.clearFilters')}
                </button>
              </div>
            ) : null}
          </div>
        ) : null}
      </section>

      <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#eef2ff] px-6 py-5">
          <div>
            <h3 className="text-lg font-semibold text-slate-900">{t('activityLog.page.timeline')}</h3>
            <p className="text-sm text-slate-500">{t('activityLog.page.actionsRecorded', { count: total })}</p>
          </div>

          <div className="flex shrink-0 items-center gap-3">
            <div className="flex items-center rounded-full border border-[#dfe5ff] bg-[#f7f9ff] p-1 text-xs font-semibold">
              <button
                type="button"
                onClick={() => setSort('desc')}
                className={`rounded-full px-3 py-1.5 transition ${sort === 'desc' ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'}`}
              >
                {t('activityLog.page.newestFirst')}
              </button>
              <button
                type="button"
                onClick={() => setSort('asc')}
                className={`rounded-full px-3 py-1.5 transition ${sort === 'asc' ? 'bg-white text-blue-700 shadow-sm' : 'text-slate-500'}`}
              >
                {t('activityLog.page.oldestFirst')}
              </button>
            </div>

            {lastPage > 1 ? (
              <div className="flex items-center gap-2">
                <button
                  type="button"
                  onClick={() => setPage((current) => Math.max(1, current - 1))}
                  disabled={page <= 1}
                  className="rounded-full border border-[#dfe5ff] p-2 text-slate-500 transition hover:bg-slate-50 disabled:opacity-40"
                  aria-label={t('activityLog.page.previousPage')}
                >
                  <ChevronLeft size={16} />
                </button>
                <span className="text-xs font-semibold text-slate-500">
                  {t('activityLog.page.pageOf', { page, lastPage })}
                </span>
                <button
                  type="button"
                  onClick={() => setPage((current) => Math.min(lastPage, current + 1))}
                  disabled={page >= lastPage}
                  className="rounded-full border border-[#dfe5ff] p-2 text-slate-500 transition hover:bg-slate-50 disabled:opacity-40"
                  aria-label={t('activityLog.page.nextPage')}
                >
                  <ChevronRight size={16} />
                </button>
              </div>
            ) : null}
          </div>
        </div>

        {loading ? (
          <div className="space-y-3 px-6 py-8">
            <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
          </div>
        ) : groups.length === 0 ? (
          <div className="px-6 py-10 text-center text-sm text-slate-500">
            {t('activityLog.page.empty')}
          </div>
        ) : (
          groups.map((group) => (
            <div key={group.key}>
              <div className="border-b border-t border-[#eef2ff] bg-[#fbfcff] px-6 py-2 text-xs font-semibold uppercase tracking-[0.1em] text-slate-400">
                {group.label}
              </div>
              <div className="divide-y divide-[#eef2ff]">
                {group.items.map((activity) => (
                  <ActivityCard key={activity.id} activity={activity} onOpen={(item) => setOpenActivityId(item.id)} />
                ))}
              </div>
            </div>
          ))
        )}
      </section>

      <ActivityDetailPanel activityId={openActivityId} onClose={() => setOpenActivityId(null)} />
    </PresidentLayout>
  )
}
