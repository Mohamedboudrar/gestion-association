import { CheckCircle2, ChevronDown, ChevronUp, ExternalLink, Upload } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import DueStatusBadge from '../committee/DueStatusBadge'
import { formatCurrency } from '../committee/committeeUtils'
import { canUploadSubscriptionReceipt } from '../../lib/roles'
import { useStatusLabel } from '../../hooks/useStatusLabel'
import { buildDueGroups } from '../../lib/duesGrouping'

// Subscriber-facing "Annual Dues" view — the Due (not the individual
// payment) is the primary entity: one card per year, each showing the
// amount/paid/remaining/progress computed server-side (DuesService), with
// its payment history collapsed by default. Read-only w.r.t. business
// logic: every figure and status comes straight from the Due/Subscription
// resources already fetched by SubscriptionsPage — no new API calls here,
// no recalculation of anything the backend already owns.
export default function SubscriberDuesOverview({ loading, dues, subscriptions, user, onUploadReceipt, errorMessage }) {
  const { t } = useTranslation('finance')
  const statusLabel = useStatusLabel()
  const [expandedDueIds, setExpandedDueIds] = useState(() => new Set())

  function toggleExpanded(dueId) {
    setExpandedDueIds((current) => {
      const next = new Set(current)
      if (next.has(dueId)) {
        next.delete(dueId)
      } else {
        next.add(dueId)
      }
      return next
    })
  }

  if (loading) {
    return (
      <div className="space-y-4">
        {[0, 1].map((key) => (
          <div
            key={key}
            className="animate-pulse rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]"
          >
            <div className="h-4 w-1/3 rounded bg-slate-100" />
            <div className="mt-4 h-3 w-full rounded bg-slate-100" />
            <div className="mt-2 h-3 w-2/3 rounded bg-slate-100" />
          </div>
        ))}
      </div>
    )
  }

  const groups = buildDueGroups(dues, subscriptions)

  return (
    <div className="space-y-4">
      {errorMessage ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
          {errorMessage}
        </div>
      ) : null}

      {groups.length === 0 ? (
        <div className="rounded-3xl border border-dashed border-[#dfe5ff] bg-white px-6 py-12 text-center shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <p className="text-base font-semibold text-slate-800">{t('subscriptionsPage.duesOverview.noDuesTitle')}</p>
          <p className="mt-1 text-sm text-slate-500">{t('subscriptionsPage.duesOverview.noDuesBody')}</p>
        </div>
      ) : (
        groups.map(({ due, payments, percentage }) => {
          const isExpanded = expandedDueIds.has(due.id)
          const isSettled = ['paid', 'waived'].includes(due.status)

          return (
            <div
              key={due.id}
              className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]"
            >
              <div className="flex flex-wrap items-center justify-between gap-3">
                <h3 className="text-lg font-semibold text-slate-900">
                  {t('subscriptionsPage.duesOverview.cardTitle', { year: due.year })}
                </h3>
                <DueStatusBadge status={due.status} />
              </div>

              <div className="mt-5 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    {t('subscriptionsPage.duesOverview.annualAmount')}
                  </p>
                  <p className="mt-1 text-base font-semibold text-slate-900">{formatCurrency(due.amount_due)}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    {t('subscriptionsPage.duesOverview.amountPaid')}
                  </p>
                  <p className="mt-1 text-base font-semibold text-emerald-600">{formatCurrency(due.amount_paid)}</p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    {t('subscriptionsPage.duesOverview.remainingBalance')}
                  </p>
                  <p className={`mt-1 text-base font-semibold ${due.balance > 0 && !isSettled ? 'text-amber-600' : 'text-slate-400'}`}>
                    {formatCurrency(due.balance)}
                  </p>
                </div>
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                    {t('subscriptionsPage.duesOverview.payments')}
                  </p>
                  <p className="mt-1 text-base font-semibold text-slate-900">
                    {t('subscriptionsPage.duesOverview.paymentsCount', { count: payments.length })}
                  </p>
                </div>
              </div>

              <div className="mt-4">
                <div className="h-2.5 w-full overflow-hidden rounded-full bg-[#f1f4ff]">
                  <div className="h-full rounded-full bg-blue-600 transition-all" style={{ width: `${percentage}%` }} />
                </div>
                <p className="mt-1.5 text-right text-xs font-semibold text-slate-500">{t('subscriptionsPage.duesOverview.progress', { percentage })}</p>
              </div>

              <button
                type="button"
                onClick={() => toggleExpanded(due.id)}
                className="mt-4 inline-flex items-center gap-1.5 text-sm font-semibold text-blue-600 transition hover:text-blue-700"
              >
                {isExpanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
                {isExpanded ? t('subscriptionsPage.duesOverview.collapse') : t('subscriptionsPage.duesOverview.expand')}
              </button>

              {isExpanded ? (
                <div className="mt-4 space-y-3 border-t border-[#eef2ff] pt-4">
                  <p className="text-sm font-semibold text-slate-700">{t('subscriptionsPage.duesOverview.paymentHistoryTitle')}</p>

                  {payments.length === 0 ? (
                    <p className="text-sm text-slate-500">{t('subscriptionsPage.duesOverview.noPayments')}</p>
                  ) : (
                    payments.map((payment) => (
                      <div
                        key={payment.id}
                        className="flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-[#f7f9ff] px-4 py-3"
                      >
                        <div>
                          <p className="text-sm font-semibold text-slate-800">{formatCurrency(payment.amount)}</p>
                          <p className="text-xs text-slate-500">
                            {payment.payment_date} • {payment.payment_method}
                          </p>
                        </div>

                        <div className="flex flex-wrap items-center gap-2">
                          <span className="inline-flex items-center rounded-full bg-white px-2.5 py-1 text-xs font-semibold leading-none text-slate-600">
                            {statusLabel(payment.status)}
                          </span>

                          {payment.receipt_url ? (
                            <a
                              href={payment.receipt_url}
                              target="_blank"
                              rel="noreferrer"
                              className="inline-flex items-center gap-1 rounded-full border border-blue-200 bg-white px-3 py-1 text-xs font-semibold text-blue-600 transition hover:bg-blue-50"
                            >
                              <ExternalLink size={12} />
                              {t('subscriptionsPage.viewReceipt')}
                            </a>
                          ) : null}

                          {canUploadSubscriptionReceipt(user, payment) ? (
                            <label className="inline-flex cursor-pointer items-center gap-1 rounded-full border border-blue-200 bg-white px-3 py-1 text-xs font-semibold text-blue-600 transition hover:bg-blue-50">
                              <Upload size={12} />
                              {payment.receipt_url ? t('subscriptionsPage.replaceReceipt') : t('subscriptionsPage.uploadReceipt')}
                              <input
                                type="file"
                                accept=".pdf,.jpg,.jpeg,.png"
                                className="hidden"
                                onChange={(event) => onUploadReceipt(payment.id, event)}
                              />
                            </label>
                          ) : null}
                        </div>
                      </div>
                    ))
                  )}

                  {!isSettled ? (
                    <p className="pt-1 text-xs text-slate-400">{t('subscriptionsPage.duesOverview.awaitingNewPayment')}</p>
                  ) : null}
                </div>
              ) : null}

              {isSettled ? (
                <p className="mt-4 flex items-center gap-2 text-sm font-semibold text-emerald-600">
                  <CheckCircle2 size={16} />
                  {t('subscriptionsPage.duesOverview.fullyPaid')}
                </p>
              ) : null}
            </div>
          )
        })
      )}
    </div>
  )
}
