import { Clock3, Repeat, ShieldPlus, Trash2, UserMinus, UsersRound } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  assignProjectMember,
  getCommitteeHistory,
  getProjectMembers,
  removeProjectMember,
  replaceProjectMember,
  resignProjectMember,
} from '../api/projectMembers.api'
import { getMembers } from '../api/members.api'
import { getProjects } from '../api/projects.api'
import PresidentLayout from '../components/layout/PresidentLayout'
import { useAuth } from '../context/auth-context'
import { canAssignCommittee, canRemoveCommitteeMember, isProjectLocked } from '../lib/roles'
import { committeeRoleLabel, formatDate } from '../components/committee/committeeUtils'
import { phaseLabel } from '../lib/projectPhase'
import { stageLabel } from '../lib/projectLifecycle'

const initialAssignment = {
  member_id: '',
  role: '',
}

const initialReplaceForm = {
  new_member_id: '',
  reason: '',
}

// Each history row spans one membership stint (assigned_at -> removed_at).
// A timeline needs the start and, once closed, the end as separate events —
// this expands rows into a flat, newest-first list of events.
function buildTimeline(history) {
  const events = []

  history.forEach((row) => {
    events.push({
      key: `${row.id}-assigned`,
      date: row.assigned_at,
      member: row.member,
      action: 'assigned',
      actor: row.assigned_by,
      reason: null,
    })

    if (row.removed_at) {
      events.push({
        key: `${row.id}-${row.action}`,
        date: row.removed_at,
        member: row.member,
        action: row.action,
        actor: row.removed_by,
        reason: row.reason,
      })
    }
  })

  return events.sort((a, b) => new Date(b.date) - new Date(a.date))
}

