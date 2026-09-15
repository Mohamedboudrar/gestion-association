import {
  Banknote,
  Bell,
  Building2,
  ClipboardCheck,
  FileText,
  FolderKanban,
  Gift,
  History,
  LayoutDashboard,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
  Receipt,
  Settings as SettingsIcon,
  Trash2,
  UserCircle2,
  Users,
  UsersRound,
  WalletCards,
  X,
} from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../context/auth-context'
import { useSettings } from '../../context/settings-context'
import { buildBureauNavigation, buildSubscriberNavigation, presidentNavigation } from '../../config/presidentNavigation'
import { hasRole, isBureauMember } from '../../lib/roles'
import { useRoleLabel } from '../../hooks/useStatusLabel'
import NotificationDropdown from './NotificationDropdown'
import { getNotifications, markAllNotificationsRead, markNotificationRead } from '../../api/notifications.api'
import { getActionCenter } from '../../api/actionCenter.api'

const SIDEBAR_COLLAPSED_STORAGE_KEY = 'sidebar-collapsed'
const APPROVALS_BADGE_REFRESH_MS = 60000

function getInitials(name) {
  return String(name ?? 'U')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((value) => value[0])
    .join('')
    .toUpperCase()
}

function getItemIcon(path) {
  if (path.startsWith('/members')) return Users
  if (path.startsWith('/subscriptions/receipts')) return Receipt
  if (path.startsWith('/subscriptions')) return WalletCards
  if (path.startsWith('/deletion-requests')) return Trash2
  if (path.startsWith('/phase-requests')) return ClipboardCheck
  if (path.startsWith('/projects')) return FolderKanban
  if (path.startsWith('/donations')) return Gift
  if (path.startsWith('/expenses')) return Banknote
  if (path.startsWith('/finance')) return Banknote
  if (path.startsWith('/reports')) return FileText
  if (path.startsWith('/committees')) return UsersRound
  if (path.startsWith('/activity-logs')) return History
  if (path.startsWith('/settings')) return SettingsIcon
  if (path.startsWith('/notifications')) return Bell
  return LayoutDashboard
}

function isActivePath(currentPath, itemPath) {
  if (itemPath === '/dashboard') {
    return currentPath === '/dashboard'
  }

  return currentPath === itemPath || currentPath.startsWith(`${itemPath}/`)
}

function SidebarLink({ to, icon: Icon, label, active, collapsed, tone = 'default' }) {
  const toneClasses =
    tone === 'danger'
      ? 'text-rose-500 hover:bg-rose-50'
      : active
        ? 'bg-blue-50 font-semibold text-blue-700 shadow-sm'
        : 'text-slate-500 hover:bg-slate-50 hover:text-slate-900'

  return (
    <Link
      to={to}
      className={`group relative flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200 hover:-translate-y-0.5 hover:shadow-sm ${
        collapsed ? 'lg:justify-center' : ''
      } ${toneClasses}`}
    >
      {active && tone !== 'danger' ? (
        <span className="absolute left-0 top-1/2 h-5 w-1 -translate-y-1/2 rounded-full bg-blue-600" />
      ) : null}
      <Icon size={18} className="shrink-0" />
      <span className={`truncate ${collapsed ? 'lg:hidden' : ''}`}>{label}</span>

      {collapsed ? (
        <span className="pointer-events-none absolute left-full top-1/2 z-50 ml-3 hidden -translate-y-1/2 whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100 lg:block">
          {label}
        </span>
      ) : null}
    </Link>
  )
}

