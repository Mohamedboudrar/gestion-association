import { CheckCircle2, ExternalLink, HandCoins, Receipt, Upload, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import DueStatusBadge from '../components/committee/DueStatusBadge'
import SubscriberDuesOverview from '../components/subscriptions/SubscriberDuesOverview'
import { formatCurrency } from '../components/committee/committeeUtils'
import { useAuth } from '../context/auth-context'
import { useSettings } from '../context/settings-context'
import { canCreateSubscriptionEntry, canUploadSubscriptionReceipt, canVerifySubscription, isBureauMember } from '../lib/roles'
import { getErrorMessage } from '../lib/apiErrors'
import { getMembers } from '../api/members.api'
import { getDues } from '../api/dues.api'
import { useStatusLabel } from '../hooks/useStatusLabel'
import {
  createSubscription,
  getSubscriptions,
  uploadSubscriptionReceipt,
  verifySubscription,
} from '../api/subscriptions.api'

// Matches the subscriptions.status enum (pending/verified/rejected/expired).
// 'rejected' has no live code path today (no reject endpoint exists) but is
// handled here anyway so the badge never falls back to a raw status string.
const STATUS_STYLES = {
  pending: 'bg-amber-50 text-amber-700',
  verified: 'bg-emerald-50 text-emerald-700',
  rejected: 'bg-rose-50 text-rose-700',
  expired: 'bg-rose-50 text-rose-700',
}

function statusBadgeClass(status) {
  return `inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold leading-none ${STATUS_STYLES[status] ?? 'bg-slate-100 text-slate-600'}`
}

const initialForm = {
  member_id: '',
  amount: '',
  payment_method: '',
  receipt_number: '',
  payment_date: '',
  expires_at: '',
  notes: '',
}

const FORM_FIELDS = ['amount', 'payment_method', 'receipt_number', 'payment_date', 'expires_at']
const DATE_FIELDS = new Set(['payment_date', 'expires_at'])

export default function SubscriptionsPage({ mode = 'all' }) {
  const { t } = useTranslation('finance')
  const statusLabel = useStatusLabel()
  const { user } = useAuth()
  const { settings, loading: settingsLoading } = useSettings()
  const canCreate = canCreateSubscriptionEntry(user)
  // A plain subscriber (never a bureau role) gets the due-centric "Annual
  // Dues" view instead of the bureau's flat payment ledger — see
  // SubscriberDuesOverview. Only relevant for the default "all" mode; the
  // pending/receipts modes are bureau-only nav entries a subscriber never
  // reaches.
  const isSubscriberView = !isBureauMember(user) && mode === 'all'
  const [subscriptions, setSubscriptions] = useState([])
  const [dues, setDues] = useState([])
  const [members, setMembers] = useState([])
  const [form, setForm] = useState(initialForm)
  const [errorMessage, setErrorMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  // Keyed by due id — lets each row's "complete due" amount be edited
  // independently, and defaults to the full remaining balance (a sensible
  // starting suggestion) until the user overrides it with a partial amount.
  const [completeAmounts, setCompleteAmounts] = useState({})
  // Which due's row currently shows the amount input — the "Compléter"
  // button reveals it on click instead of showing it inline for every row.
  const [completingDueId, setCompletingDueId] = useState(null)

  // Prefill the amount field with the association's configured annual
  // subscription amount — only while the field is still untouched, so this
  // never overwrites what the user is already typing.
  useEffect(() => {
    if (settingsLoading) return

    setForm((current) =>
      current.amount === '' && settings.annual_subscription_amount
        ? { ...current, amount: String(settings.annual_subscription_amount) }
        : current,
    )
  }, [settingsLoading, settings.annual_subscription_amount])

  useEffect(() => {
    async function load() {
      setLoading(true)
      setLoadError('')

      try {
        const tasks = [loadSubscriptions()]
        if (canCreate) tasks.push(loadMembers())
        if (isSubscriberView) tasks.push(loadDues())
        await Promise.all(tasks)
      } catch (error) {
        setLoadError(error.response?.data?.message ?? t('subscriptionsPage.loadError'))
      } finally {
        setLoading(false)
      }
    }

    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canCreate, isSubscriberView])

  async function loadSubscriptions() {
    const data = await getSubscriptions()
    setSubscriptions(data)
  }

  // Only fetched for the subscriber's due-centric view — GET /api/dues is
  // already scoped server-side to the caller's own dues (DueController),
  // and is the authoritative source for amount_due/amount_paid/balance/
  // status, including a due that has no payment recorded against it yet.
  async function loadDues() {
    const data = await getDues()
    setDues(data)
  }

  async function loadMembers() {
    const data = await getMembers()
    setMembers(data)
  }

  function handleChange(event) {
    const { name, value } = event.target

    // The subscription covers the calendar year of the payment, so it
    // always expires on the first day of the following year — derived
    // automatically rather than left for manual (and error-prone) entry.
    if (name === 'payment_date') {
      const year = value ? Number(value.slice(0, 4)) : null
      const expiresAt = year ? `${year + 1}-01-01` : ''
      setForm((current) => ({ ...current, payment_date: value, expires_at: expiresAt }))
      return
    }

    setForm((current) => ({ ...current, [name]: value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setErrorMessage('')

    try {
      await createSubscription({
        ...form,
        amount: Number(form.amount),
      })
      setForm(initialForm)
      await loadSubscriptions()
    } catch (error) {
      setErrorMessage(
        getErrorMessage(error, t('subscriptionsPage.createError')),
      )
    }
  }

  async function handleVerify(subscriptionId) {
    setErrorMessage('')

    try {
      await verifySubscription(subscriptionId)
      await loadSubscriptions()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('subscriptionsPage.verifyError')))
    }
  }

  // Records — and immediately verifies — a subscription payment for the
  // amount entered next to the button (defaults to, but can be less than,
  // the due's full remaining balance). Only verified payments count toward
  // a due's balance (see DuesService::recalculate), hence the immediate
  // verify right after creation.
  async function handleCompleteDue(subscription) {
    const due = subscription.due
    if (!due) return

    const rawAmount = completeAmounts[due.id] ?? due.balance
    const amount = Number(rawAmount)

    if (!Number.isFinite(amount) || amount <= 0 || amount > due.balance) {
      setErrorMessage(t('subscriptionsPage.completeDueInvalidAmount', { amount: formatCurrency(due.balance) }))
      return
    }

    const paymentMethod = window.prompt(
      t('subscriptionsPage.completeDuePrompt'),
      subscription.payment_method || '',
    )
    if (!paymentMethod || !paymentMethod.trim()) return

    setErrorMessage('')

    try {
      const today = new Date().toISOString().slice(0, 10)
      const newSubscription = await createSubscription({
        member_id: subscription.member.id,
        due_id: due.id,
        amount,
        payment_method: paymentMethod.trim(),
        payment_date: today,
        expires_at: `${due.year + 1}-01-01`,
        notes: t('subscriptionsPage.completeDueNote', { year: due.year }),
      })
      await verifySubscription(newSubscription.id)
      setCompleteAmounts((current) => {
        const next = { ...current }
        delete next[due.id]
        return next
      })
      setCompletingDueId(null)
      await loadSubscriptions()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('subscriptionsPage.completeDueError')))
    }
  }

  async function handleReceiptUpload(subscriptionId, event) {
    const file = event.target.files?.[0]
    if (!file) return

    setErrorMessage('')

    try {
      await uploadSubscriptionReceipt(subscriptionId, file)
      await loadSubscriptions()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('subscriptionsPage.uploadReceiptError')))
    } finally {
      event.target.value = ''
    }
  }

  const filteredSubscriptions = useMemo(() => {
    if (mode === 'pending') {
      return subscriptions.filter((item) => item.status === 'pending')
    }

    if (mode === 'receipts') {
      return subscriptions.filter((item) => item.receipt_number || item.receipt_file)
    }

    return subscriptions
  }, [mode, subscriptions])

  return (
    <PresidentLayout
      title={
        isSubscriberView
          ? t('subscriptionsPage.duesOverview.pageTitle')
          : mode === 'pending'
            ? t('subscriptionsPage.pendingPayments')
            : mode === 'receipts'
              ? t('subscriptionsPage.receipts')
              : t('subscriptionsPage.title')
      }
      description={isSubscriberView ? t('subscriptionsPage.duesOverview.pageDescription') : t('subscriptionsPage.description')}
      breadcrumbs={['Subscriptions']}
    >
      {loadError ? (
        <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {loadError}
        </div>
      ) : null}

      {isSubscriberView ? (
        <SubscriberDuesOverview
          loading={loading}
          dues={dues}
          subscriptions={subscriptions}
          user={user}
          onUploadReceipt={handleReceiptUpload}
          errorMessage={errorMessage}
        />
      ) : (
      <div className={canCreate ? 'grid grid-cols-1 gap-6 xl:grid-cols-[420px_minmax(0,1fr)]' : ''}>
        {canCreate ? (
          <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
            <div className="mb-5 flex items-center gap-3">
              <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <Receipt size={20} />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{t('subscriptionsPage.createTitle')}</h3>
                <p className="text-sm text-slate-500">{t('subscriptionsPage.createSubtitle')}</p>
              </div>
            </div>

            <form className="space-y-4" onSubmit={handleSubmit}>
              {errorMessage ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                  {errorMessage}
                </div>
              ) : null}

              <select
                name="member_id"
                value={form.member_id}
                onChange={handleChange}
                className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
              >
                <option value="">{t('subscriptionsPage.selectMember')}</option>
                {members.map((member) => (
                  <option key={member.id} value={member.id}>
                    {member.user.name}
                  </option>
                ))}
              </select>

              {FORM_FIELDS.map((field) => (
                <input
                  key={field}
                  type={DATE_FIELDS.has(field) ? 'date' : field === 'amount' ? 'number' : 'text'}
                  name={field}
                  placeholder={t(`subscriptionsPage.fields.${field}`)}
                  value={form[field]}
                  onChange={handleChange}
                  className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
                />
              ))}

              <textarea
                name="notes"
                value={form.notes}
                onChange={handleChange}
                placeholder={t('subscriptionsPage.notes')}
                className="min-h-[96px] w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 py-3 text-sm outline-none"
              />

              <button className="w-full rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white">
                {t('subscriptionsPage.saveSubscription')}
              </button>
            </form>
          </section>
        ) : null}

        <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="border-b border-[#eef2ff] px-6 py-5">
            <h3 className="text-lg font-semibold text-slate-900">{t('subscriptionsPage.ledgerTitle')}</h3>
            <p className="text-sm text-slate-500">{t('subscriptionsPage.recordCount', { count: filteredSubscriptions.length })}</p>
          </div>

          <div className="divide-y divide-[#eef2ff]">
            {loading ? (
              <div className="space-y-3 px-6 py-8">
                <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
              </div>
            ) : filteredSubscriptions.length === 0 ? (
              <div className="px-6 py-8 text-sm text-slate-500">
                {mode === 'pending' ? t('subscriptionsPage.emptyPending') : t('subscriptionsPage.emptyAll')}
              </div>
            ) : (
              filteredSubscriptions.map((subscription) => (
              <div key={subscription.id} className="flex items-center justify-between gap-4 px-6 py-4">
                <div>
                  <p className="font-semibold text-slate-800">{subscription.member.name}</p>
                  <p className="text-sm text-slate-500">
                    {formatCurrency(subscription.amount)} • {subscription.payment_method}
                  </p>
                  <p className="text-xs text-slate-400">
                    {subscription.payment_date} → {subscription.expires_at}
                  </p>
                  <div className="mt-2 flex flex-wrap items-center gap-2">
                    <span className="rounded-full bg-[#f7f9ff] px-3 py-1 text-xs font-semibold text-slate-600">
                      {t('subscriptionsPage.receiptLabel', { number: subscription.receipt_number || t('subscriptionsPage.receiptNotProvided') })}
                    </span>

                    {subscription.receipt_url ? (
                      <a
                        href={subscription.receipt_url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 rounded-full border border-blue-200 px-3 py-1 text-xs font-semibold text-blue-600 transition hover:bg-blue-50"
                      >
                        <ExternalLink size={12} />
                        {t('subscriptionsPage.viewReceipt')}
                      </a>
                    ) : (
                      <span className="text-xs text-slate-400">{t('subscriptionsPage.noFileUploaded')}</span>
                    )}

                    {subscription.due ? (
                      <span className="inline-flex items-center gap-1.5 rounded-full bg-[#f7f9ff] px-1 py-1 pr-3 text-xs font-semibold text-slate-600">
                        <DueStatusBadge status={subscription.due.status} />
                        {t('subscriptionsPage.dueYear', { year: subscription.due.year })}
                        {!['paid', 'waived'].includes(subscription.due.status)
                          ? ` · ${t('subscriptionsPage.remaining', { amount: formatCurrency(subscription.due.balance) })}`
                          : ''}
                      </span>
                    ) : null}
                  </div>
                </div>

                <div className="flex flex-col items-end gap-2">
                  <span className={statusBadgeClass(subscription.status)}>
                    {statusLabel(subscription.status)}
                  </span>

                  <div className="flex items-center gap-2">
                    {subscription.status === 'pending' && canVerifySubscription(user) ? (
                      <button
                        type="button"
                        onClick={() => handleVerify(subscription.id)}
                        className="rounded-xl border border-emerald-200 px-3 py-2 text-emerald-600 transition hover:bg-emerald-50"
                      >
                        <CheckCircle2 size={16} />
                      </button>
                    ) : null}

                    {canUploadSubscriptionReceipt(user, subscription) ? (
                      <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-blue-200 px-3 py-2 text-sm font-medium text-blue-600 transition hover:bg-blue-50">
                        <Upload size={16} />
                        {subscription.receipt_url ? t('subscriptionsPage.replaceReceipt') : t('subscriptionsPage.uploadReceipt')}
                        <input
                          type="file"
                          accept=".pdf,.jpg,.jpeg,.png"
                          className="hidden"
                          onChange={(event) => handleReceiptUpload(subscription.id, event)}
                        />
                      </label>
                    ) : null}

                    {subscription.due &&
                    !['paid', 'waived'].includes(subscription.due.status) &&
                    canCreateSubscriptionEntry(user) ? (
                      completingDueId === subscription.due.id ? (
                        <>
                          <input
                            type="number"
                            min="0.01"
                            max={subscription.due.balance}
                            step="0.01"
                            autoFocus
                            value={completeAmounts[subscription.due.id] ?? subscription.due.balance}
                            onChange={(event) =>
                              setCompleteAmounts((current) => ({
                                ...current,
                                [subscription.due.id]: event.target.value,
                              }))
                            }
                            title={t('subscriptionsPage.completeDueAmountLabel')}
                            className="h-11 w-24 rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-3 text-sm outline-none"
                          />
                          <button
                            type="button"
                            onClick={() => handleCompleteDue(subscription)}
                            title={t('subscriptionsPage.completeDueTooltip')}
                            className="inline-flex items-center gap-2 rounded-xl border border-indigo-200 px-3 py-2 text-sm font-medium text-indigo-600 transition hover:bg-indigo-50"
                          >
                            <HandCoins size={16} />
                            {t('subscriptionsPage.completeDueSubmit')}
                          </button>
                          <button
                            type="button"
                            onClick={() => setCompletingDueId(null)}
                            title={t('subscriptionsPage.completeDueCancel')}
                            className="rounded-xl border border-[#dfe5ff] px-3 py-2 text-slate-400 transition hover:bg-slate-50 hover:text-slate-600"
                          >
                            <X size={16} />
                          </button>
                        </>
                      ) : (
                        <button
                          type="button"
                          onClick={() => setCompletingDueId(subscription.due.id)}
                          title={t('subscriptionsPage.completeDueTooltip')}
                          className="inline-flex items-center gap-2 rounded-xl border border-indigo-200 px-3 py-2 text-sm font-medium text-indigo-600 transition hover:bg-indigo-50"
                        >
                          <HandCoins size={16} />
                          {t('subscriptionsPage.completeDue')}
                        </button>
                      )
                    ) : null}
                  </div>

                  {mode !== 'receipts' ? (
                    <button
                      type="button"
                      className="text-xs font-semibold text-blue-600"
                      onClick={() => {
                        window.location.href = '/subscriptions/receipts'
                      }}
                    >
                      {t('subscriptionsPage.openReceiptsView')}
                    </button>
                  ) : null}
                </div>
              </div>
              ))
            )}
          </div>
        </section>
      </div>
      )}
    </PresidentLayout>
  )
}
