import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import RejectReasonModal from '../components/committee/RejectReasonModal'
import { formatDate } from '../components/committee/committeeUtils'
import { useAuth } from '../context/auth-context'
import { canReviewPhaseRequest } from '../lib/roles'
import { phaseLabel, phaseStyle } from '../lib/projectPhase'
import { approvePhaseRequest, getPendingPhaseRequests, rejectPhaseRequest } from '../api/phaseRequests.api'

export default function PendingPhaseRequestsPage() {
  const { t } = useTranslation(['approvals', 'common'])
  const { user } = useAuth()
  const canReview = canReviewPhaseRequest(user)
  const [requests, setRequests] = useState([])
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')
  const [processingId, setProcessingId] = useState(null)
  const [rejectingId, setRejectingId] = useState(null)

  useEffect(() => {
    if (!canReview) return

    loadRequests()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [canReview])

  async function loadRequests() {
    setLoading(true)
    setErrorMessage('')

    try {
      const data = await getPendingPhaseRequests()
      setRequests(data)
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('phaseRequestsPage.loadError'))
    } finally {
      setLoading(false)
    }
  }

  if (!canReview) {
    return <Navigate to="/dashboard" replace />
  }

  async function handleApprove(requestId) {
    setProcessingId(requestId)
    setErrorMessage('')

    try {
      await approvePhaseRequest(requestId)
      setRequests((current) => current.filter((request) => request.id !== requestId))
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('phaseRequestsPage.approveError'))
    } finally {
      setProcessingId(null)
    }
  }

  async function handleRejectConfirm(reason) {
    setProcessingId(rejectingId)

    try {
      await rejectPhaseRequest(rejectingId, reason)
      setRequests((current) => current.filter((request) => request.id !== rejectingId))
      setRejectingId(null)
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('phaseRequestsPage.rejectError'))
    } finally {
      setProcessingId(null)
    }
  }

  return (
    <PresidentLayout
      title={t('phaseRequestsPage.title')}
      description={t('phaseRequestsPage.description')}
      breadcrumbs={['Projects', 'Pending Phase Requests']}
    >
      {errorMessage ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {errorMessage}
        </div>
      ) : null}

      <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="border-b border-[#eef2ff] px-6 py-5">
          <h3 className="text-lg font-semibold text-slate-900">{t('phaseRequestsPage.pendingRequests')}</h3>
          <p className="text-sm text-slate-500">{t('phaseRequestsPage.awaitingReview', { count: requests.length })}</p>
        </div>

        {loading ? (
          <div className="space-y-3 px-6 py-8">
            <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
          </div>
        ) : requests.length === 0 ? (
          <p className="px-6 py-8 text-sm text-slate-500">{t('phaseRequestsPage.empty')}</p>
        ) : (
          <div className="w-full divide-y divide-[#eef2ff]">
            {requests.map((request) => (
              <div key={request.id} className="flex w-full flex-col gap-4 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 flex-1 space-y-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <p className="font-semibold text-slate-900">{request.project?.name ?? t('phaseRequestsPage.unknownProject')}</p>
                      <p className="text-sm text-slate-500">
                        <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${phaseStyle(request.from_phase)}`}>
                          {phaseLabel(request.from_phase)}
                        </span>
                        {' → '}
                        <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${phaseStyle(request.to_phase)}`}>
                          {phaseLabel(request.to_phase)}
                        </span>
                      </p>
                    </div>
                    <p className="text-xs text-slate-400">
                      {t('phaseRequestsPage.requestedBy', { name: request.requested_by?.name ?? t('phaseRequestsPage.unknown'), date: formatDate(request.requested_at) })}
                    </p>
                  </div>

                  <p className="text-sm text-slate-700">{request.summary}</p>
                  {request.notes ? <p className="text-sm text-slate-500">{request.notes}</p> : null}

                  {request.proofs?.length ? (
                    <div className="flex flex-wrap gap-3">
                      {request.proofs.map((proof) =>
                        proof.mime_type?.startsWith('image/') ? (
                          <a key={proof.id} href={proof.url} target="_blank" rel="noreferrer">
                            <img
                              src={proof.url}
                              alt={proof.original_name}
                              className="h-20 w-20 rounded-xl border border-[#dfe5ff] object-cover"
                            />
                          </a>
                        ) : (
                          <a
                            key={proof.id}
                            href={proof.url}
                            target="_blank"
                            rel="noreferrer"
                            className="flex h-20 min-w-40 max-w-full items-center justify-center wrap-break-word rounded-xl border border-[#dfe5ff] bg-[#f7f9ff] px-3 text-center text-xs font-semibold text-blue-600"
                          >
                            {proof.original_name}
                          </a>
                        ),
                      )}
                    </div>
                  ) : null}
                </div>

                <div className="flex shrink-0 gap-2 lg:flex-col lg:items-stretch">
                  <button
                    type="button"
                    disabled={processingId === request.id}
                    onClick={() => handleApprove(request.id)}
                    className="rounded-full bg-emerald-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
                  >
                    {t('common:actions.approve')}
                  </button>
                  <button
                    type="button"
                    disabled={processingId === request.id}
                    onClick={() => setRejectingId(request.id)}
                    className="rounded-full border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"
                  >
                    {t('common:actions.reject')}
                  </button>
                </div>
              </div>
            ))}
          </div>
        )}
      </section>

      <RejectReasonModal
        open={rejectingId != null}
        submitting={processingId === rejectingId}
        title={t('phaseRequest.rejectTitle')}
        description={t('phaseRequest.rejectDescription')}
        onCancel={() => setRejectingId(null)}
        onConfirm={handleRejectConfirm}
      />
    </PresidentLayout>
  )
}
