import { STATUS_STYLES } from '../../lib/expenseStatus'
import { useStatusLabel } from '../../hooks/useStatusLabel'

export default function ExpenseStatusBadge({ status }) {
  const statusLabel = useStatusLabel()

  return (
    <span
      className={`inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold leading-none ${STATUS_STYLES[status] ?? 'bg-slate-100 text-slate-600'}`}
    >
      {statusLabel(status)}
    </span>
  )
}
