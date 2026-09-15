import { FileText, FolderPlus, UserPlus, UsersRound } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import CommitteeDashboardPage from './CommitteeDashboardPage'
import PresidentLayout from '../components/layout/PresidentLayout'
import ExecutiveKPIs from '../components/dashboard/ExecutiveKPIs'
import DuesOverview from '../components/dashboard/DuesOverview'
import FinancialOverview from '../components/dashboard/FinancialOverview'
import ProjectHealthTable from '../components/dashboard/ProjectHealthTable'
import ProjectMap from '../components/dashboard/ProjectMap'
import ActivityTimeline from '../components/dashboard/ActivityTimeline'
import ReportsSummary from '../components/dashboard/ReportsSummary'
import MemberHealth from '../components/dashboard/MemberHealth'
import UpcomingEvents from '../components/dashboard/UpcomingEvents'
import AssociationStatistics from '../components/dashboard/AssociationStatistics'
import useDashboard from '../hooks/useDashboard'
import useCommitteeProjects from '../hooks/useCommitteeProjects'
import { useAuth } from '../context/auth-context'
import { hasRole, isBureauMember } from '../lib/roles'
import { getMemberDashboard } from '../api/auth.api'
import { getSubscriptions } from '../api/subscriptions.api'
import { getProjects } from '../api/projects.api'
import { getDonations } from '../api/donations.api'
import { getExpenses } from '../api/expenses.api'
import { getMembers } from '../api/members.api'
import { getActivityLogs } from '../api/activityLogs.api'
import { downloadReportBlob } from '../api/reports.api'
import { formatCurrency } from '../components/committee/committeeUtils'
import { useStatusLabel } from '../hooks/useStatusLabel'
import { useLocaleFormat } from '../hooks/useLocaleFormat'
import {
  computeActivityByProject,
  computeCommittees,
  computeMonthlyFinancials,
  computeProjectBreakdown,
  expiringSubscriptions,
  highestSpendingProjects,
  newestSubscribers,
  PRE_ACTIVE_STATUSES,
  projectsEndingSoon,
  projectsFinishingThisMonth,
  topDonors,
  topFundedProjects,
} from '../lib/dashboardMetrics'

