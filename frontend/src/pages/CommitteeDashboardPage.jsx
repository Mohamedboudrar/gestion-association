import {
  BadgeDollarSign,
  FolderKanban,
  FolderOpen,
  HandCoins,
  ReceiptText,
  UsersRound,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { getExpenses } from '../api/expenses.api'
import { getProjectAllocations } from '../api/projectAllocations.api'
import PresidentLayout from '../components/layout/PresidentLayout'
import useCommitteeProjects from '../hooks/useCommitteeProjects'
import { useAuth } from '../context/auth-context'
import {
  formatCurrency,
  formatDate,
  statusBadgeClass,
} from '../components/committee/committeeUtils'
import { phaseLabel } from '../lib/projectPhase'
import { stageLabel } from '../lib/projectLifecycle'
import { isFinanciallyCountedExpense } from '../lib/expenseStatus'

function CommitteeStatCard({ title, value, icon: Icon, note, accent = 'text-slate-900' }) {
  return (
    <div className="rounded-3xl border border-[#dfe5ff] bg-white p-5 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
      <div className="mb-4 flex h-11 w-11 items-center justify-center rounded-2xl bg-[#f7f9ff] text-blue-600">
        <Icon size={20} />
      </div>
      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{title}</p>
      <h3 className={`mt-2 text-2xl font-semibold ${accent}`}>{value}</h3>
      <p className="mt-2 text-sm text-slate-500">{note}</p>
    </div>
  )
}

export default function CommitteeDashboardPage() {
  const { t } = useTranslation('dashboard')
  const { user } = useAuth()
  const { projects, projectMembers, loading, error } = useCommitteeProjects(user)
  const [expenses, setExpenses] = useState([])
  const [expensesError, setExpensesError] = useState('')
  const [allocationTotals, setAllocationTotals] = useState({})
  const [allocationsLoading, setAllocationsLoading] = useState(true)

  const activeProjects = projects.filter((project) => String(project.status).toLowerCase() === 'active')
  const assignedProjectIds = useMemo(
    () => new Set(projects.map((project) => String(project.id))),
    [projects],
  )
  // Collected totals come straight from each project's own ProjectResource
  // figure (donations + allocations, backend-computed — same "Collected"
  // value CommitteeProjectPage's Overview tab shows) rather than summing
  // itemized donation records: a plain committee member (not leader/
  // treasurer) can't list individual donations/donor names, only a
  // project's aggregate totals — see DonationPolicy.
  const donationTotal = projects.reduce((sum, project) => sum + Number(project.collected ?? 0), 0)
  const expenseTotal = expenses.reduce((sum, expense) => {
    if (!assignedProjectIds.has(String(expense.project?.id)) || !isFinanciallyCountedExpense(expense)) {
      return sum
    }

    return sum + Number(expense.amount ?? 0)
  }, 0)

  useEffect(() => {
    let cancelled = false

    async function loadExpenses() {
      setExpensesError('')

      try {
        const data = await getExpenses()

        if (!cancelled) {
          setExpenses(data ?? [])
        }
      } catch (loadError) {
        if (!cancelled) {
          setExpenses([])
          setExpensesError(
            loadError.response?.data?.message ?? t('committee.expensesLoadError'),
          )
        }
      }
    }

    loadExpenses()

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    let cancelled = false

    async function loadAllocations() {
      setAllocationsLoading(true)

      const entries = await Promise.all(
        projects.map(async (project) => {
          try {
            const projectAllocations = await getProjectAllocations(project.id)
            const total = (projectAllocations ?? []).reduce(
              (sum, allocation) => sum + Number(allocation.amount ?? 0),
              0,
            )
            return [project.id, total]
          } catch {
            return [project.id, null]
          }
        }),
      )

      if (!cancelled) {
        setAllocationTotals(Object.fromEntries(entries))
        setAllocationsLoading(false)
      }
    }

    if (projects.length) {
      loadAllocations()
    } else {
      setAllocationTotals({})
      setAllocationsLoading(false)
    }

    return () => {
      cancelled = true
    }
  }, [projects])

  const stats = [
    {
      title: t('committee.assignedProjects'),
      value: projects.length,
      icon: FolderKanban,
      note: t('committee.assignedProjectsNote'),
    },
    {
      title: t('committee.activeProjects'),
      value: activeProjects.length,
      icon: FolderOpen,
      note: t('committee.activeProjectsNote'),
    },
    {
      title: t('committee.donations'),
      value: formatCurrency(donationTotal),
      icon: HandCoins,
      accent: 'text-blue-700',
      note: t('committee.donationsNote'),
    },
    {
      title: t('committee.expenses'),
      value: formatCurrency(expenseTotal),
      icon: ReceiptText,
      accent: 'text-rose-600',
      note: expensesError || t('committee.expensesNote'),
    },
  ]

  return (
    <PresidentLayout
      title={t('committee.title')}
      description={t('committee.description')}
      breadcrumbs={['Dashboard']}
    >
      {error ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {error}
        </div>
      ) : null}

      <section className="grid grid-cols-1 gap-4 md:grid-cols-2 2xl:grid-cols-4">
        {stats.map((card) => (
          <CommitteeStatCard key={card.title} {...card} />
        ))}
      </section>

      <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
        <div className="flex flex-col gap-3 border-b border-[#eef2ff] px-6 py-5 md:flex-row md:items-center md:justify-between">
          <div>
            <h3 className="text-lg font-semibold text-slate-900">{t('committee.assignedProjectsTable')}</h3>
            <p className="text-sm text-slate-500">{t('committee.assignedProjectsTableNote')}</p>
          </div>
          <div className="flex items-center gap-2 rounded-full bg-[#f7f9ff] px-4 py-2 text-xs font-semibold text-slate-500">
            <UsersRound size={14} />
            {t('committee.assignedCount', { count: projects.length })}
          </div>
        </div>

        {loading ? (
          <div className="px-6 py-8 text-sm text-slate-500">{t('committee.loading')}</div>
        ) : projects.length ? (
          <div className="overflow-x-auto">
            <table className="min-w-full">
              <thead className="bg-[#fbfcff] text-left">
                <tr className="text-xs uppercase tracking-[0.12em] text-slate-400">
                  <th className="px-6 py-4 font-semibold">{t('committee.table.project')}</th>
                  <th className="px-4 py-4 font-semibold">{t('committee.table.status')}</th>
                  <th className="px-4 py-4 font-semibold">{t('committee.table.budget')}</th>
                  <th className="px-4 py-4 font-semibold">{t('committee.table.allocatedToDate')}</th>
                  <th className="px-4 py-4 font-semibold">{t('committee.table.progress')}</th>
                  <th className="px-4 py-4 font-semibold">{t('committee.table.committee')}</th>
                  <th className="px-6 py-4 font-semibold text-right">{t('committee.table.action')}</th>
                </tr>
              </thead>
              <tbody>
                {projects.map((project) => (
                  <tr key={project.id} className="border-t border-[#eef2ff] text-sm text-slate-600">
                    <td className="px-6 py-5">
                      <p className="font-semibold text-slate-900">{project.name}</p>
                      <p className="mt-1 text-xs text-slate-400">
                        {project.description || t('committee.noDescription')} • {formatDate(project.start_date)}
                      </p>
                    </td>
                    <td className="px-4 py-5">
                      <span className={statusBadgeClass(project.status)}>
                        {project.status ? stageLabel(project.status) : t('committee.unknown')}
                      </span>
                    </td>
                    <td className="px-4 py-5 font-semibold text-slate-800">{formatCurrency(project.budget)}</td>
                    <td className="px-4 py-5 font-semibold text-emerald-600">
                      {allocationsLoading
                        ? t('committee.loadingEllipsis')
                        : allocationTotals[project.id] != null
                          ? formatCurrency(allocationTotals[project.id])
                          : t('committee.unavailable')}
                    </td>
                    <td className="px-4 py-5">
                      <div className="flex items-center gap-3">
                        <div className="h-2 w-28 overflow-hidden rounded-full bg-slate-100">
                          <div
                            className="h-full rounded-full bg-gradient-to-r from-blue-500 to-indigo-400"
                            style={{ width: `${Math.max(0, Math.min(100, Number(project.progress_percentage ?? 0)))}%` }}
                          />
                        </div>
                        <span className="text-xs font-semibold text-slate-400">
                          {phaseLabel(project.phase)} • {project.progress_percentage ?? 0}%
                        </span>
                      </div>
                    </td>
                    <td className="px-4 py-5 text-slate-500">
                      {t('committee.membersCount', { count: (projectMembers[project.id] ?? []).length })}
                    </td>
                    <td className="px-6 py-5 text-right">
                      <Link
                        to={`/projects/${project.id}`}
                        className="inline-flex items-center gap-2 rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                      >
                        <BadgeDollarSign size={16} />
                        {t('committee.openProject')}
                      </Link>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="px-6 py-10 text-sm text-slate-500">
            {t('committee.noProjectsAssigned')}
          </div>
        )}
      </section>
    </PresidentLayout>
  )
}
