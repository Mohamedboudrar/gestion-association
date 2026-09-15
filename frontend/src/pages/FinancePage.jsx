import {
  AlertTriangle,
  ArrowRightLeft,
  Banknote,
  ChevronDown,
  ChevronRight,
  FolderKanban,
  Gift,
  PiggyBank,
  Receipt,
  WalletCards,
} from 'lucide-react'
import { Fragment, useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import { useAuth } from '../context/auth-context'
import { hasRole, isProjectLocked } from '../lib/roles'
import { canReceiveAllocation, stageLabel } from '../lib/projectLifecycle'
import { formatCurrency, statusBadgeClass } from '../components/committee/committeeUtils'
import { getSubscriptions } from '../api/subscriptions.api'
import { getDonations } from '../api/donations.api'
import { getProjects } from '../api/projects.api'
import { getDashboard } from '../api/dashboard.api'
import { createProjectAllocation } from '../api/projectAllocations.api'
import {
  computeFinanceSummary,
  computeOpenProjectsAllocated,
  computeProjectFunding,
  groupDonationsByProject,
} from '../lib/dashboardMetrics'

const DONATIONS_PREVIEW_COUNT = 8

function SummaryCard({ icon: Icon, iconTone, title, value, valueClass, caption }) {
  return (
    <div className="rounded-3xl border border-[#dfe5ff] bg-white p-5 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
      <div className={`mb-3 flex h-11 w-11 items-center justify-center rounded-2xl ${iconTone}`}>
        <Icon size={20} />
      </div>
      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{title}</p>
      <h3 className={`mt-1 text-2xl font-bold ${valueClass ?? 'text-slate-900'}`}>{value}</h3>
      {caption ? <p className="mt-2 text-xs text-slate-500">{caption}</p> : null}
    </div>
  )
}

function TransferForm({ project, availableFunds, onCancel, onSubmit }) {
  const { t } = useTranslation('finance')
  const [amount, setAmount] = useState('')
  const [proofFile, setProofFile] = useState(null)
  const [error, setError] = useState('')
  const [submitting, setSubmitting] = useState(false)

  async function handleSubmit(event) {
    event.preventDefault()
    setError('')

    const numericAmount = Number(amount)

    if (!numericAmount || numericAmount <= 0) {
      setError(t('transferForm.amountRequired'))
      return
    }

    if (availableFunds == null) {
      setError(t('transferForm.fundsUnknown'))
      return
    }

    if (numericAmount > availableFunds) {
      setError(t('transferForm.amountExceedsFunds', { amount: formatCurrency(availableFunds) }))
      return
    }

    if (!proofFile) {
      setError(t('transferForm.proofRequired'))
      return
    }

    setSubmitting(true)

    try {
      await onSubmit(numericAmount, proofFile)
    } catch (submitError) {
      setError(
        submitError.response?.data?.message ?? t('transferForm.genericError'),
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-wrap items-start gap-2 rounded-2xl bg-[#f7f9ff] p-3">
      <div className="flex-1 min-w-[160px]">
        <input
          type="number"
          min="0"
          step="0.01"
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
          placeholder={t('transferForm.amountPlaceholder', { project: project.name })}
          className="h-10 w-full rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm outline-none focus:border-blue-500"
          autoFocus
        />
      </div>

      <div className="flex-1 min-w-[200px]">
        <input
          type="file"
          accept=".pdf,.jpg,.jpeg,.png"
          onChange={(event) => setProofFile(event.target.files?.[0] ?? null)}
          className="h-10 w-full rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm outline-none file:mr-3 file:h-full file:border-0 file:bg-transparent file:text-sm file:font-semibold file:text-blue-600 focus:border-blue-500"
        />
      </div>

      {error ? <p className="w-full text-xs font-medium text-rose-600">{error}</p> : null}

      <button
        type="submit"
        disabled={submitting}
        className="h-10 shrink-0 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-60"
      >
        {submitting ? t('transferForm.transferring') : t('transferForm.confirm')}
      </button>

      <button
        type="button"
        onClick={onCancel}
        className="h-10 shrink-0 rounded-xl border border-[#dfe5ff] px-4 text-sm font-semibold text-slate-500 transition hover:bg-white"
      >
        {t('transferForm.cancel')}
      </button>
    </form>
  )
}

function DonationGroup({ group, expanded, onToggle }) {
  const { t } = useTranslation('finance')
  const [showAll, setShowAll] = useState(false)
  const visibleDonations = showAll ? group.donations : group.donations.slice(0, DONATIONS_PREVIEW_COUNT)
  const hiddenCount = group.donations.length - visibleDonations.length

  return (
    <div>
      <button
        type="button"
        onClick={onToggle}
        className="flex w-full items-center justify-between gap-3 px-6 py-4 text-left transition hover:bg-slate-50"
      >
        <div className="flex min-w-0 items-center gap-2">
          {expanded ? (
            <ChevronDown size={16} className="shrink-0 text-slate-400" />
          ) : (
            <ChevronRight size={16} className="shrink-0 text-slate-400" />
          )}
          <p className="truncate font-semibold text-slate-800">{group.projectName}</p>
          <span className="shrink-0 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">
            {t('donations.count', { count: group.donations.length })}
          </span>
        </div>
        <p className="shrink-0 text-sm font-semibold text-blue-700">{formatCurrency(group.total)}</p>
      </button>

      {expanded ? (
        <div className="space-y-2 px-6 pb-5 pl-11">
          {visibleDonations.map((donation) => (
            <div key={donation.id} className="flex items-center justify-between gap-3 text-sm">
              <p className="min-w-0 truncate text-slate-500">{donation.donor_name ?? t('donations.unknownDonor')}</p>
              <div className="flex shrink-0 items-center gap-3 text-xs text-slate-400">
                <span>{donation.donation_date}</span>
                <span className="font-semibold text-slate-700">{formatCurrency(donation.amount)}</span>
              </div>
            </div>
          ))}

          {hiddenCount > 0 ? (
            <button
              type="button"
              onClick={() => setShowAll(true)}
              className="text-xs font-semibold text-blue-600 hover:text-blue-700"
            >
              {t('donations.showAll', { total: group.donations.length, more: hiddenCount })}
            </button>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

export default function FinancePage() {
  const { t } = useTranslation('finance')
  const { user } = useAuth()
  const isPresident = hasRole(user, 'president')
  const isTreasurer = hasRole(user, 'tresorier') || hasRole(user, 'vice-tresorier')

  const [subscriptions, setSubscriptions] = useState([])
  const [donations, setDonations] = useState([])
  const [projects, setProjects] = useState([])
  const [availableFunds, setAvailableFunds] = useState(null)
  const [subscriptionsError, setSubscriptionsError] = useState(null)
  const [donationsError, setDonationsError] = useState(null)
  const [projectsError, setProjectsError] = useState(null)
  const [availableFundsError, setAvailableFundsError] = useState(null)
  const [loading, setLoading] = useState(true)
  const [transferProjectId, setTransferProjectId] = useState(null)
  const [expandedDonationGroups, setExpandedDonationGroups] = useState(() => new Set())
  const [expandedProjects, setExpandedProjects] = useState(false)

  async function loadFinanceData() {
    const [subscriptionResult, donationResult, projectResult, dashboardResult] = await Promise.allSettled([
      getSubscriptions(),
      getDonations(),
      getProjects(),
      getDashboard(),
    ])

    if (subscriptionResult.status === 'fulfilled') {
      setSubscriptions(subscriptionResult.value ?? [])
      setSubscriptionsError(null)
    } else {
      setSubscriptionsError(subscriptionResult.reason)
    }

    if (donationResult.status === 'fulfilled') {
      setDonations(donationResult.value ?? [])
      setDonationsError(null)
    } else {
      setDonationsError(donationResult.reason)
    }

    if (projectResult.status === 'fulfilled') {
      setProjects(projectResult.value ?? [])
      setProjectsError(null)
    } else {
      setProjectsError(projectResult.reason)
    }

    if (dashboardResult.status === 'fulfilled') {
      setAvailableFunds(dashboardResult.value?.available_funds ?? null)
      setAvailableFundsError(null)
    } else {
      setAvailableFundsError(dashboardResult.reason)
    }
  }

  useEffect(() => {
    if (!isPresident && !isTreasurer) return

    setLoading(true)
    loadFinanceData().finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isPresident, isTreasurer])

  if (!isPresident && !isTreasurer) {
    return <Navigate to="/dashboard" replace />
  }

  if (loading) {
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

  const summary = computeFinanceSummary(subscriptions, donations, projects)
  const subscriptionsOk = !subscriptionsError
  const donationsOk = !donationsError
  const projectsOk = !projectsError

  const annualSubscriptionIncome = subscriptionsOk ? summary.annualSubscriptionIncome : null
  const totalDonated = donationsOk ? summary.totalDonated : null
  const totalProjectBudget = projectsOk ? summary.totalProjectBudget : null
  const availableFundsOk = !availableFundsError
  const availableFundsKnown = availableFundsOk && availableFunds != null
  const availableFundsNegative = availableFundsKnown && availableFunds < 0

  const openProjectsAllocated = projectsOk ? computeOpenProjectsAllocated(projects) : null

  const donationGroups = donationsOk ? groupDonationsByProject(donations) : []
  const projectFunding = projectsOk ? computeProjectFunding(projects) : []
  const visibleProjectFunding = expandedProjects ? projectFunding : projectFunding.slice(0, 8)

  function toggleDonationGroup(key) {
    setExpandedDonationGroups((current) => {
      const next = new Set(current)
      if (next.has(key)) next.delete(key)
      else next.add(key)
      return next
    })
  }

  async function handleTransfer(project, amount, proofFile) {
    const formData = new FormData()
    formData.append('amount', amount)
    formData.append('allocation_date', new Date().toISOString().slice(0, 10))
    formData.append('proof_file', proofFile)

    await createProjectAllocation(project.id, formData)
    setTransferProjectId(null)
    await loadFinanceData()
  }

  return (
    <PresidentLayout
      title={t('page.title')}
      description={t('page.description')}
      breadcrumbs={['Finance']}
      headerActions={
        <>
          <Link
            to="/subscriptions"
            className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            <WalletCards size={16} />
            {t('page.paymentHistory')}
          </Link>
          <Link
            to="/subscriptions/receipts"
            className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            <Receipt size={16} />
            {t('page.receipts')}
          </Link>
        </>
      }
    >
      <section className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
        <SummaryCard
          icon={Banknote}
          iconTone="bg-emerald-50 text-emerald-600"
          title={t('summary.annualSubscriptionIncome')}
          value={subscriptionsOk ? formatCurrency(annualSubscriptionIncome) : t('summary.unavailable')}
          caption={subscriptionsOk ? t('summary.annualSubscriptionCaption') : t('summary.subscriptionsUnavailableCaption')}
        />
        <SummaryCard
          icon={PiggyBank}
          iconTone={availableFundsNegative ? 'bg-rose-50 text-rose-600' : 'bg-blue-50 text-blue-600'}
          title={t('summary.availableFunds')}
          value={availableFundsOk && availableFunds != null ? formatCurrency(availableFunds) : t('summary.unavailable')}
          valueClass={availableFundsNegative ? 'text-rose-600' : 'text-slate-900'}
          caption={t('summary.availableFundsCaption')}
        />
        <SummaryCard
          icon={Gift}
          iconTone="bg-purple-50 text-purple-600"
          title={t('summary.totalDonated')}
          value={donationsOk ? formatCurrency(totalDonated) : t('summary.unavailable')}
          caption={donationsOk ? t('summary.donationCount', { count: donations.length }) : t('summary.donationsUnavailableCaption')}
        />
        <SummaryCard
          icon={FolderKanban}
          iconTone="bg-amber-50 text-amber-600"
          title={t('summary.totalProjectBudget')}
          value={projectsOk ? formatCurrency(totalProjectBudget) : t('summary.unavailable')}
          caption={projectsOk ? t('summary.projectCount', { count: projects.length }) : t('summary.projectsUnavailableCaption')}
        />
      </section>

      <section
        className={`rounded-3xl border p-6 text-white shadow-[0_10px_24px_rgba(59,130,246,0.25)] ${
          availableFundsNegative
            ? 'border-rose-300 bg-gradient-to-br from-rose-600 to-orange-600'
            : 'border-[#dfe5ff] bg-gradient-to-br from-blue-600 to-indigo-600'
        }`}
      >
        <div className="flex items-center gap-2">
          {availableFundsNegative ? <AlertTriangle size={18} className="shrink-0" /> : null}
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-blue-100">
            {t('balance.title')}
          </p>
        </div>
        <h2 className="mt-2 text-4xl font-bold">
          {availableFundsOk && availableFunds != null ? formatCurrency(availableFunds) : t('summary.unavailable')}
        </h2>

        {!availableFundsOk ? (
          <p className="mt-3 text-sm text-blue-100">{t('balance.unavailable')}</p>
        ) : availableFundsNegative ? (
          <p className="mt-3 max-w-2xl text-sm text-orange-50">
            {t('balance.negativeExplanation', {
              allocated: formatCurrency(openProjectsAllocated),
              income: formatCurrency(annualSubscriptionIncome),
            })}
          </p>
        ) : (
          <p className="mt-3 text-sm text-blue-100">
            {t('balance.positiveExplanation')}
          </p>
        )}
      </section>

      <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="border-b border-[#eef2ff] px-6 py-5">
          <h3 className="text-lg font-semibold text-slate-900">{t('donations.title')}</h3>
          <p className="text-sm text-slate-500">{t('donations.subtitle')}</p>
        </div>

        {!donationsOk ? (
          <div className="flex items-start gap-3 px-6 py-5 text-sm text-amber-700">
            <AlertTriangle size={18} className="mt-0.5 shrink-0" />
            <p>{t('donations.unavailable')}</p>
          </div>
        ) : donationGroups.length === 0 ? (
          <p className="px-6 py-6 text-sm text-slate-500">{t('donations.empty')}</p>
        ) : (
          <div className="divide-y divide-[#eef2ff]">
            {donationGroups.map((group) => {
              const key = group.projectId ?? 'unassigned'
              return (
                <DonationGroup
                  key={key}
                  group={group}
                  expanded={expandedDonationGroups.has(key)}
                  onToggle={() => toggleDonationGroup(key)}
                />
              )
            })}
          </div>
        )}
      </section>

      <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="border-b border-[#eef2ff] px-6 py-5">
          <h3 className="text-lg font-semibold text-slate-900">{t('projectFunding.title')}</h3>
          <p className="text-sm text-slate-500">{t('projectFunding.subtitle')}</p>
        </div>

        {!projectsOk ? (
          <div className="flex items-start gap-3 px-6 py-5 text-sm text-amber-700">
            <AlertTriangle size={18} className="mt-0.5 shrink-0" />
            <p>{t('projectFunding.unavailable')}</p>
          </div>
        ) : projectFunding.length === 0 ? (
          <p className="px-6 py-6 text-sm text-slate-500">{t('projectFunding.empty')}</p>
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[720px] text-sm">
                <thead>
                  <tr className="border-b border-[#eef2ff] text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                    <th className="px-6 py-3 font-semibold">{t('projectFunding.table.project')}</th>
                    <th className="px-3 py-3 text-right font-semibold">{t('projectFunding.table.budget')}</th>
                    <th className="px-3 py-3 text-right font-semibold">{t('projectFunding.table.donations')}</th>
                    <th className="px-3 py-3 text-right font-semibold">{t('projectFunding.table.allocated')}</th>
                    <th className="px-3 py-3 text-right font-semibold">{t('projectFunding.table.expenses')}</th>
                    <th className="px-3 py-3 text-right font-semibold">{t('projectFunding.table.remaining')}</th>
                    <th className="px-6 py-3 text-right font-semibold">{t('projectFunding.table.actions')}</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-[#eef2ff]">
                  {visibleProjectFunding.map(({ project, budget, donationsTotal, allocatedTotal, expensesTotal, remainingFunds }) => {
                    const canTransfer = (isPresident || isTreasurer) && !isProjectLocked(project) && canReceiveAllocation(project)
                    const isOpenRow = transferProjectId === project.id

                    return (
                      <Fragment key={project.id}>
                        <tr className="align-top">
                          <td className="max-w-[240px] px-6 py-4">
                            <p className="truncate font-semibold text-slate-800">{project.name}</p>
                            <span className={statusBadgeClass(project.status)}>{stageLabel(project.status)}</span>
                          </td>
                          <td className="px-3 py-4 text-right font-normal text-slate-500">{formatCurrency(budget)}</td>
                          <td className="px-3 py-4 text-right font-normal text-slate-500">{formatCurrency(donationsTotal)}</td>
                          <td className="px-3 py-4 text-right font-normal text-slate-500">{formatCurrency(allocatedTotal)}</td>
                          <td className="px-3 py-4 text-right font-normal text-slate-500">{formatCurrency(expensesTotal)}</td>
                          <td
                            className={`px-3 py-4 text-right font-bold ${remainingFunds < 0 ? 'text-rose-600' : 'text-emerald-600'}`}
                          >
                            {formatCurrency(remainingFunds)}
                          </td>
                          <td className="px-6 py-4 text-right">
                            {canTransfer ? (
                              <button
                                type="button"
                                onClick={() => setTransferProjectId(isOpenRow ? null : project.id)}
                                className="inline-flex items-center gap-2 rounded-xl border border-blue-200 px-3 py-2 text-xs font-semibold text-blue-600 transition hover:bg-blue-50"
                              >
                                <ArrowRightLeft size={14} />
                                {t('projectFunding.transfer')}
                              </button>
                            ) : null}
                          </td>
                        </tr>
                        {isOpenRow ? (
                          <tr>
                            <td colSpan={7} className="bg-[#fbfcff] px-6 py-4">
                              <TransferForm
                                project={project}
                                availableFunds={availableFunds}
                                onCancel={() => setTransferProjectId(null)}
                                onSubmit={(amount, proofFile) => handleTransfer(project, amount, proofFile)}
                              />
                            </td>
                          </tr>
                        ) : null}
                      </Fragment>
                    )
                  })}
                </tbody>
              </table>
            </div>

            {projectFunding.length > visibleProjectFunding.length ? (
              <div className="border-t border-[#eef2ff] px-6 py-4">
                <button
                  type="button"
                  onClick={() => setExpandedProjects(true)}
                  className="text-sm font-semibold text-blue-600 hover:text-blue-700"
                >
                  {t('projectFunding.showAll', {
                    total: projectFunding.length,
                    more: projectFunding.length - visibleProjectFunding.length,
                  })}
                </button>
              </div>
            ) : null}
          </>
        )}
      </section>
    </PresidentLayout>
  )
}