export default function DashboardPage() {
  const { t } = useTranslation(['dashboard', 'common'])
  const statusLabel = useStatusLabel()
  const { formatDate } = useLocaleFormat()
  const { user } = useAuth()
  const isPresident = hasRole(user, 'president')
  const isBureau = isBureauMember(user)
  const { projects: committeeProjects, loading: committeeLoading } = useCommitteeProjects(user)
  const { dashboard, loading: presidentLoading } = useDashboard(isPresident)

  const [subscriptions, setSubscriptions] = useState([])
  const [projects, setProjects] = useState([])
  const [donations, setDonations] = useState([])
  const [expenses, setExpenses] = useState([])
  const [members, setMembers] = useState([])
  const [activityLogs, setActivityLogs] = useState([])
  const [reportError, setReportError] = useState('')
  const [memberSummary, setMemberSummary] = useState(null)

  async function loadExecutiveData() {
    // Independent settles: one failing source (e.g. a policy/permission gap on a
    // single endpoint) must not blank out the rest of the dashboard.
    const [subscriptionData, projectData, donationData, expenseData, memberData, activityData] =
      await Promise.allSettled([
        getSubscriptions(),
        getProjects(),
        getDonations(),
        getExpenses(),
        getMembers(),
        getActivityLogs(),
      ])

    if (subscriptionData.status === 'fulfilled') setSubscriptions(subscriptionData.value ?? [])
    else console.error('Failed to load subscriptions', subscriptionData.reason)

    if (projectData.status === 'fulfilled') setProjects(projectData.value ?? [])
    else console.error('Failed to load projects', projectData.reason)

    if (donationData.status === 'fulfilled') setDonations(donationData.value ?? [])
    else console.error('Failed to load donations', donationData.reason)

    if (expenseData.status === 'fulfilled') setExpenses(expenseData.value ?? [])
    else console.error('Failed to load expenses', expenseData.reason)

    if (memberData.status === 'fulfilled') setMembers(memberData.value ?? [])
    else console.error('Failed to load members', memberData.reason)

    if (activityData.status === 'fulfilled') setActivityLogs(activityData.value?.data ?? [])
    else console.error('Failed to load activity logs', activityData.reason)
  }

  useEffect(() => {
    if (!isPresident) return

    let cancelled = false

    loadExecutiveData().catch((error) => {
      if (!cancelled) console.error('Failed to load dashboard data', error)
    })

    return () => {
      cancelled = true
    }
  }, [isPresident])

  // Plain subscriber, no committee assignments — the only branch below that
  // has nothing else to fetch. Reuses the same safe-by-construction endpoint
  // the old Member Portal dashboard used, just for a smaller summary here.
  useEffect(() => {
    if (isBureau || committeeLoading || committeeProjects.length > 0) return

    let cancelled = false

    getMemberDashboard()
      .then((data) => {
        if (!cancelled) setMemberSummary(data)
      })
      .catch((error) => console.error('Failed to load member summary', error))

    return () => {
      cancelled = true
    }
  }, [isBureau, committeeLoading, committeeProjects.length])

  async function handleDownloadAnnualReport() {
    setReportError('')

    try {
      const blob = await downloadReportBlob('/reports/projects')
      window.open(URL.createObjectURL(blob), '_blank')
    } catch (error) {
      setReportError(error.message ?? t('dashboard:president.reportDownloadError'))
    }
  }

  if (isPresident) {
    if (presidentLoading) {
      return (
        <div className="flex min-h-screen items-center justify-center bg-[#f5f7ff]">
          <div className="w-64 space-y-3 rounded-2xl border border-[#dbe2ff] bg-white px-6 py-5 shadow-sm">
            <div className="h-3 w-2/3 animate-pulse rounded bg-slate-100" />
            <div className="h-3 w-full animate-pulse rounded bg-slate-100" />
            <div className="h-3 w-1/2 animate-pulse rounded bg-slate-100" />
          </div>
        </div>
      )
    }
  } else {
    if (committeeLoading) {
      return (
        <div className="flex min-h-screen items-center justify-center bg-[#f5f7ff]">
          <div className="w-64 space-y-3 rounded-2xl border border-[#dbe2ff] bg-white px-6 py-5 shadow-sm">
            <div className="h-3 w-2/3 animate-pulse rounded bg-slate-100" />
            <div className="h-3 w-full animate-pulse rounded bg-slate-100" />
            <div className="h-3 w-1/2 animate-pulse rounded bg-slate-100" />
          </div>
        </div>
      )
    }

    if (committeeProjects.length > 0) {
      return <CommitteeDashboardPage />
    }
  }

  if (!isPresident && committeeProjects.length === 0) {
    return (
      <PresidentLayout
        title={isBureau ? t('dashboard:bureau.title') : t('common:workspace.subscriberDashboard')}
        description={isBureau ? t('dashboard:bureau.description') : t('dashboard:subscriber.description')}
        breadcrumbs={['Dashboard']}
      >
        {!isBureau && memberSummary ? (
          <div className="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div className="rounded-3xl border border-[#dfe5ff] bg-white p-5 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{t('dashboard:subscriber.profile')}</p>
              <p className="mt-2 font-semibold text-slate-800">{memberSummary.profile?.name}</p>
              <p className="text-sm text-slate-500">{memberSummary.profile?.email}</p>
            </div>
            <div className="rounded-3xl border border-[#dfe5ff] bg-white p-5 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{t('dashboard:subscriber.subscriptionCard')}</p>
              <p className="mt-2 font-semibold text-slate-800">
                {memberSummary.subscription_status ? statusLabel(memberSummary.subscription_status) : t('dashboard:subscriber.noneOnFile')}
              </p>
              {memberSummary.expiration_date ? (
                <p className="text-sm text-slate-500">
                  {t('dashboard:subscriber.expires', { date: formatDate(memberSummary.expiration_date) })}
                </p>
              ) : null}
            </div>
          </div>
        ) : null}

        <div className="rounded-2xl border border-[#dfe5ff] bg-white p-8 text-center shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <h3 className="text-lg font-semibold text-slate-900">{t('dashboard:subscriber.noAssignmentsTitle')}</h3>
          <p className="mt-2 text-sm text-slate-500">
            {isBureau
              ? t('dashboard:subscriber.noAssignmentsBureau')
              : t('dashboard:subscriber.noAssignmentsSubscriber')}
          </p>
        </div>
      </PresidentLayout>
    )
  }

  // --- Derived, real metrics only — see src/lib/dashboardMetrics.js ---
  // Available funds itself always comes from the backend (GET /dashboard's
  // available_funds, via FundsHelper) — the same value the Finance page
  // reads, never recomputed client-side (that used to drift: a client calc
  // here ignored project fund allocations entirely).
  const availableFunds = dashboard?.available_funds ?? null
  const monthly = computeMonthlyFinancials(subscriptions, donations, expenses)
  const projectBreakdown = computeProjectBreakdown(projects)
  const activityByProject = computeActivityByProject(activityLogs)
  const committees = computeCommittees(projects, activityByProject)
  const committeeSummary = {
    total: committees.length,
    green: committees.filter((c) => c.tone === 'green').length,
    yellow: committees.filter((c) => c.tone === 'yellow').length,
    red: committees.filter((c) => c.tone === 'red').length,
  }
  const subscriptionStats = {
    total: subscriptions.length,
    verified: subscriptions.filter((s) => s.status === 'verified').length,
    pending: subscriptions.filter((s) => s.status === 'pending').length,
    expired: subscriptions.filter((s) => s.status === 'expired').length,
  }
  const pendingSubscriptions = subscriptions.filter((s) => s.status === 'pending')
  const activeProjects = projects.filter((p) => p.status === 'active' || PRE_ACTIVE_STATUSES.includes(p.status))
  const expiringSubs = expiringSubscriptions(subscriptions)
  const endingProjects = projectsEndingSoon(projects)
  const newSubscribersThisMonth = members.filter((m) => {
    if (!m.created_at) return false
    const created = new Date(m.created_at)
    const now = new Date()
    return created.getFullYear() === now.getFullYear() && created.getMonth() === now.getMonth()
  }).length

  // Phase requests aren't counted here (they're fetched independently by the
  // Action Center card below) — this header stat covers the three approval
  // types this page already has full lists loaded for.
  const pendingApprovalsCount =
    pendingSubscriptions.length +
    expenses.filter((e) => e.status === 'pending').length +
    donations.filter((d) => d.status === 'pending').length
  const isHealthy = (availableFunds == null || availableFunds >= 0) && committeeSummary.red === 0

  const secondaryActionClass =
    'inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition duration-150 hover:bg-slate-50 active:scale-[0.97]'

  const headerActions = (
    <>
      <Link to="/members/new" className={secondaryActionClass}>
        <UserPlus size={16} />
        {t('dashboard:president.addSubscriber')}
      </Link>

      <Link to="/projects/new" className={secondaryActionClass}>
        <FolderPlus size={16} />
        {t('dashboard:president.createProject')}
      </Link>

      <Link to="/committees" className={secondaryActionClass}>
        <UsersRound size={16} />
        {t('dashboard:president.createCommittee')}
      </Link>

      <button
        type="button"
        onClick={handleDownloadAnnualReport}
        className={secondaryActionClass}
        title={t('dashboard:president.annualReportTooltip')}
      >
        <FileText size={16} />
        {t('dashboard:president.annualReport')}
      </button>
    </>
  )

  return (
    <PresidentLayout
      title={t('dashboard:president.title')}
      description={
        <>
          {t('dashboard:president.statusLabel')}{' '}
          <span className={isHealthy ? 'font-semibold text-emerald-600' : 'font-semibold text-rose-600'}>
            {isHealthy ? t('dashboard:president.healthy') : t('dashboard:president.needsAttention')}
          </span>
          {' · '}
          {activeProjects.filter((p) => p.status === 'active').length} {t('dashboard:president.activeProjects')}
          {' · '}
          {pendingApprovalsCount} {t('dashboard:president.pendingApprovals')}
          {' · '}
          {formatCurrency(availableFunds)} {t('dashboard:president.available')}
          {' · '}
          {subscriptionStats.expired} {t('dashboard:president.overdueSubscriptions')}
          {' · '}
          {committeeSummary.red} {t('dashboard:president.committeesNeedAttention')}
        </>
      }
      breadcrumbs={['Dashboard']}
      headerActions={headerActions}
    >
      {reportError ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {reportError}
        </div>
      ) : null}

      <ExecutiveKPIs
        availableFunds={availableFunds}
        revenueTrend={dashboard?.stats?.revenue}
        projectBreakdown={projectBreakdown}
        subscriptionStats={subscriptionStats}
        committeeSummary={committeeSummary}
      />

      <FinancialOverview monthly={monthly} />

      <ProjectHealthTable projects={activeProjects} activityByProject={activityByProject} />

      <section className="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <ProjectMap projects={projects} />
        <UpcomingEvents expiringSubs={expiringSubs} endingProjects={endingProjects} />
      </section>

      <section className="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <ActivityTimeline logs={activityLogs} />
        <ReportsSummary />
      </section>

      <MemberHealth
        newThisMonth={newSubscribersThisMonth}
        expiringCount={expiringSubs.length}
        inactiveCount={subscriptionStats.expired}
      />

      <DuesOverview dues={dashboard?.dues} />

      <AssociationStatistics
        topFunded={topFundedProjects(projects)}
        topDonorList={topDonors(donations)}
        topSpending={highestSpendingProjects(projects)}
        finishingThisMonth={projectsFinishingThisMonth(projects)}
        newestMembers={newestSubscribers(members)}
      />
    </PresidentLayout>
  )
}
