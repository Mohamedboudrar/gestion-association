import { X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { getActivityLog } from '../../api/activityLogs.api'
import { statusToneClass } from '../../lib/activityLog'
import { stageLabel } from '../../lib/projectLifecycle'

function Row({ label, children }) {
  return (
    <div className="border-b border-[#eef2ff] px-6 py-4 last:border-b-0">
      <p className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-400">{label}</p>
      <div className="mt-1 text-sm text-slate-800">{children}</div>
    </div>
  )
}

function ValueDiff({ label, value }) {
  if (!value || Object.keys(value).length === 0) return null

  return (
    <div>
      <p className="mb-1 text-xs font-semibold text-slate-500">{label}</p>
      <pre className="max-h-40 overflow-auto rounded-xl bg-slate-50 p-3 text-xs text-slate-700">
        {JSON.stringify(value, null, 2)}
      </pre>
    </div>
  )
}

export default function ActivityDetailPanel({ activityId, onClose }) {
  const { t } = useTranslation('association')
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')

  useEffect(() => {
    if (!activityId) return undefined

    let cancelled = false
    setLoading(true)
    setErrorMessage('')
    setDetail(null)

    getActivityLog(activityId)
      .then((data) => {
        if (!cancelled) setDetail(data)
      })
      .catch((error) => {
        if (!cancelled) setErrorMessage(error.response?.data?.message ?? t('activityLog.detail.loadError'))
      })
      .finally(() => {
        if (!cancelled) setLoading(false)
      })

    return () => {
      cancelled = true
    }
  }, [activityId, t])

  const open = Boolean(activityId)
  const hasChanges = detail?.changes && (detail.changes.old || detail.changes.new)
  const locale = 'fr-FR'

  return (
    <>
      <div
        className={`fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-sm transition-opacity ${
          open ? 'opacity-100' : 'pointer-events-none opacity-0'
        }`}
        onClick={onClose}
        aria-hidden="true"
      />

      <aside
        className={`fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col bg-white shadow-2xl transition-transform duration-200 ease-in-out ${
          open ? 'translate-x-0' : 'translate-x-full'
        }`}
      >
        <div className="flex items-center justify-between border-b border-[#eef2ff] px-6 py-5">
          <h3 className="text-lg font-semibold text-slate-900">{t('activityLog.detail.title')}</h3>
          <button
            type="button"
            onClick={onClose}
            className="flex h-9 w-9 items-center justify-center rounded-xl border border-[#eef2ff] text-slate-400 transition hover:bg-slate-50"
            aria-label={t('activityLog.detail.close')}
          >
            <X size={16} />
          </button>
        </div>

        <div className="flex-1 overflow-y-auto">
          {loading ? (
            <div className="px-6 py-8 text-sm text-slate-500">{t('activityLog.detail.loading')}</div>
          ) : errorMessage ? (
            <div className="mx-6 mt-6 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
              {errorMessage}
            </div>
          ) : detail ? (
            <>
              <Row label={t('activityLog.detail.message')}>
                <p className="font-semibold text-slate-900">{detail.message}</p>
              </Row>

              <Row label={t('activityLog.detail.who')}>{detail.causer?.name ?? t('activityLog.detail.system')}</Row>

              <Row label={t('activityLog.detail.when')}>{new Date(detail.created_at).toLocaleString(locale)}</Row>

              <Row label={t('activityLog.detail.action')}>
                <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold leading-none ${statusToneClass(detail.status?.value)}`}>
                  {detail.action_label}
                </span>
              </Row>

              <Row label={t('activityLog.detail.entity')}>
                {detail.entity_label} {detail.reference}
              </Row>

              {detail.status ? (
                <Row label={t('activityLog.detail.status')}>
                  <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold leading-none ${statusToneClass(detail.status.value)}`}>
                    {detail.status.label}
                  </span>
                </Row>
              ) : null}

              {hasChanges ? (
                <Row label={t('activityLog.detail.changes')}>
                  <div className="space-y-3">
                    <ValueDiff label={t('activityLog.detail.previousValues')} value={detail.changes.old} />
                    <ValueDiff label={t('activityLog.detail.newValues')} value={detail.changes.new} />
                  </div>
                </Row>
              ) : (
                <Row label={t('activityLog.detail.changes')}>
                  <p className="text-sm text-slate-400">{t('activityLog.detail.noChanges')}</p>
                </Row>
              )}

              {detail.related?.project ? (
                <Row label={t('activityLog.detail.relatedProject')}>
                  <p className="font-semibold text-slate-800">{detail.related.project.name}</p>
                  {detail.related.project.status ? (
                    <p className="mt-1 text-xs text-slate-500">{stageLabel(detail.related.project.status)}</p>
                  ) : null}
                </Row>
              ) : null}

              {detail.related?.member ? (
                <Row label={t('activityLog.detail.relatedSubscriber')}>{detail.related.member.name}</Row>
              ) : null}

              {detail.related?.donation ? (
                <Row label={t('activityLog.detail.relatedDonation')}>
                  <p>{detail.related.donation.donor_name ?? detail.related.donation.member?.name ?? t('activityLog.detail.donationFallback')} — {detail.related.donation.amount}</p>
                </Row>
              ) : null}

              {detail.related?.expense ? (
                <Row label={t('activityLog.detail.relatedExpense')}>
                  <p>{detail.related.expense.supplier_name} — {detail.related.expense.amount}</p>
                </Row>
              ) : null}
            </>
          ) : null}
        </div>
      </aside>
    </>
  )
}
