import { CheckCircle2, Send, Wallet2, XCircle } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  canApproveExpense,
  canMarkExpensePaid,
  canRejectExpense,
  canSubmitExpense,
} from '../../lib/roles'
import RejectReasonModal from './RejectReasonModal'

// Renders whichever workflow buttons the current user/expense/project
// combination allows — shared by the project workspace (which has
// `committeeScope` for the create/submit role check) and the cross-project
// Pending Approvals page (which only needs the approval-role checks).
export default function ExpenseWorkflowActions({
  user,
  expense,
  project = null,
  committeeScope = null,
  onSubmit,
  onApprove,
  onReject,
  onMarkPaid,
}) {
  const { t } = useTranslation(['approvals', 'common'])
  const [rejecting, setRejecting] = useState(false)
  const [submittingReject, setSubmittingReject] = useState(false)

  const showSubmit = canSubmitExpense(user, committeeScope, expense)
  const showApprove = canApproveExpense(user, expense, project)
  const showReject = canRejectExpense(user, expense, project)
  const showMarkPaid = canMarkExpensePaid(user, expense, project)

  if (!showSubmit && !showApprove && !showReject && !showMarkPaid) {
    return null
  }

  async function handleRejectConfirm(reason, rejectionType) {
    setSubmittingReject(true)

    try {
      await onReject(expense.id, reason, rejectionType)
      setRejecting(false)
    } finally {
      setSubmittingReject(false)
    }
  }

  const isResubmit = expense.status === 'rejected' && expense.rejection_type === 'invoice'

  return (
    <div className="flex flex-wrap gap-2">
      {showSubmit ? (
        <button
          type="button"
          onClick={() => onSubmit(expense.id)}
          className="inline-flex items-center gap-1 rounded-full border border-blue-200 px-3 py-1.5 text-xs font-semibold text-blue-600 transition hover:bg-blue-50"
        >
          <Send size={14} />
          {isResubmit ? t('approvals:workflowActions.submitAgain') : t('approvals:workflowActions.submit')}
        </button>
      ) : null}

      {showApprove ? (
        <button
          type="button"
          onClick={() => onApprove(expense.id)}
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

      {showMarkPaid ? (
        <button
          type="button"
          onClick={() => onMarkPaid(expense.id)}
          className="inline-flex items-center gap-1 rounded-full border border-blue-200 px-3 py-1.5 text-xs font-semibold text-blue-600 transition hover:bg-blue-50"
        >
          <Wallet2 size={14} />
          {t('approvals:workflowActions.markPaid')}
        </button>
      ) : null}

      <RejectReasonModal
        open={rejecting}
        submitting={submittingReject}
        onCancel={() => setRejecting(false)}
        onConfirm={handleRejectConfirm}
      />
    </div>
  )
}