export default function CommitteesPage() {
  const { t } = useTranslation('association')
  const { user } = useAuth()

  const ACTION_TONES = {
    assigned: 'bg-emerald-50 text-emerald-700',
    replaced: 'bg-amber-50 text-amber-700',
    resigned: 'bg-slate-100 text-slate-600',
    removed: 'bg-rose-50 text-rose-700',
    dissolved: 'bg-rose-50 text-rose-700',
  }

  const [projects, setProjects] = useState([])
  const [members, setMembers] = useState([])
  const [selectedProjectId, setSelectedProjectId] = useState('')
  const [committeeMembers, setCommitteeMembers] = useState([])
  const [history, setHistory] = useState([])
  const [subTab, setSubTab] = useState('active')
  const [assignment, setAssignment] = useState(initialAssignment)
  const [loadingCommittee, setLoadingCommittee] = useState(false)
  const [loadingHistory, setLoadingHistory] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [replacingMemberId, setReplacingMemberId] = useState(null)
  const [replaceForm, setReplaceForm] = useState(initialReplaceForm)
  const [savingReplace, setSavingReplace] = useState(false)

  useEffect(() => {
    loadInitialData()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    setReplacingMemberId(null)
    setReplaceForm(initialReplaceForm)

    if (selectedProjectId) {
      loadCommitteeMembers(selectedProjectId)
      loadHistory(selectedProjectId)
    } else {
      setCommitteeMembers([])
      setHistory([])
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedProjectId])

  async function loadInitialData() {
    const [projectsData, membersData] = await Promise.all([getProjects(), getMembers()])
    setProjects(projectsData)
    setMembers(membersData)

    if (projectsData.length > 0) {
      setSelectedProjectId(String(projectsData[0].id))
    }
  }

  async function loadCommitteeMembers(projectId) {
    setLoadingCommittee(true)
    setErrorMessage('')

    try {
      const data = await getProjectMembers(projectId)
      setCommitteeMembers(data)
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeesPage.loadCommitteeError'),
      )
    } finally {
      setLoadingCommittee(false)
    }
  }

  async function loadHistory(projectId) {
    setLoadingHistory(true)

    try {
      const data = await getCommitteeHistory(projectId)
      setHistory(data)
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeesPage.loadHistoryError'),
      )
    } finally {
      setLoadingHistory(false)
    }
  }

  async function refreshCommittee() {
    await Promise.all([loadCommitteeMembers(selectedProjectId), loadHistory(selectedProjectId)])
  }

  function handleAssignmentChange(event) {
    const { name, value } = event.target
    setAssignment((current) => ({ ...current, [name]: value }))
  }

  async function handleAssign(event) {
    event.preventDefault()
    if (!selectedProjectId) return

    setErrorMessage('')

    try {
      await assignProjectMember(selectedProjectId, {
        member_id: Number(assignment.member_id),
        role: assignment.role || null,
        committee_role: assignment.role || null,
      })
      setAssignment(initialAssignment)
      await refreshCommittee()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeesPage.assignError'),
      )
    }
  }

  async function handleRemove(memberId) {
    if (!selectedProjectId) return
    if (!window.confirm(t('committeesPage.removeConfirm'))) return

    const reason = window.prompt(t('committeesPage.removeReasonPrompt')) ?? ''
    setErrorMessage('')

    try {
      await removeProjectMember(selectedProjectId, memberId, reason || null)
      await refreshCommittee()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeesPage.removeError'),
      )
    }
  }

  async function handleResign(memberId) {
    if (!selectedProjectId) return
    if (!window.confirm(t('committeesPage.resignConfirm'))) return

    const reason = window.prompt(t('committeesPage.resignReasonPrompt')) ?? ''
    setErrorMessage('')

    try {
      await resignProjectMember(selectedProjectId, memberId, reason || null)
      await refreshCommittee()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeesPage.resignError'),
      )
    }
  }

  function beginReplace(memberId) {
    setReplacingMemberId(memberId)
    setReplaceForm(initialReplaceForm)
  }

  function updateReplaceForm(event) {
    const { name, value } = event.target
    setReplaceForm((current) => ({ ...current, [name]: value }))
  }

  async function handleReplaceSubmit(event, oldMemberId) {
    event.preventDefault()
    if (!selectedProjectId || !replaceForm.new_member_id) return

    setSavingReplace(true)
    setErrorMessage('')

    try {
      await replaceProjectMember(selectedProjectId, oldMemberId, {
        new_member_id: Number(replaceForm.new_member_id),
        reason: replaceForm.reason || null,
      })
      setReplacingMemberId(null)
      setReplaceForm(initialReplaceForm)
      await refreshCommittee()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeesPage.replaceError'),
      )
    } finally {
      setSavingReplace(false)
    }
  }

  const availableMembers = useMemo(() => {
    const assignedIds = new Set(committeeMembers.map((member) => member.id))
    return members.filter(
      (member) =>
        !assignedIds.has(member.id) && (member.is_bureau_member || member.has_verified_subscription),
    )
  }, [committeeMembers, members])

  const selectedProject = projects.find((project) => String(project.id) === selectedProjectId)
  const locked = isProjectLocked(selectedProject)
  const timeline = useMemo(() => buildTimeline(history), [history])

  return (
    <PresidentLayout
      title={t('committeesPage.title')}
      description={t('committeesPage.description')}
      breadcrumbs={['Committees']}
    >
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[420px_minmax(0,1fr)]">
        <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="mb-5 flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
              <UsersRound size={20} />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">{t('committeesPage.assignmentTitle')}</h3>
              <p className="text-sm text-slate-500">{t('committeesPage.assignmentSubtitle')}</p>
            </div>
          </div>

          <div className="space-y-4">
            <div>
              <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                {t('committeesPage.project')}
              </label>
              <select
                value={selectedProjectId}
                onChange={(event) => setSelectedProjectId(event.target.value)}
                className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
              >
                <option value="">{t('committeesPage.selectProject')}</option>
                {projects.map((project) => (
                  <option key={project.id} value={project.id}>
                    {project.name}
                  </option>
                ))}
              </select>
            </div>

            {locked ? (
              <p className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600">
                {t('committeesPage.lockedNotice', { status: stageLabel(selectedProject.status) })}
              </p>
            ) : null}

            {canAssignCommittee(user) && !locked ? (
            <form className="space-y-4" onSubmit={handleAssign}>
              <div>
                <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                  {t('committeesPage.member')}
                </label>
                <select
                  name="member_id"
                  value={assignment.member_id}
                  onChange={handleAssignmentChange}
                  className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
                >
                  <option value="">{t('committeesPage.selectMember')}</option>
                  {availableMembers.map((member) => (
                    <option key={member.id} value={member.id}>
                      {member.user.name}
                    </option>
                  ))}
                </select>
              </div>

              <div>
                <label className="mb-2 block text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                  {t('committeesPage.committeeRole')}
                </label>
                <select
                  name="role"
                  value={assignment.role}
                  onChange={handleAssignmentChange}
                  className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
                >
                  <option value="">{t('committeesPage.selectCommitteeRole')}</option>
                  <option value="leader">{t('committeesPage.roleLeader')}</option>
                  <option value="treasurer">{t('committeesPage.roleTreasurer')}</option>
                  <option value="secretary">{t('committeesPage.roleSecretary')}</option>
                  <option value="member">{t('committeesPage.roleMember')}</option>
                </select>
              </div>

              {errorMessage ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                  {errorMessage}
                </div>
              ) : null}

              <button
                type="submit"
                className="flex w-full items-center justify-center gap-2 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white"
              >
                <ShieldPlus size={16} />
                {t('committeesPage.assignMember')}
              </button>
            </form>
            ) : (
              errorMessage ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                  {errorMessage}
                </div>
              ) : null
            )}
          </div>
        </section>

        <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="border-b border-[#eef2ff] px-6 py-5">
            <h3 className="text-lg font-semibold text-slate-900">
              {selectedProject ? t('committeesPage.committeeSuffix', { name: selectedProject.name }) : t('committeesPage.committeeMembers')}
            </h3>
            <p className="text-sm text-slate-500">
              {selectedProject
                ? t('committeesPage.projectStatusLine', {
                    status: stageLabel(selectedProject.status),
                    phase: phaseLabel(selectedProject.phase),
                    progress: selectedProject.progress_percentage,
                  })
                : t('committeesPage.selectProjectPrompt')}
            </p>
          </div>

          <div className="flex flex-wrap gap-2 border-b border-[#eef2ff] px-6 py-4">
            <button
              type="button"
              onClick={() => setSubTab('active')}
              className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition ${
                subTab === 'active'
                  ? 'bg-[#e8efff] text-blue-700'
                  : 'bg-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-900'
              }`}
            >
              <UsersRound size={16} />
              {t('committeesPage.activeMembers')}
            </button>
            <button
              type="button"
              onClick={() => setSubTab('history')}
              className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition ${
                subTab === 'history'
                  ? 'bg-[#e8efff] text-blue-700'
                  : 'bg-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-900'
              }`}
            >
              <Clock3 size={16} />
              {t('committeesPage.committeeHistory')}
            </button>
          </div>

          {subTab === 'active' ? (
            <div className="divide-y divide-[#eef2ff]">
              {loadingCommittee ? (
                <div className="space-y-3 px-6 py-5">
                  <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
                  <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
                </div>
              ) : committeeMembers.length > 0 ? (
                committeeMembers.map((member) => (
                  <div key={member.id} className="px-6 py-4">
                    <div className="flex items-center justify-between gap-4">
                      <div>
                        <p className="font-semibold text-slate-800">{member.user?.name ?? t('committeesPage.unknownMember')}</p>
                        <p className="text-sm text-slate-500">{member.user?.email ?? t('committeesPage.noEmail')}</p>
                        <p className="text-xs text-slate-400">
                          {t('committeesPage.committeeRoleLine', { role: committeeRoleLabel(member.pivot?.committee_role || member.pivot?.role) })}
                        </p>
                      </div>

                      <div className="flex shrink-0 items-center gap-2">
                        {canAssignCommittee(user) && !locked ? (
                          <button
                            type="button"
                            onClick={() => beginReplace(member.id)}
                            className="inline-flex items-center gap-1 rounded-xl border border-[#dfe5ff] px-3 py-2 text-xs font-semibold text-slate-600 transition hover:bg-slate-50"
                          >
                            <Repeat size={14} />
                            {t('committeesPage.replace')}
                          </button>
                        ) : null}

                        {member.user?.id === user?.id && !locked ? (
                          <button
                            type="button"
                            onClick={() => handleResign(member.id)}
                            className="inline-flex items-center gap-1 rounded-xl border border-amber-200 px-3 py-2 text-xs font-semibold text-amber-600 transition hover:bg-amber-50"
                          >
                            <UserMinus size={14} />
                            {t('committeesPage.resign')}
                          </button>
                        ) : null}

                        {canRemoveCommitteeMember(user) && !locked ? (
                          <button
                            type="button"
                            onClick={() => handleRemove(member.id)}
                            className="rounded-xl border border-rose-200 px-3 py-2 text-rose-500 transition hover:bg-rose-50"
                          >
                            <Trash2 size={16} />
                          </button>
                        ) : null}
                      </div>
                    </div>

                    {replacingMemberId === member.id ? (
                      <form
                        className="mt-3 flex flex-wrap items-start gap-2 rounded-2xl bg-[#f7f9ff] p-3"
                        onSubmit={(event) => handleReplaceSubmit(event, member.id)}
                      >
                        <select
                          name="new_member_id"
                          value={replaceForm.new_member_id}
                          onChange={updateReplaceForm}
                          className="h-10 min-w-[180px] flex-1 rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm outline-none"
                        >
                          <option value="">{t('committeesPage.selectReplacement')}</option>
                          {availableMembers.map((candidate) => (
                            <option key={candidate.id} value={candidate.id}>
                              {candidate.user.name}
                            </option>
                          ))}
                        </select>

                        <input
                          type="text"
                          name="reason"
                          value={replaceForm.reason}
                          onChange={updateReplaceForm}
                          placeholder={t('committeesPage.reasonPlaceholder')}
                          className="h-10 min-w-[160px] flex-1 rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm outline-none"
                        />

                        <button
                          type="submit"
                          disabled={savingReplace || !replaceForm.new_member_id}
                          className="h-10 shrink-0 rounded-xl bg-blue-600 px-4 text-sm font-semibold text-white disabled:opacity-60"
                        >
                          {savingReplace ? t('committeesPage.saving') : t('committeesPage.confirm')}
                        </button>

                        <button
                          type="button"
                          onClick={() => setReplacingMemberId(null)}
                          className="h-10 shrink-0 rounded-xl border border-[#dfe5ff] px-4 text-sm font-semibold text-slate-500 transition hover:bg-white"
                        >
                          {t('committeesPage.cancel')}
                        </button>
                      </form>
                    ) : null}
                  </div>
                ))
              ) : (
                <div className="px-6 py-5 text-sm text-slate-500">
                  {locked
                    ? t('committeesPage.dissolvedNotice')
                    : t('committeesPage.emptyCommittee')}
                </div>
              )}
            </div>
          ) : (
            <div className="divide-y divide-[#eef2ff]">
              {loadingHistory ? (
                <div className="space-y-3 px-6 py-5">
                  <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
                  <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
                </div>
              ) : timeline.length > 0 ? (
                timeline.map((event) => (
                  <div key={event.key} className="flex items-start justify-between gap-4 px-6 py-4">
                    <div>
                      <p className="font-semibold text-slate-800">{event.member?.user?.name ?? t('committeesPage.unknownMember')}</p>
                      <p className="text-sm text-slate-500">{formatDate(event.date)}</p>
                      {event.reason ? (
                        <p className="mt-1 text-xs text-slate-400">{t('committeesPage.reasonLabel', { reason: event.reason })}</p>
                      ) : null}
                    </div>

                    <div className="flex shrink-0 flex-col items-end gap-1">
                      <span
                        className={`rounded-full px-3 py-1 text-xs font-semibold ${
                          ACTION_TONES[event.action] ?? 'bg-slate-100 text-slate-600'
                        }`}
                      >
                        {t(`committeesPage.actions.${event.action}`, { defaultValue: event.action })}
                      </span>
                      <span className="text-xs text-slate-400">
                        {t('committeesPage.by', { name: event.actor?.name ?? t('committeesPage.unknown') })}
                      </span>
                    </div>
                  </div>
                ))
              ) : (
                <div className="px-6 py-5 text-sm text-slate-500">
                  {t('committeesPage.emptyHistory')}
                </div>
              )}
            </div>
          )}
        </section>
      </div>
    </PresidentLayout>
  )
}
