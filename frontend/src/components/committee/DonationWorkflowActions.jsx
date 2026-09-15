import { CheckCircle2, Send, XCircle } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  canApproveDonation,
  canRejectDonation,
  canSubmitDonation,
} from '../../lib/roles'
import RejectReasonModal from './RejectReasonModal'

// Renders whichever workflow buttons the current user/donation/project
// combination allows — shared by the project workspace (which has
// `committeeScope` for the create/submit role check) and the cross-project
// donation list (which only needs the approval-role checks). Mirrors
// ExpenseWorkflowActions exactly, minus Mark Paid — donations have no paid
// state, only draft/pending/approved/rejected.
export default function DonationWorkflowActions({
  user,
  donation,
  project = null,
  committeeScope = null,
  onSubmit,
  onApprove,
  onReject,
}) {
  const { t } = useTranslation(['approvals', 'common'])
  const [rejecting, setRejecting] = useState(false)
  const [submittingReject, setSubmittingReject] = useState(false)

  const showSubmit = canSubmitDonation(user, committeeScope, donation)
  const showApprove = canApproveDonation(user, donation, project)
  const showReject = canRejectDonation(user, donation, project)

  if (!showSubmit && !showApprove && !showReject) {
    return null
  }

  async function handleRejectConfirm(reason, rejectionType) {
    setSubmittingReject(true)

    try {
      await onReject(donation.id, reason, rejectionType)
      setRejecting(false)
    } finally {
      setSubmittingReject(false)
    }
  }

  const isResubmit = donation.status === 'rejected' && donation.rejection_type === 'receipt'

  const donationRejectionTypes = [
    {
      value: 'receipt',
      label: t('approvals:rejectionTypes.receiptIssue.label'),
      description: t('approvals:rejectionTypes.receiptIssue.description'),
    },
    {
      value: 'details',
      label: t('approvals:rejectionTypes.donationDetailsIssue.label'),
      description: t('approvals:rejectionTypes.donationDetailsIssue.description'),
    },
  ]

  return (
    <div className="flex flex-wrap gap-2">
      {showSubmit ? (
        <button
          type="button"
          onClick={() => onSubmit(donation.id)}
          className="inline-flex items-center gap-1 rounded-full border border-blue-200 px-3 py-1.5 text-xs font-semibold text-blue-600 transition hover:bg-blue-50"
        >
          <Send size={14} />
          {isResubmit ? t('approvals:workflowActions.submitAgain') : t('approvals:workflowActions.submit')}
        </button>
      ) : null}

      {showApprove ? (
        <button
          type="button"
          onClick={() => onApprove(donation.id)}
          className="inline-flex items-center gap-1 rounded-full border border-emerald-200 px-3 py-1.5 text-xs font-semibold text-emerald-600 transition hover:bg-emerald-50"
        >
          <CheckCircle2 size={14} />
          {t('common:actions.approve')}
        </button>
      ) : null}

      {showReject ? (
        <button
          type="button"
          onClick={() => setRejecting(true)}
          className="inline-flex items-center gap-1 rounded-full border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 transition hover:bg-rose-50"
        >
          <XCircle size={14} />
          {t('common:actions.reject')}
        </button>
      ) : null}

      <RejectReasonModal
        open={rejecting}
        submitting={submittingReject}
        onCancel={() => setRejecting(false)}
        onConfirm={handleRejectConfirm}
        title={t('approvals:rejectModal.rejectDonationTitle')}
        description={t('approvals:rejectModal.rejectDonationDescription')}
        rejectionTypes={donationRejectionTypes}
      />
    </div>
  )
}
