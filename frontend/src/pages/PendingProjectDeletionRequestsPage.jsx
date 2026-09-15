import { useEffect, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import RejectReasonModal from '../components/committee/RejectReasonModal'
import { formatDate } from '../components/committee/committeeUtils'
import { useAuth } from '../context/auth-context'
import { canReviewProjectDeletionRequest } from '../lib/roles'
import {
  approveProjectDeletionRequest,
  getPendingProjectDeletionRequests,
  rejectProjectDeletionRequest,
} from '../api/projects.api'

export default function PendingProjectDeletionRequestsPage() {
  const { t } = useTranslation(['approvals', 'common'])
  const { user } = useAuth()
  const canReview = canReviewProjectDeletionRequest(user)
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
      const data = await getPendingProjectDeletionRequests()
      setRequests(data)
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('deletionRequestsPage.loadError'))
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
      await approveProjectDeletionRequest(requestId)
      setRequests((current) => current.filter((request) => request.id !== requestId))
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('deletionRequestsPage.approveError'))
    } finally {
      setProcessingId(null)
    }
  }

  async function handleRejectConfirm(reason) {
    setProcessingId(rejectingId)

    try {
      await rejectProjectDeletionRequest(rejectingId, reason)
      setRequests((current) => current.filter((request) => request.id !== rejectingId))
      setRejectingId(null)
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('deletionRequestsPage.rejectError'))
    } finally {
      setProcessingId(null)
    }
  }

  return (
    <PresidentLayout
      title={t('deletionRequestsPage.title')}
      description={t('deletionRequestsPage.description')}
      breadcrumbs={['Projects', 'Pending Deletion Requests']}
    >
      {errorMessage ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {errorMessage}
        </div>
      ) : null}

      <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="border-b border-[#eef2ff] px-6 py-5">
          <h3 className="text-lg font-semibold text-slate-900">{t('deletionRequestsPage.pendingRequests')}</h3>
          <p className="text-sm text-slate-500">{t('deletionRequestsPage.awaitingReview', { count: requests.length })}</p>
        </div>

        {loading ? (
          <div className="space-y-3 px-6 py-8">
            <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
          </div>
        ) : requests.length === 0 ? (
          <p className="px-6 py-8 text-sm text-slate-500">{t('deletionRequestsPage.empty')}</p>
        ) : (
          <div className="w-full divide-y divide-[#eef2ff]">
            {requests.map((request) => (
              <div key={request.id} className="flex w-full flex-col gap-4 px-6 py-5 lg:flex-row lg:items-start lg:justify-between">
                <div className="min-w-0 flex-1 space-y-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-semibold text-slate-900">{request.project?.name ?? request.project_name}</p>
                    <p className="text-xs text-slate-400">
                      {t('deletionRequestsPage.requestedBy', { name: request.requested_by?.name ?? t('deletionRequestsPage.unknown'), date: formatDate(request.requested_at) })}
                    </p>
                  </div>

                  <p className="text-sm text-slate-700">{request.reason}</p>
                </div>

                <div className="flex shrink-0 gap-2 lg:flex-col lg:items-stretch">
                  <button
                    type="button"
                    disabled={processingId === request.id}
                    onClick={() => handleApprove(request.id)}
                    className="rounded-full bg-rose-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60"
                  >
                    {t('deletionRequestsPage.approveAndDelete')}
                  </button>
                  <button
                    type="button"
                    disabled={processingId === request.id}
                    onClick={() => setRejectingId(request.id)}
                    className="rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-600 transition hover:bg-slate-50 disabled:opacity-60"
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
        title={t('deletionRequestsPage.rejectTitle')}
        description={t('deletionRequestsPage.rejectDescription')}
        onCancel={() => setRejectingId(null)}
        onConfirm={handleRejectConfirm}
      />
    </PresidentLayout>
  )
}
