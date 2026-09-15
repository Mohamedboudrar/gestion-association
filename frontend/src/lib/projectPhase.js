import i18n from '../i18n'

// Single source of truth for the project execution-phase workflow, mirroring
// backend/app/Helpers/ProjectPhaseWorkflow.php. The backend is still the
// enforcing authority — these helpers only decide what the UI shows.

export const PHASE_ORDER = ['planning', 'preparation', 'in_progress', 'finishing', 'completed']

export const PHASE_STYLES = {
  planning: 'bg-slate-100 text-slate-600',
  preparation: 'bg-amber-50 text-amber-700',
  in_progress: 'bg-blue-50 text-blue-700',
  finishing: 'bg-indigo-50 text-indigo-700',
  completed: 'bg-emerald-50 text-emerald-700',
}

// Reuses the same 'common:status.*' keys as every status badge elsewhere
// (planning/preparation/in_progress/finishing/completed are shared between
// project "status" and project "phase") — one translation, not a duplicate.
export function phaseLabel(phase) {
  return i18n.t(`status.${phase}`, { ns: 'common', defaultValue: phase })
}

export function phaseStyle(phase) {
  return PHASE_STYLES[phase] ?? 'bg-slate-100 text-slate-600'
}

// "completed" is deliberately excluded — only reachable via the existing
// project-close flow, never through a phase change request.
export function nextRequestablePhase(currentPhase) {
  const index = PHASE_ORDER.indexOf(currentPhase)
  if (index === -1) return null

  const next = PHASE_ORDER[index + 1] ?? null
  return next === 'completed' ? null : next
}