export default function PresidentLayout({
  title,
  description,
  // eslint-disable-next-line no-unused-vars -- accepted for API compatibility with existing page callers, no longer rendered
  breadcrumbs = [],
  children,
  headerActions = null,
}) {
  const location = useLocation()
  const { t } = useTranslation()
  const roleLabel = useRoleLabel()
  const { user, logout } = useAuth()
  const { settings } = useSettings()
  const [notifications, setNotifications] = useState([])
  const [unreadNotificationCount, setUnreadNotificationCount] = useState(0)
  const [loadingNotifications, setLoadingNotifications] = useState(true)
  const [showNotifications, setShowNotifications] = useState(false)
  const [collapsed, setCollapsed] = useState(
    () => typeof window !== 'undefined' && window.localStorage.getItem(SIDEBAR_COLLAPSED_STORAGE_KEY) === 'true',
  )
  const [mobileNavOpen, setMobileNavOpen] = useState(false)
  const notificationsRef = useRef(null)
  const isPresident = hasRole(user, 'president')
  const isBureau = isBureauMember(user)
  const [approvalsCount, setApprovalsCount] = useState(0)

  useEffect(() => {
    window.localStorage.setItem(SIDEBAR_COLLAPSED_STORAGE_KEY, String(collapsed))
  }, [collapsed])

  // Sidebar badge for the Approvals group only (per design: notification counts
  // shouldn't repeat on every page/section, just the one that queues decisions).
  // Backed by the same single /action-center endpoint the Action Center
  // widget uses — one lightweight, server-filtered request instead of the
  // four full-list fetches this used to make (subscriptions/expenses/phase
  // requests/deletion requests, each pulled in full and filtered to
  // 'pending' in the browser). Donations aren't counted here, matching the
  // Approvals group's existing scope — donations have their own page, not a
  // sidebar entry.
  useEffect(() => {
    if (!isPresident) return undefined

    let cancelled = false

    function load() {
      getActionCenter()
        .then(({ counts }) => {
          if (cancelled) return
          setApprovalsCount(counts.subscriptions + counts.expenses + counts.phase_requests + counts.deletion_requests)
        })
        .catch((error) => console.error('Failed to load approvals count', error))
    }

    load()
    const interval = setInterval(load, APPROVALS_BADGE_REFRESH_MS)

    return () => {
      cancelled = true
      clearInterval(interval)
    }
  }, [isPresident])

  useEffect(() => {
    setMobileNavOpen(false)
  }, [location.pathname])

  // Polls for new notifications every 45s while the user is logged in, so
  // the bell badge and dropdown update without a page refresh — this app
  // has no websocket/broadcasting infrastructure, so polling is the
  // practical "real-time" mechanism here, not a full push stack.
  useEffect(() => {
    let cancelled = false

    function load(showSpinner) {
      if (showSpinner) setLoadingNotifications(true)

      getNotifications({ per_page: 10 })
        .then((response) => {
          if (cancelled) return
          setNotifications(response.data ?? [])
          // Authoritative count from the backend — counting only the 10
          // most recent notifications fetched here would undercount the
          // moment there's more than a page of unread ones.
          setUnreadNotificationCount(response.unread_count ?? 0)
        })
        .finally(() => {
          if (!cancelled && showSpinner) setLoadingNotifications(false)
        })
    }

    load(true)
    const interval = setInterval(() => load(false), 45000)

    return () => {
      cancelled = true
      clearInterval(interval)
    }
  }, [])

  useEffect(() => {
    if (!showNotifications) return undefined

    function handlePointerDown(event) {
      if (notificationsRef.current && !notificationsRef.current.contains(event.target)) {
        setShowNotifications(false)
      }
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') setShowNotifications(false)
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [showNotifications])

  async function handleMarkRead(notificationId) {
    let wasUnread = false
    setNotifications((current) =>
      current.map((n) => {
        if (n.id !== notificationId) return n
        wasUnread = !n.is_read
        return { ...n, is_read: true, read_at: new Date().toISOString() }
      }),
    )
    if (wasUnread) setUnreadNotificationCount((current) => Math.max(0, current - 1))
    await markNotificationRead(notificationId)
  }

  async function handleMarkAllRead() {
    setNotifications((current) =>
      current.map((n) => ({ ...n, is_read: true, read_at: n.read_at ?? new Date().toISOString() })),
    )
    setUnreadNotificationCount(0)
    await markAllNotificationsRead()
  }

  const displayName = user?.name ?? t('roles.president')
  const role = user?.roles?.[0]?.name ?? user?.role ?? 'president'
  const initials = getInitials(displayName)
  const navigation = isPresident
    ? presidentNavigation
    : isBureau
      ? buildBureauNavigation(user)
      : buildSubscriberNavigation()
  const workspaceSubtitle = isPresident
    ? t('workspace.presidentControlCenter')
    : isBureau
      ? t('workspace.roleWorkspace', { role: roleLabel(role) })
      : t('workspace.subscriberDashboard')

  return (
    <div className="min-h-screen bg-[#eef2ff] text-slate-800">
      <div className="flex min-h-screen w-full overflow-hidden rounded-[28px] border border-[#cfd7ff] bg-[#f8faff] shadow-[0_24px_80px_rgba(85,100,180,0.12)]">
        {mobileNavOpen ? (
          <div
            className="fixed inset-0 z-30 bg-slate-900/40 backdrop-blur-sm lg:hidden"
            onClick={() => setMobileNavOpen(false)}
            aria-hidden="true"
          />
        ) : null}

        <aside
          className={`fixed inset-y-0 left-0 z-40 flex w-[280px] shrink-0 flex-col border-r border-[#dfe5ff] bg-white/95 shadow-2xl transition-transform duration-200 ease-in-out lg:static lg:z-auto lg:shadow-none lg:transition-[width] ${
            mobileNavOpen ? 'translate-x-0' : '-translate-x-full'
          } lg:translate-x-0 ${collapsed ? 'lg:w-20' : 'lg:w-[280px]'}`}
        >
          <div className="flex items-center gap-3 border-b border-[#eef2ff] px-5 py-5">
            <div className="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-500 text-white shadow-lg shadow-blue-200">
              {settings.logo_url ? (
                <img src={settings.logo_url} alt={settings.association_name} className="h-full w-full object-cover" />
              ) : (
                <Building2 size={20} />
              )}
            </div>

            <div className={`min-w-0 ${collapsed ? 'lg:hidden' : ''}`}>
              <h1 className="truncate text-sm font-semibold text-slate-900">{settings.association_name}</h1>
              <p className="truncate text-[11px] font-medium text-slate-400">{workspaceSubtitle}</p>
            </div>

            <button
              type="button"
              onClick={() => setMobileNavOpen(false)}
              className="ml-auto flex h-9 w-9 shrink-0 items-center justify-center rounded-xl border border-[#eef2ff] text-slate-400 transition hover:bg-slate-50 lg:hidden"
              aria-label={t('closeNavigationAria')}
            >
              <X size={16} />
            </button>
          </div>

          <div className="hidden px-3 pt-3 lg:block">
            <button
              type="button"
              onClick={() => setCollapsed((current) => !current)}
              className={`flex w-full items-center gap-2 rounded-xl border border-[#eef2ff] px-3 py-2 text-xs font-semibold text-slate-400 transition hover:bg-slate-50 hover:text-slate-600 ${
                collapsed ? 'justify-center' : 'justify-between'
              }`}
              title={collapsed ? t('actions.expandSidebar') : t('actions.collapseSidebar')}
            >
              {collapsed ? null : <span>{t('actions.collapse')}</span>}
              {collapsed ? <PanelLeftOpen size={16} /> : <PanelLeftClose size={16} />}
            </button>
          </div>

          <nav className="flex-1 space-y-8 overflow-y-auto px-3 py-6">
            {navigation.map((section) => {
              const sectionBadge = section.badgeKey === 'approvals' ? approvalsCount : 0

              return (
                <div key={section.titleKey}>
                  <div
                    className={`mb-2.5 flex items-center gap-2 px-3 ${collapsed ? 'lg:justify-center' : 'justify-between'}`}
                  >
                    <p
                      className={`text-[11px] font-bold uppercase tracking-[0.16em] text-slate-500 ${
                        collapsed ? 'lg:hidden' : ''
                      }`}
                    >
                      {t(section.titleKey)}
                    </p>
                    {sectionBadge > 0 ? (
                      <span
                        className={`flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-600 px-1.5 text-[10px] font-semibold text-white ${
                          collapsed ? 'lg:hidden' : ''
                        }`}
                      >
                        {sectionBadge > 99 ? '99+' : sectionBadge}
                      </span>
                    ) : null}
                  </div>

                  <div className="space-y-1">
                    {section.items.map((item) => (
                      <SidebarLink
                        key={item.path}
                        to={item.path}
                        icon={getItemIcon(item.path)}
                        label={t(item.labelKey)}
                        active={isActivePath(location.pathname, item.path)}
                        collapsed={collapsed}
                      />
                    ))}
                  </div>
                </div>
              )
            })}
          </nav>

          <div className="border-t border-[#eef2ff] p-3">
            <div className={`flex items-center gap-3 rounded-xl px-2 py-2 ${collapsed ? 'lg:justify-center' : ''}`}>
              <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-sm font-semibold text-white">
                {initials}
              </div>
              <div className={`min-w-0 ${collapsed ? 'lg:hidden' : ''}`}>
                <p className="truncate text-sm font-semibold text-slate-900">{displayName}</p>
                <p className="truncate text-xs text-slate-400">{roleLabel(role)}</p>
              </div>
            </div>

            <div className="mt-2 space-y-1">
              <SidebarLink
                to="/notifications"
                icon={Bell}
                label={t('nav.notifications')}
                active={location.pathname === '/notifications'}
                collapsed={collapsed}
              />
              <SidebarLink
                to="/profile"
                icon={UserCircle2}
                label={t('nav.profile')}
                active={location.pathname === '/profile'}
                collapsed={collapsed}
              />
            </div>

            <div className="my-2 border-t border-[#eef2ff]" />

            <button
              type="button"
              onClick={logout}
              className={`group relative flex w-full items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-rose-500 transition-all duration-200 hover:-translate-y-0.5 hover:bg-rose-50 hover:shadow-sm ${
                collapsed ? 'lg:justify-center' : ''
              }`}
            >
              <LogOut size={18} className="shrink-0" />
              <span className={collapsed ? 'lg:hidden' : ''}>{t('actions.logout')}</span>

              {collapsed ? (
                <span className="pointer-events-none absolute left-full top-1/2 z-50 ml-3 hidden -translate-y-1/2 whitespace-nowrap rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs font-medium text-white opacity-0 shadow-lg transition-opacity duration-150 group-hover:opacity-100 lg:block">
                  {t('actions.logout')}
                </span>
              ) : null}
            </button>
          </div>
        </aside>

        <main className="flex min-w-0 flex-1 flex-col">
          <div className="flex items-center justify-between border-b border-[#dfe5ff] bg-white px-4 py-3 lg:hidden">
            <button
              type="button"
              onClick={() => setMobileNavOpen(true)}
              className="flex h-10 w-10 items-center justify-center rounded-xl border border-[#dfe5ff] text-slate-500 transition hover:bg-slate-50"
              aria-label={t('openNavigationAria')}
            >
              <Menu size={18} />
            </button>

            <div className="flex items-center gap-2">
              <div className="flex h-8 w-8 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gradient-to-br from-blue-600 to-indigo-500 text-white">
                {settings.logo_url ? (
                  <img src={settings.logo_url} alt={settings.association_name} className="h-full w-full object-cover" />
                ) : (
                  <Building2 size={16} />
                )}
              </div>
              <span className="truncate text-sm font-semibold text-slate-900">{settings.association_name}</span>
            </div>

            <div className="w-10" />
          </div>

          {/* Replace your existing <header>...</header> with this */}

<header className="border-b border-[#dfe5ff] bg-white px-8 py-6">
  <div className="flex flex-wrap items-center justify-between gap-x-8 gap-y-4 xl:flex-nowrap">
    <div className="shrink-0">
      <h1 className="text-2xl font-bold leading-tight tracking-tight text-slate-900">{title}</h1>
      <p className="mt-1.5 text-sm text-slate-500">{t('executiveOverview')}</p>
    </div>

    <div className="flex flex-wrap items-center gap-3 xl:flex-nowrap">
      {headerActions ? (
        <div className="flex flex-wrap items-center gap-3">{headerActions}</div>
      ) : null}

      <div className="relative shrink-0" ref={notificationsRef}>
        <button
          type="button"
          onClick={() => setShowNotifications((current) => !current)}
          className="relative flex h-12 w-12 items-center justify-center rounded-2xl border border-[#dfe5ff] bg-white transition duration-150 hover:bg-slate-50"
        >
          <Bell size={18}/>
          {unreadNotificationCount>0 && <span className="absolute -right-1 -top-1 flex h-5 min-w-5 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-semibold text-white">{unreadNotificationCount>9?"9+":unreadNotificationCount}</span>}
        </button>

        <NotificationDropdown
          open={showNotifications}
          notifications={notifications}
          loading={loadingNotifications}
          onMarkRead={handleMarkRead}
          onMarkAllRead={handleMarkAllRead}
          onClose={() => setShowNotifications(false)}
        />
      </div>

      <button className="flex shrink-0 items-center gap-3 rounded-2xl border border-[#dfe5ff] bg-white px-3 py-2 shadow-sm transition duration-150 hover:bg-slate-50">
        <div className="text-right">
          <div className="text-sm font-semibold">{displayName}</div>
          <div className="text-xs text-slate-500">{roleLabel(role)}</div>
        </div>
        <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 text-white font-semibold">{initials}</div>
      </button>
    </div>
  </div>
</header>


          <div className="flex-1 space-y-7 p-4 lg:p-8">{children}</div>

          <footer className="border-t border-[#eef2ff] px-4 py-4 text-center text-xs text-slate-400 lg:px-8">
            &copy; {new Date().getFullYear()} {settings.association_name}
            {settings.phone ? ` · ${settings.phone}` : ''}
            {settings.email ? ` · ${settings.email}` : ''}
          </footer>
        </main>
      </div>
    </div>
  )
}
