import { useState } from 'react'
import { useTranslation } from 'react-i18next'

export default function RejectReasonModal({
  open,
  onCancel,
  onConfirm,
  submitting,
  title,
  description,
  rejectionTypes,
}) {
  const { t } = useTranslation(['approvals', 'common'])

  // Default (expense) rejection types — callers for other workflows (e.g.
  // donations) pass their own `rejectionTypes` prop with matching {value,
  // label, description} shape so this one modal stays the single reject-reason
  // UI for every approval workflow in the app, not a copy per entity.
  const defaultRejectionTypes = [
    {
      value: 'invoice',
      label: t('approvals:rejectionTypes.invoiceIssue.label'),
      description: t('approvals:rejectionTypes.invoiceIssue.description'),
    },
    {
      value: 'details',
      label: t('approvals:rejectionTypes.expenseDetailsIssue.label'),
      description: t('approvals:rejectionTypes.expenseDetailsIssue.description'),
    },
  ]
  const resolvedRejectionTypes = rejectionTypes ?? defaultRejectionTypes
  const resolvedTitle = title ?? t('approvals:rejectModal.rejectExpenseTitle')
  const resolvedDescription = description ?? t('approvals:rejectModal.rejectExpenseDescription')

  const [reason, setReason] = useState('')
  const [rejectionType, setRejectionType] = useState(resolvedRejectionTypes[0]?.value)

  if (!open) return null

  function handleSubmit(event) {
    event.preventDefault()
    if (!reason.trim()) return
    onConfirm(reason.trim(), rejectionType)
  }

  function handleCancel() {
    setReason('')
    setRejectionType(resolvedRejectionTypes[0]?.value)
    onCancel()
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
      <div className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
        <h3 className="text-lg font-semibold text-slate-900">{resolvedTitle}</h3>
        <p className="mt-1 text-sm text-slate-500">{resolvedDescription}</p>

        <form className="mt-4 space-y-4" onSubmit={handleSubmit}>
          <div className="space-y-2">
            <p className="text-xs font-semibold uppercase tracking-[0.1em] text-slate-400">{t('approvals:rejectModal.rejectionTypeLabel')}</p>
            {resolvedRejectionTypes.map((type) => (
              <label
                key={type.value}
                className={`flex cursor-pointer items-start gap-3 rounded-2xl border px-4 py-3 text-sm transition ${
                  rejectionType === type.value
                    ? 'border-rose-300 bg-rose-50'
                    : 'border-[#dfe5ff] bg-white hover:bg-slate-50'
                }`}
              >
                <input
                  type="radio"
                  name="rejection_type"
                  value={type.value}
                  checked={rejectionType === type.value}
                  onChange={(event) => setRejectionType(event.target.value)}
                  className="mt-0.5"
                />
                <span>
                  <span className="block font-semibold text-slate-800">{type.label}</span>
                  <span className="block text-xs text-slate-500">{type.description}</span>
                </span>
              </label>
            ))}
          </div>

          <textarea
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder={t('approvals:rejectModal.reasonPlaceholder')}
            autoFocus
            className="min-h-[96px] w-full rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm outline-none"
          />

          <div className="flex gap-3">
            <button
              type="submit"
              disabled={submitting || !reason.trim()}
              className="flex-1 rounded-2xl bg-rose-600 px-4 py-3 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60"
            >
              {submitting ? t('approvals:rejectModal.rejecting') : t('common:actions.reject')}
            </button>
            <button
              type="button"
              onClick={handleCancel}
              className="rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            >
              {t('common:actions.cancel')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
