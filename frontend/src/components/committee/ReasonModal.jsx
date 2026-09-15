import { useState } from 'react'
import { useTranslation } from 'react-i18next'

// A minimal "enter a reason and submit" modal — distinct from
// RejectReasonModal, which always shows a rejection-type radio group that
// doesn't apply here (e.g. requesting a project's deletion isn't rejecting
// anything, it's the first step of a new request).
export default function ReasonModal({
  open,
  onCancel,
  onConfirm,
  submitting,
  title,
  description,
  placeholder,
  confirmLabel,
  confirmClassName = 'bg-blue-600 hover:bg-blue-700',
}) {
  const { t } = useTranslation('association')
  const [reason, setReason] = useState('')

  if (!open) return null

  function handleSubmit(event) {
    event.preventDefault()
    if (!reason.trim()) return
    onConfirm(reason.trim())
  }

  function handleCancel() {
    setReason('')
    onCancel()
  }

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4">
      <div className="w-full max-w-md rounded-3xl bg-white p-6 shadow-2xl">
        <h3 className="text-lg font-semibold text-slate-900">{title}</h3>
        {description ? <p className="mt-1 text-sm text-slate-500">{description}</p> : null}

        <form className="mt-4 space-y-4" onSubmit={handleSubmit}>
          <textarea
            value={reason}
            onChange={(event) => setReason(event.target.value)}
            placeholder={placeholder ?? t('committeeProject.reasonModal.placeholder')}
            autoFocus
            className="min-h-[96px] w-full rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm outline-none"
          />

          <div className="flex gap-3">
            <button
              type="submit"
              disabled={submitting || !reason.trim()}
              className={`flex-1 rounded-2xl px-4 py-3 text-sm font-semibold text-white transition disabled:opacity-60 ${confirmClassName}`}
            >
              {submitting ? t('committeeProject.reasonModal.submitting') : (confirmLabel ?? t('committeeProject.reasonModal.confirmLabel'))}
            </button>
            <button
              type="button"
              onClick={handleCancel}
              className="rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            >
              {t('committeeProject.reasonModal.cancel')}
            </button>
          </div>
        </form>
      </div>
    </div>
  )
}
