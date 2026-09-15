import { FolderKanban } from 'lucide-react'
import { Link } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { committeeLeader, isProjectDelayed } from '../../lib/dashboardMetrics'
import { phaseLabel } from '../../lib/projectPhase'
import { stageLabel } from '../../lib/projectLifecycle'
import { formatCurrency, formatDate, statusBadgeClass } from '../committee/committeeUtils'

function healthTone(project) {
  if (isProjectDelayed(project) || Number(project.remaining ?? 0) < 0) return 'bg-rose-500'
  if (Number(project.progress_percentage ?? 0) >= 90) return 'bg-amber-400'
  return 'bg-emerald-500'
}

function needsApproval(project, lastActivityAt, t) {
  const reasons = []
  if ((project.members_count ?? 0) === 0) reasons.push(t('projectHealth.noCommittee'))
  if (!lastActivityAt) reasons.push(t('projectHealth.noActivity'))
  return reasons
}

export default function ProjectHealthTable({ projects, activityByProject }) {
  const { t } = useTranslation('dashboard')

  return (
    <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-sm xl:col-span-2">
      <div className="flex items-center justify-between border-b border-[#eef2ff] px-6 py-5">
        <div>
          <h2 className="text-lg font-semibold text-slate-900">{t('projectHealth.title')}</h2>
          <p className="text-sm text-slate-500">{t('projectHealth.projectCount', { count: projects.length })}</p>
        </div>
        <Link to="/projects" className="text-sm font-semibold text-blue-600 transition hover:text-blue-700">
          {t('projectHealth.viewAll')}
        </Link>
      </div>

      {projects.length === 0 ? (
        <div className="flex flex-col items-center gap-2 px-6 py-14 text-center">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
            <FolderKanban size={20} />
          </div>
          <p className="text-sm font-semibold text-slate-700">{t('projectHealth.empty')}</p>
          <p className="text-xs text-slate-400">{t('projectHealth.emptyNote')}</p>
        </div>
      ) : (
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-[#fbfcff] text-left text-xs uppercase tracking-[0.1em] text-slate-400">
            <tr>
              <th className="px-6 py-3 font-semibold">{t('projectHealth.table.project')}</th>
              <th className="px-4 py-3 font-semibold">{t('projectHealth.table.leader')}</th>
              <th className="px-4 py-3 font-semibold">{t('projectHealth.table.budget')}</th>
              <th className="px-4 py-3 font-semibold">{t('projectHealth.table.collected')}</th>
              <th className="px-4 py-3 font-semibold">{t('projectHealth.table.spent')}</th>
              <th className="px-4 py-3 font-semibold">{t('projectHealth.table.remaining')}</th>
              <th className="px-4 py-3 font-semibold">{t('projectHealth.table.progress')}</th>
              <th className="px-4 py-3 font-semibold">{t('projectHealth.table.status')}</th>
              <th className="px-4 py-3 font-semibold">{t('projectHealth.table.lastActivity')}</th>
            </tr>
          </thead>
          <tbody>
            {projects.map((project) => {
              const leader = committeeLeader(project)
              const lastActivityAt = activityByProject?.get(project.id) ?? null
              const flags = needsApproval(project, lastActivityAt, t)

              return (
                <tr key={project.id} className="border-t border-[#eef2ff] text-slate-600 transition hover:bg-[#fbfcff]">
                  <td className="px-6 py-4">
                    <div className="flex items-center gap-2">
                      <span className={`h-2 w-2 shrink-0 rounded-full ${healthTone(project)}`} />
                      <span className="font-semibold text-slate-800">{project.name}</span>
                    </div>
                    {flags.length ? (
                      <p className="mt-1 text-xs text-amber-600">{flags.join(' • ')}</p>
                    ) : null}
                  </td>
                  <td className="px-4 py-4">{leader?.name ?? t('projectHealth.unassigned')}</td>
                  <td className="px-4 py-4 font-medium tabular-nums text-slate-800">
                    {formatCurrency(project.budget)}
                  </td>
                  <td className="px-4 py-4 tabular-nums text-blue-700">{formatCurrency(project.collected)}</td>
                  <td className="px-4 py-4 tabular-nums text-rose-600">{formatCurrency(project.expenses)}</td>
                  <td className="px-4 py-4 tabular-nums text-emerald-600">{formatCurrency(project.remaining)}</td>
                  <td className="px-4 py-4">
                    <div className="flex items-center gap-2">
                      <div className="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100">
                        <div
                          className="h-full rounded-full bg-blue-500"
                          style={{ width: `${Math.max(0, Math.min(100, project.progress_percentage ?? 0))}%` }}
                        />
                      </div>
                      <span className="text-xs text-slate-400">
                        {phaseLabel(project.phase)} • {project.progress_percentage ?? 0}%
                      </span>
                    </div>
                  </td>
                  <td className="px-4 py-4">
                    <span className={statusBadgeClass(project.status)}>
                      {stageLabel(project.status)}
                    </span>
                  </td>
                  <td className="px-4 py-4 text-xs text-slate-400">
                    {lastActivityAt ? formatDate(lastActivityAt) : '—'}
                  </td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>
      )}
    </section>
  )
}
