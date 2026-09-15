import { CheckCircle2, ChevronDown, XCircle } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../../context/auth-context'
import { getErrorMessage } from '../../lib/apiErrors'
import { phaseLabel, phaseStyle } from '../../lib/projectPhase'
import { getActionCenter } from '../../api/actionCenter.api'
import { approveDonation, rejectDonation } from '../../api/donations.api'
import { approveExpense, rejectExpense } from '../../api/expenses.api'
import { approvePhaseRequest, rejectPhaseRequest } from '../../api/phaseRequests.api'
import { verifySubscription } from '../../api/subscriptions.api'
import DonationWorkflowActions from '../committee/DonationWorkflowActions'
import ExpenseWorkflowActions from '../committee/ExpenseWorkflowActions'
import RejectReasonModal from '../committee/RejectReasonModal'
import { formatCurrency, getRelativeTime } from '../committee/committeeUtils'

const VISIBLE_COUNT = 5
const REFRESH_INTERVAL_MS = 60000

const CATEGORY_VALUES = [
  { value: 'all', viewAllTo: null },
  { value: 'subscriptions', viewAllTo: '/subscriptions/pending' },
  { value: 'donations', viewAllTo: '/donations' },
  { value: 'expenses', viewAllTo: '/expenses/pending' },
  { value: 'phase_requests', viewAllTo: '/phase-requests/pending' },
]

const linkButtonClass =
  'inline-flex items-center gap-1 rounded-full border border-[#dfe5ff] bg-white px-3 py-1.5 text-xs font-semibold text-slate-600 transition hover:bg-slate-50'

function SkeletonCard() {
  return (
    <div className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-4">
      <div className="h-3 w-1/3 animate-pulse rounded bg-slate-200" />
      <div className="mt-2 h-3.5 w-2/3 animate-pulse rounded bg-slate-200" />
      <div className="mt-2 h-3 w-1/2 animate-pulse rounded bg-slate-100" />
      <div className="mt-3 flex items-center justify-between">
        <div className="h-3 w-16 animate-pulse rounded bg-slate-100" />
        <div className="h-3 w-12 animate-pulse rounded bg-slate-100" />
      </div>
    </div>
  )
}

function ItemCard({ itemKey, isProcessing, children }) {
  const { t } = useTranslation('approvals')

  return (
    <div
      className={`rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-4 transition ${
        isProcessing ? 'pointer-events-none opacity-60' : ''
      }`}
      data-item-key={itemKey}
    >
      {children}
      {isProcessing ? <p className="mt-2 text-xs font-semibold text-blue-600">{t('actionCenter.processing')}</p> : null}
    </div>
  )
}

function PhaseRequestActions({ processing, onApprove, onReject }) {
  const { t } = useTranslation(['common', 'approvals'])
  const [rejecting, setRejecting] = useState(false)

  async function handleConfirm(reason) {
    await onReject(reason)
    setRejecting(false)
  }

  return (
    <div className="mt-3 flex flex-wrap items-center gap-2">
      <button
        type="button"
        disabled={processing}
        onClick={onApprove}
        className="inline-flex items-center gap-1 rounded-full border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-600 transition hover:bg-emerald-50 disabled:opacity-60"
      >
        <CheckCircle2 size={14} />
        {t('common:actions.approve')}
      </button>
      <button
        type="button"
        disabled={processing}
        onClick={() => setRejecting(true)}
        className="inline-flex items-center gap-1 rounded-full border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"
      >
        <XCircle size={14} />
        {t('common:actions.reject')}
      </button>

      <RejectReasonModal
        open={rejecting}
        submitting={processing}
        title={t('approvals:phaseRequest.rejectTitle')}
        description={t('approvals:phaseRequest.rejectDescription')}
        onCancel={() => setRejecting(false)}
        onConfirm={handleConfirm}
      />
    </div>
  )
}

