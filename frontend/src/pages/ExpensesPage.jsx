import { ChevronDown, ChevronUp } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import ExpenseInvoicePreview from '../components/committee/ExpenseInvoicePreview'
import ExpenseStatusBadge from '../components/committee/ExpenseStatusBadge'
import ExpenseWorkflowActions from '../components/committee/ExpenseWorkflowActions'
import { formatCurrency, formatDate } from '../components/committee/committeeUtils'
import { useAuth } from '../context/auth-context'
import { approveExpense, getExpenses, markExpensePaid, rejectExpense } from '../api/expenses.api'
import { STATUS_STYLES } from '../lib/expenseStatus'
import { useStatusLabel } from '../hooks/useStatusLabel'

const NAVIGATION_KEYS = ['ArrowUp', 'ArrowDown']
const TEXT_INPUT_TAGS = ['INPUT', 'TEXTAREA', 'SELECT']

export default function ExpensesPage({ mode = 'all' }) {
  const { t } = useTranslation('finance')
  const statusLabel = useStatusLabel()
  const { user } = useAuth()
  const [expenses, setExpenses] = useState([])
  const [loading, setLoading] = useState(true)
  const [errorMessage, setErrorMessage] = useState('')
  const [selectedExpenseId, setSelectedExpenseId] = useState(null)
  // The dedicated /expenses/pending route opens pre-filtered to Pending (its
  // whole purpose) but the filter itself is otherwise fully independent —
  // switching it away from Pending doesn't navigate anywhere.
  const [statusFilter, setStatusFilter] = useState(mode === 'pending' ? 'pending' : 'all')
  const [projectFilter, setProjectFilter] = useState('all')

  const STATUS_FILTERS = [
    { value: 'all', label: t('expensesPage.statusFilters.all') },
    { value: 'draft', label: statusLabel('draft') },
    { value: 'pending', label: statusLabel('pending') },
    { value: 'approved', label: statusLabel('approved') },
    { value: 'rejected', label: statusLabel('rejected') },
    { value: 'paid', label: statusLabel('paid') },
  ]

  useEffect(() => {
    loadExpenses()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function loadExpenses() {
    setLoading(true)
    setErrorMessage('')

    try {
      const data = await getExpenses()
      setExpenses(data)
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('expensesPage.loadError'))
    } finally {
      setLoading(false)
    }
  }

  async function handleApprove(expenseId) {
    setErrorMessage('')

    try {
      await approveExpense(expenseId)
      await loadExpenses()
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('expensesPage.approveError'))
    }
  }

  async function handleReject(expenseId, reason, rejectionType) {
    setErrorMessage('')

    try {
      await rejectExpense(expenseId, reason, rejectionType)
      await loadExpenses()
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('expensesPage.rejectError'))
      throw error
    }
  }

  async function handleMarkPaid(expenseId) {
    setErrorMessage('')

    try {
      await markExpensePaid(expenseId)
      await loadExpenses()
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('expensesPage.markPaidError'))
    }
  }

  // Scoped to the current project filter (if any) so the counts match what
  // clicking each tab will actually show — not a global count that could
  // disagree with the project filter already applied.
  const statusCounts = useMemo(() => {
    const scoped =
      projectFilter === 'all'
        ? expenses
        : expenses.filter((expense) => String(expense.project?.id) === projectFilter)

    const counts = { all: scoped.length, draft: 0, pending: 0, approved: 0, rejected: 0, paid: 0 }

    scoped.forEach((expense) => {
      if (counts[expense.status] !== undefined) {
        counts[expense.status] += 1
      }
    })

    return counts
  }, [expenses, projectFilter])

  // Derived from the already-loaded expenses (no extra request) — only lists
  // projects that actually have a visible expense, sorted for a stable menu.
  const projectOptions = useMemo(() => {
    const byId = new Map()

    expenses.forEach((expense) => {
      if (expense.project?.id != null && !byId.has(expense.project.id)) {
        byId.set(expense.project.id, expense.project)
      }
    })

    return Array.from(byId.values()).sort((a, b) => a.name.localeCompare(b.name))
  }, [expenses])

  const filteredExpenses = useMemo(() => {
    return expenses.filter((expense) => {
      if (statusFilter !== 'all' && expense.status !== statusFilter) return false
      if (projectFilter !== 'all' && String(expense.project?.id) !== projectFilter) return false
      return true
    })
  }, [statusFilter, projectFilter, expenses])

  const selectedProjectName = projectOptions.find((project) => String(project.id) === projectFilter)?.name

  function emptyStateMessage() {
    if (statusFilter === 'all' && projectFilter === 'all') {
      return t('expensesPage.emptyNoExpenses')
    }

    const statusText = statusFilter === 'all' ? '' : statusLabel(statusFilter).toLowerCase()
    const projectText = projectFilter === 'all' ? '' : (selectedProjectName ?? t('expensesPage.thisProject'))

    if (statusText && projectText) {
      return t('expensesPage.emptyStatusAndProject', { status: statusText, project: projectText })
    }
    if (statusText) {
      return t('expensesPage.emptyStatus', { status: statusText })
    }
    return t('expensesPage.emptyProject', { project: projectText })
  }

  // First expense auto-selected; keep the current selection across refetches
  // (e.g. after approve/reject) as long as it's still in the filtered list.
  useEffect(() => {
    if (filteredExpenses.length === 0) {
      setSelectedExpenseId(null)
      return
    }

    const stillVisible = filteredExpenses.some((expense) => expense.id === selectedExpenseId)

    if (!stillVisible) {
      setSelectedExpenseId(filteredExpenses[0].id)
    }
    // Only re-run when the list itself changes — selectedExpenseId is read, not depended on.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filteredExpenses])

  const selectedIndex = filteredExpenses.findIndex((expense) => expense.id === selectedExpenseId)
  const selectedExpense = selectedIndex >= 0 ? filteredExpenses[selectedIndex] : null

  function selectByOffset(offset) {
    if (filteredExpenses.length === 0) return

    const currentIndex = selectedIndex >= 0 ? selectedIndex : 0
    const nextIndex = Math.min(filteredExpenses.length - 1, Math.max(0, currentIndex + offset))
    setSelectedExpenseId(filteredExpenses[nextIndex].id)
  }

  useEffect(() => {
    function handleKeyDown(event) {
      if (!NAVIGATION_KEYS.includes(event.key)) return
      if (TEXT_INPUT_TAGS.includes(document.activeElement?.tagName) || document.activeElement?.isContentEditable) {
        return
      }

      event.preventDefault()
      selectByOffset(event.key === 'ArrowUp' ? -1 : 1)
    }

    window.addEventListener('keydown', handleKeyDown)
    return () => window.removeEventListener('keydown', handleKeyDown)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filteredExpenses, selectedIndex])

  return (
    <PresidentLayout
      title={mode === 'pending' ? t('expensesPage.pendingTitle') : t('expensesPage.title')}
      description={t('expensesPage.description')}
      breadcrumbs={['Expenses']}
    >
      {errorMessage ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {errorMessage}
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[2fr_3fr]">
        <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="flex items-center justify-between gap-3 border-b border-[#eef2ff] px-6 py-5">
            <div>
              <h3 className="text-lg font-semibold text-slate-900">
                {mode === 'pending' ? t('expensesPage.pendingApproval') : t('expensesPage.allExpenses')}
              </h3>
              <p className="text-sm text-slate-500">{t('expensesPage.recordCount', { count: filteredExpenses.length })}</p>
            </div>

            {filteredExpenses.length > 1 ? (
              <div className="flex shrink-0 items-center gap-1">
                <button
                  type="button"
                  onClick={() => selectByOffset(-1)}
                  disabled={selectedIndex <= 0}
                  className="rounded-full border border-[#dfe5ff] p-2 text-slate-500 transition hover:bg-slate-50 disabled:opacity-40"
                  aria-label={t('expensesPage.previousExpense')}
                >
                  <ChevronUp size={16} />
                </button>
                <button
                  type="button"
                  onClick={() => selectByOffset(1)}
                  disabled={selectedIndex < 0 || selectedIndex >= filteredExpenses.length - 1}
                  className="rounded-full border border-[#dfe5ff] p-2 text-slate-500 transition hover:bg-slate-50 disabled:opacity-40"
                  aria-label={t('expensesPage.nextExpense')}
                >
                  <ChevronDown size={16} />
                </button>
              </div>
            ) : null}
          </div>

          {projectOptions.length > 0 ? (
            <div className="border-b border-[#eef2ff] px-6 py-4">
              <label className="mb-1.5 block text-xs font-semibold uppercase tracking-[0.1em] text-slate-400">
                {t('expensesPage.project')}
              </label>
              <select
                value={projectFilter}
                onChange={(event) => setProjectFilter(event.target.value)}
                className="h-11 w-full max-w-xs rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none transition focus:border-blue-300"
              >
                <option value="all">{t('expensesPage.allProjects')}</option>
                {projectOptions.map((project) => (
                  <option key={project.id} value={String(project.id)}>
                    {project.name}
                  </option>
                ))}
              </select>
            </div>
          ) : null}

          <div className="flex flex-wrap gap-2 border-b border-[#eef2ff] px-6 py-4">
            {STATUS_FILTERS.map(({ value, label }) => (
              <button
                key={value}
                type="button"
                onClick={() => setStatusFilter(value)}
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition ${
                  value === 'all' ? 'bg-slate-100 text-slate-600' : STATUS_STYLES[value]
                } ${
                  statusFilter === value
                    ? 'ring-2 ring-offset-1 ring-blue-400'
                    : 'opacity-60 hover:opacity-100'
                }`}
              >
                {label}
                <span className="rounded-full bg-white/70 px-1.5 py-0.5 text-[10px]">
                  {statusCounts[value] ?? 0}
                </span>
              </button>
            ))}
          </div>

          <div className="max-h-[calc(100vh-320px)] divide-y divide-[#eef2ff] overflow-y-auto">
            {loading ? (
              <div className="space-y-3 px-6 py-8">
                <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
              </div>
            ) : filteredExpenses.length ? (
              filteredExpenses.map((expense) => (
                <div
                  key={expense.id}
                  onClick={() => setSelectedExpenseId(expense.id)}
                  className={`flex cursor-pointer flex-col gap-3 px-6 py-4 transition ${
                    expense.id === selectedExpenseId ? 'bg-[#eef2ff]' : 'hover:bg-[#fbfcff]'
                  }`}
                >
                  <div>
                    <div className="flex flex-wrap items-center gap-2">
                      <p className="font-semibold text-slate-800">{expense.supplier_name || t('expensesPage.unknownSupplier')}</p>
                      <ExpenseStatusBadge status={expense.status} />
                    </div>
                    <p className="text-sm text-slate-500">
                      {formatCurrency(expense.amount)} • {expense.project?.name ?? t('expensesPage.noProject')}
                    </p>
                    <p className="text-xs text-slate-400">
                      {formatDate(expense.expense_date)} • {t('expensesPage.submittedBy', { name: expense.created_by?.name ?? t('expensesPage.unknown') })}
                    </p>
                    {expense.status === 'rejected' && expense.rejection_reason ? (
                      <p className="mt-1 text-sm text-rose-600">{t('expensesPage.rejectedLabel', { reason: expense.rejection_reason })}</p>
                    ) : null}
                  </div>

                  <div className="mt-3" onClick={(event) => event.stopPropagation()}>
                    <ExpenseWorkflowActions
                      user={user}
                      expense={expense}
                      onApprove={handleApprove}
                      onReject={handleReject}
                      onMarkPaid={handleMarkPaid}
                    />
                  </div>
                </div>
              ))
            ) : (
              <div className="px-6 py-8 text-sm text-slate-500">{emptyStateMessage()}</div>
            )}
          </div>
        </section>

        <section className="min-h-[480px] rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)] lg:sticky lg:top-6 lg:self-start">
          <ExpenseInvoicePreview expense={selectedExpense} />
        </section>
      </div>
    </PresidentLayout>
  )
}