// The president's approval inbox — everything awaiting a decision, in one
// card, instead of forcing navigation to four separate pages. Deliberately
// self-contained (fetches its own data on the existing list/pending
// endpoints and filters to status === 'pending' client-side, same as the
// rest of this dashboard already does) so it doesn't have to thread state
// through DashboardPage/FinancialOverview.
export default function ActionCenter() {
  const { t } = useTranslation(['approvals', 'common'])
  const { user } = useAuth()

  const [subscriptions, setSubscriptions] = useState([])
  const [expenses, setExpenses] = useState([])
  const [donations, setDonations] = useState([])
  const [phaseRequests, setPhaseRequests] = useState([])
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')
  const [activeTab, setActiveTab] = useState('all')
  const [processingKey, setProcessingKey] = useState(null)
  const [toast, setToast] = useState(null)
  const toastTimer = useRef(null)
  const [tabsOpen, setTabsOpen] = useState(false)
  const tabsRef = useRef(null)

  const CATEGORIES = CATEGORY_VALUES.map((category) => ({
    ...category,
    label: t(`actionCenter.categories.${category.value}`),
  }))

  useEffect(() => {
    if (!tabsOpen) return undefined

    function handlePointerDown(event) {
      if (tabsRef.current && !tabsRef.current.contains(event.target)) setTabsOpen(false)
    }

    function handleKeyDown(event) {
      if (event.key === 'Escape') setTabsOpen(false)
    }

    document.addEventListener('mousedown', handlePointerDown)
    document.addEventListener('keydown', handleKeyDown)

    return () => {
      document.removeEventListener('mousedown', handlePointerDown)
      document.removeEventListener('keydown', handleKeyDown)
    }
  }, [tabsOpen])

  async function load(showSpinner) {
    if (showSpinner) setLoading(true)

    try {
      const { items } = await getActionCenter()
      setSubscriptions(items.subscriptions)
      setExpenses(items.expenses)
      setDonations(items.donations)
      setPhaseRequests(items.phase_requests)
      setErrorMessage('')
    } catch (error) {
      console.error('Failed to load action center', error)
      setErrorMessage(t('actionCenter.loadError'))
    }

    if (showSpinner) setLoading(false)
  }

  useEffect(() => {
    let cancelled = false

    load(true).catch((error) => {
      if (!cancelled) console.error('Failed to load action center', error)
    })

    const interval = setInterval(() => {
      load(false).catch((error) => console.error('Failed to refresh action center', error))
    }, REFRESH_INTERVAL_MS)

    return () => {
      cancelled = true
      clearInterval(interval)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  function showToast(tone, message) {
    setToast({ tone, message })
    window.clearTimeout(toastTimer.current)
    toastTimer.current = window.setTimeout(() => setToast(null), 3500)
  }

  function removeItem(category, id) {
    if (category === 'subscriptions') setSubscriptions((current) => current.filter((item) => item.id !== id))
    if (category === 'expenses') setExpenses((current) => current.filter((item) => item.id !== id))
    if (category === 'donations') setDonations((current) => current.filter((item) => item.id !== id))
    if (category === 'phase_requests') setPhaseRequests((current) => current.filter((item) => item.id !== id))
  }

  async function runAction(category, id, action, successMessage, failureMessage, { keepOpenOnError = false } = {}) {
    setProcessingKey(`${category}-${id}`)

    try {
      await action()
      removeItem(category, id)
      showToast('success', successMessage)
    } catch (error) {
      showToast('error', getErrorMessage(error, failureMessage))
      if (keepOpenOnError) throw error
    } finally {
      setProcessingKey(null)
    }
  }

  const handleVerifySubscription = (id) =>
    runAction('subscriptions', id, () => verifySubscription(id), t('subscriptionPayment.approveSuccess'), t('subscriptionPayment.approveError'))

  const handleApproveExpense = (id) =>
    runAction('expenses', id, () => approveExpense(id), t('expenseApproval.approveSuccess'), t('expenseApproval.approveError'))

  const handleRejectExpense = (id, reason, rejectionType) =>
    runAction(
      'expenses',
      id,
      () => rejectExpense(id, reason, rejectionType),
      t('expenseApproval.rejectSuccess'),
      t('expenseApproval.rejectError'),
      { keepOpenOnError: true },
    )

  const handleApproveDonation = (id) =>
    runAction('donations', id, () => approveDonation(id), t('donationApproval.approveSuccess'), t('donationApproval.approveError'))

  const handleRejectDonation = (id, reason, rejectionType) =>
    runAction(
      'donations',
      id,
      () => rejectDonation(id, reason, rejectionType),
      t('donationApproval.rejectSuccess'),
      t('donationApproval.rejectError'),
      { keepOpenOnError: true },
    )

  const handleApprovePhaseRequest = (id) =>
    runAction('phase_requests', id, () => approvePhaseRequest(id), t('phaseRequest.approveSuccess'), t('phaseRequest.approveError'))

  const handleRejectPhaseRequest = (id, reason) =>
    runAction(
      'phase_requests',
      id,
      () => rejectPhaseRequest(id, reason),
      t('phaseRequest.rejectSuccess'),
      t('phaseRequest.rejectError'),
      { keepOpenOnError: true },
    )

  const allItems = useMemo(() => {
    const items = [
      ...subscriptions.map((s) => ({ type: 'subscriptions', id: s.id, date: s.payment_date, data: s })),
      ...expenses.map((e) => ({ type: 'expenses', id: e.id, date: e.created_at, data: e })),
      ...donations.map((d) => ({ type: 'donations', id: d.id, date: d.created_at, data: d })),
      ...phaseRequests.map((p) => ({ type: 'phase_requests', id: p.id, date: p.requested_at, data: p })),
    ]

    return items.sort((a, b) => new Date(a.date) - new Date(b.date))
  }, [subscriptions, expenses, donations, phaseRequests])

  const counts = {
    all: allItems.length,
    subscriptions: subscriptions.length,
    donations: donations.length,
    expenses: expenses.length,
    phase_requests: phaseRequests.length,
  }

  const activeCategory = CATEGORIES.find((c) => c.value === activeTab)
  const activeItems = activeTab === 'all' ? allItems : allItems.filter((item) => item.type === activeTab)
  const visibleItems = activeItems.slice(0, VISIBLE_COUNT)
  const hasMore = activeItems.length > VISIBLE_COUNT

  function renderItem(item) {
    const itemKey = `${item.type}-${item.id}`
    const isProcessing = processingKey === itemKey

    if (item.type === 'subscriptions') {
      const subscription = item.data
      return (
        <ItemCard key={itemKey} itemKey={itemKey} isProcessing={isProcessing}>
          <p className="text-xs font-semibold uppercase tracking-[0.08em] text-blue-600">{t('subscriptionPayment.badge')}</p>
          <p className="mt-1 font-semibold text-slate-900">{subscription.member?.name ?? t('subscriptionPayment.unknownSubscriber')}</p>
          <p className="text-sm text-slate-500">{t('subscriptionPayment.annualSubscription')}</p>
          <div className="mt-2 flex items-center justify-between">
            <span className="font-semibold text-slate-800">{formatCurrency(subscription.amount)}</span>
            <span className="text-xs text-slate-400">{getRelativeTime(subscription.payment_date)}</span>
          </div>
          <div className="mt-3 flex flex-wrap items-center gap-2">
            {subscription.receipt_url ? (
              <a href={subscription.receipt_url} target="_blank" rel="noreferrer" className={linkButtonClass}>
                {t('subscriptionPayment.viewReceipt')}
              </a>
            ) : null}
            {/* No reject action here: the backend has no subscription-rejection
                endpoint (verify() is the only status-changing action besides
                automatic expiry), so only Approve (verify) is offered. */}
            <button
              type="button"
              disabled={isProcessing}
              onClick={() => handleVerifySubscription(subscription.id)}
              className="inline-flex items-center gap-1 rounded-full border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-600 transition hover:bg-emerald-50 disabled:opacity-60"
            >
              <CheckCircle2 size={14} />
              {t('common:actions.approve')}
            </button>
          </div>
        </ItemCard>
      )
    }

    if (item.type === 'expenses') {
      const expense = item.data
      return (
        <ItemCard key={itemKey} itemKey={itemKey} isProcessing={isProcessing}>
          <p className="text-xs font-semibold uppercase tracking-[0.08em] text-rose-600">{t('expenseApproval.badge')}</p>
          <p className="mt-1 font-semibold text-slate-900">
            {t('expenseApproval.supplier', { name: expense.supplier_name ?? t('expenseApproval.unknownSupplier') })}
          </p>
          <p className="text-sm text-slate-500">
            {t('expenseApproval.project', { name: expense.project?.name ?? t('expenseApproval.noProject') })}
          </p>
          <div className="mt-2 flex items-center justify-between">
            <span className="font-semibold text-slate-800">{formatCurrency(expense.amount)}</span>
            <span className="text-xs text-slate-400">{getRelativeTime(expense.created_at)}</span>
          </div>
          {expense.invoice_number ? (
            <p className="mt-1 text-xs text-slate-400">{t('expenseApproval.invoiceNumber', { number: expense.invoice_number })}</p>
          ) : null}
          <div className="mt-3 flex flex-wrap items-center gap-2">
            {expense.invoice_url ? (
              <a href={expense.invoice_url} target="_blank" rel="noreferrer" className={linkButtonClass}>
                {t('expenseApproval.viewInvoice')}
              </a>
            ) : null}
            <ExpenseWorkflowActions
              user={user}
              expense={expense}
              onApprove={handleApproveExpense}
              onReject={handleRejectExpense}
            />
          </div>
        </ItemCard>
      )
    }

    if (item.type === 'donations') {
      const donation = item.data
      return (
        <ItemCard key={itemKey} itemKey={itemKey} isProcessing={isProcessing}>
          <p className="text-xs font-semibold uppercase tracking-[0.08em] text-emerald-600">{t('donationApproval.badge')}</p>
          <p className="mt-1 font-semibold text-slate-900">
            {t('donationApproval.donor', { name: donation.donor_name ?? t('donationApproval.unknownDonor') })}
          </p>
          <p className="text-sm text-slate-500">
            {t('donationApproval.project', { name: donation.project?.name ?? t('donationApproval.noProject') })}
          </p>
          <div className="mt-2 flex items-center justify-between">
            <span className="font-semibold text-slate-800">{formatCurrency(donation.amount)}</span>
            <span className="text-xs text-slate-400">{getRelativeTime(donation.created_at)}</span>
          </div>
          <div className="mt-3 flex flex-wrap items-center gap-2">
            {donation.receipt_url ? (
              <a href={donation.receipt_url} target="_blank" rel="noreferrer" className={linkButtonClass}>
                {t('donationApproval.viewReceipt')}
              </a>
            ) : null}
            <DonationWorkflowActions
              user={user}
              donation={donation}
              onApprove={handleApproveDonation}
              onReject={handleRejectDonation}
            />
          </div>
        </ItemCard>
      )
    }

    const phaseRequest = item.data
    const proofs = phaseRequest.proofs ?? []

    return (
      <ItemCard key={itemKey} itemKey={itemKey} isProcessing={isProcessing}>
        <p className="text-xs font-semibold uppercase tracking-[0.08em] text-indigo-600">{t('phaseRequest.badge')}</p>
        <p className="mt-1 font-semibold text-slate-900">{phaseRequest.project?.name ?? t('phaseRequest.unknownProject')}</p>
        <div className="mt-1 flex items-center gap-2 text-sm">
          <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${phaseStyle(phaseRequest.from_phase)}`}>
            {phaseLabel(phaseRequest.from_phase)}
          </span>
          <span className="text-slate-400">→</span>
          <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${phaseStyle(phaseRequest.to_phase)}`}>
            {phaseLabel(phaseRequest.to_phase)}
          </span>
        </div>
        <p className="mt-2 text-xs text-slate-400">
          {t('phaseRequest.committeeLeader', { name: phaseRequest.requested_by?.name ?? t('phaseRequest.unknown') })} • {getRelativeTime(phaseRequest.requested_at)}
        </p>
        <div className="mt-3 flex flex-wrap items-center gap-2">
          {proofs.length > 0 ? (
            <a href={proofs[0].url} target="_blank" rel="noreferrer" className={linkButtonClass}>
              {proofs.length > 1 ? t('phaseRequest.viewProofCount', { count: proofs.length }) : t('phaseRequest.viewProof')}
            </a>
          ) : null}
        </div>
        <PhaseRequestActions
          processing={isProcessing}
          onApprove={() => handleApprovePhaseRequest(phaseRequest.id)}
          onReject={(reason) => handleRejectPhaseRequest(phaseRequest.id, reason)}
        />
      </ItemCard>
    )
  }

  return (
    <div className="relative rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
      <h2 className="text-lg font-semibold text-slate-900">{t('actionCenter.title')}</h2>
      <p className="mt-0.5 text-sm text-slate-500">{t('actionCenter.subtitle')}</p>

      <div ref={tabsRef} className="relative mt-4">
        <button
          type="button"
          onClick={() => setTabsOpen((current) => !current)}
          aria-expanded={tabsOpen}
          className="flex w-full items-center justify-between gap-2 rounded-xl border border-[#dfe5ff] bg-[#f7f9ff] px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-white"
        >
          <span className="flex items-center gap-2">
            {activeCategory?.label}
            <span className="flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-100 px-1.5 text-xs font-semibold text-blue-700">
              {counts[activeTab]}
            </span>
          </span>
          <ChevronDown size={16} className={`text-slate-400 transition-transform ${tabsOpen ? 'rotate-180' : ''}`} />
        </button>

        <div
          className={`absolute left-0 right-0 top-full z-20 mt-2 origin-top rounded-2xl border border-[#dfe5ff] bg-white p-1.5 shadow-[0_20px_45px_rgba(85,100,180,0.18)] transition-all duration-150 ease-out ${
            tabsOpen ? 'pointer-events-auto translate-y-0 opacity-100' : 'pointer-events-none -translate-y-2 opacity-0'
          }`}
          role="menu"
          aria-hidden={!tabsOpen}
        >
          {CATEGORIES.map((category) => (
            <button
              key={category.value}
              type="button"
              onClick={() => {
                setActiveTab(category.value)
                setTabsOpen(false)
              }}
              className={`flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-left text-sm font-semibold transition ${
                activeTab === category.value ? 'bg-blue-50 text-blue-700' : 'text-slate-600 hover:bg-[#f7f9ff]'
              }`}
            >
              {category.label}
              <span
                className={`flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-xs font-semibold ${
                  activeTab === category.value ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500'
                }`}
              >
                {counts[category.value]}
              </span>
            </button>
          ))}
        </div>
      </div>

      {errorMessage ? <p className="mt-3 text-xs font-semibold text-rose-600">{errorMessage}</p> : null}

      <div className="mt-4 max-h-[360px] space-y-3 overflow-y-auto pr-1">
        {loading ? (
          <>
            <SkeletonCard />
            <SkeletonCard />
            <SkeletonCard />
          </>
        ) : visibleItems.length === 0 ? (
          <p className="py-8 text-center text-sm text-slate-500">{t('actionCenter.empty')}</p>
        ) : (
          visibleItems.map((item) => renderItem(item))
        )}
      </div>

      {hasMore && activeCategory?.viewAllTo ? (
        <Link
          to={activeCategory.viewAllTo}
          className="mt-4 flex w-full items-center justify-center rounded-xl border border-[#dfe5ff] px-4 py-2.5 text-sm font-semibold text-blue-600 transition hover:bg-blue-50"
        >
          {t('actionCenter.viewAll', { count: activeItems.length })}
        </Link>
      ) : null}

      {toast ? (
        <div
          className={`fixed bottom-6 right-6 z-50 rounded-2xl px-4 py-3 text-sm font-semibold text-white shadow-lg ${
            toast.tone === 'success' ? 'bg-emerald-600' : 'bg-rose-600'
          }`}
        >
          {toast.message}
        </div>
      ) : null}
    </div>
  )
}
