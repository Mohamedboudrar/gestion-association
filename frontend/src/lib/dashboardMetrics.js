// Pure derivations from existing API payloads only. No invented fields, no fake numbers.
// Anything the backend genuinely doesn't track is called out with a TODO comment
// naming the field/endpoint that would be needed, rather than being estimated.

import { isFinanciallyCountedExpense } from './expenseStatus'
import { isFinanciallyCountedDonation } from './donationStatus'
import i18n from '../i18n'

function monthKey(date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

export function lastNMonths(n = 12) {
  const now = new Date()
  return Array.from({ length: n }, (_, i) => {
    const d = new Date(now.getFullYear(), now.getMonth() - (n - 1 - i), 1)
    return { key: monthKey(d), label: d.toLocaleString('fr-FR', { month: 'short' }), date: d }
  })
}

export function computeMonthlyFinancials(subscriptions, donations, expenses, months = 12) {
  const buckets = lastNMonths(months)
  const map = new Map(buckets.map((b) => [b.key, { month: b.label, subscriptions: 0, donations: 0, expenses: 0 }]))

  for (const s of subscriptions) {
    if (s.status !== 'verified' || !s.payment_date) continue
    const key = monthKey(new Date(s.payment_date))
    if (map.has(key)) map.get(key).subscriptions += Number(s.amount ?? 0)
  }

  for (const d of donations) {
    if (!d.donation_date || !isFinanciallyCountedDonation(d)) continue
    const key = monthKey(new Date(d.donation_date))
    if (map.has(key)) map.get(key).donations += Number(d.amount ?? 0)
  }

  for (const e of expenses) {
    if (!e.expense_date || !isFinanciallyCountedExpense(e)) continue
    const key = monthKey(new Date(e.expense_date))
    if (map.has(key)) map.get(key).expenses += Number(e.amount ?? 0)
  }

  return Array.from(map.values()).map((row) => ({
    ...row,
    revenue: row.subscriptions + row.donations,
  }))
}

export function isProjectDelayed(project) {
  return project.status === 'active' && project.end_date && new Date(project.end_date) < new Date()
}

// Draft, committee_ready, and funding_ready are the three pre-active lifecycle
// stages — grouped here as "In Setup" since none of them can hold donations
// or expenses yet. See backend/app/Helpers/ProjectLifecycle.php.
export const PRE_ACTIVE_STATUSES = ['draft', 'committee_ready', 'funding_ready']

export function computeProjectBreakdown(projects) {
  return {
    total: projects.length,
    inSetup: projects.filter((p) => PRE_ACTIVE_STATUSES.includes(p.status)).length,
    active: projects.filter((p) => p.status === 'active' && !isProjectDelayed(p)).length,
    completed: projects.filter((p) => p.status === 'completed').length,
    // "Delayed" is not a stored project status — derived as active + past end_date.
    delayed: projects.filter(isProjectDelayed).length,
  }
}

export function committeeLeader(project) {
  return project.members?.find((m) => m.committee_role === 'leader') ?? null
}

export function computeCommittees(projects, activityByProject) {
  return projects
    .filter((p) => p.status === 'active' || PRE_ACTIVE_STATUSES.includes(p.status))
    .map((project) => {
      const leader = committeeLeader(project)
      const budget = Number(project.budget ?? 0)
      const spent = Number(project.expenses ?? 0)
      const budgetUsagePct = budget > 0 ? Math.min(100, Math.round((spent / budget) * 100)) : 0
      const memberCount = project.members?.length ?? project.members_count ?? 0
      const lastActivityAt = activityByProject?.get(project.id) ?? null

      let tone = 'green'
      if (!leader || memberCount === 0) tone = 'red'
      else if (budgetUsagePct >= 90) tone = 'yellow'

      return {
        projectId: project.id,
        projectName: project.name,
        leaderName: leader?.name ?? null,
        memberCount,
        budgetUsagePct,
        status: project.status,
        lastActivityAt,
        tone,
        // TODO(backend): no "final report submitted" field exists on projects yet —
        // add e.g. `report_submitted_at` to expose this honestly instead of guessing.
        reportSubmitted: null,
      }
    })
}

export function computeActivityByProject(logs) {
  const map = new Map()

  for (const log of logs) {
    let projectId = null
    if (log.subject_type === 'App\\Models\\Project') {
      projectId = log.subject?.id
    } else if (log.subject?.project_id) {
      projectId = log.subject.project_id
    }

    if (projectId == null) continue

    const existing = map.get(projectId)
    if (!existing || new Date(log.created_at) > new Date(existing)) {
      map.set(projectId, log.created_at)
    }
  }

  return map
}

export function groupActivityByRecency(logs) {
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const yesterday = new Date(today)
  yesterday.setDate(yesterday.getDate() - 1)
  const weekAgo = new Date(today)
  weekAgo.setDate(weekAgo.getDate() - 7)

  const groups = { Today: [], Yesterday: [], 'This Week': [], Earlier: [] }

  for (const log of logs) {
    const date = new Date(log.created_at)
    if (date >= today) groups.Today.push(log)
    else if (date >= yesterday) groups.Yesterday.push(log)
    else if (date >= weekAgo) groups['This Week'].push(log)
    else groups.Earlier.push(log)
  }

  return groups
}

export function describeActivity(log) {
  const causer = log.causer?.name ?? i18n.t('activity.unknownActor', { ns: 'dashboard' })
  const eventKey = { created: 'created', updated: 'updated', deleted: 'deleted' }[log.event] ?? 'changed'
  const event = i18n.t(`activity.events.${eventKey}`, { ns: 'dashboard' })
  const subjectKey = String(log.subject_type ?? '').split('\\').pop()?.toLowerCase() ?? 'record'
  const subject = i18n.t(`activity.subjects.${subjectKey}`, {
    ns: 'dashboard',
    defaultValue: i18n.t('activity.subjects.record', { ns: 'dashboard' }),
  })

  return i18n.t('activity.sentence', { ns: 'dashboard', causer, event, subject })
}

export function expiringSubscriptions(subscriptions, withinDays = 30) {
  const cutoff = new Date()
  cutoff.setDate(cutoff.getDate() + withinDays)

  return subscriptions.filter(
    (s) => s.status === 'verified' && s.expires_at && new Date(s.expires_at) <= cutoff && new Date(s.expires_at) >= new Date(),
  )
}

export function projectsEndingSoon(projects, withinDays = 30) {
  const cutoff = new Date()
  cutoff.setDate(cutoff.getDate() + withinDays)

  return projects.filter(
    (p) => p.status === 'active' && p.end_date && new Date(p.end_date) <= cutoff && new Date(p.end_date) >= new Date(),
  )
}

export function topDonors(donations, limit = 5) {
  const totals = new Map()

  for (const d of donations) {
    if (!isFinanciallyCountedDonation(d)) continue
    const name = d.member?.name ?? d.donor_name ?? i18n.t('unknownDonor', { ns: 'dashboard' })
    totals.set(name, (totals.get(name) ?? 0) + Number(d.amount ?? 0))
  }

  return Array.from(totals.entries())
    .map(([name, total]) => ({ name, total }))
    .sort((a, b) => b.total - a.total)
    .slice(0, limit)
}

export function topFundedProjects(projects, limit = 5) {
  return [...projects]
    .sort((a, b) => Number(b.collected ?? 0) - Number(a.collected ?? 0))
    .slice(0, limit)
}

export function highestSpendingProjects(projects, limit = 5) {
  return [...projects]
    .sort((a, b) => Number(b.expenses ?? 0) - Number(a.expenses ?? 0))
    .slice(0, limit)
}

export function projectsFinishingThisMonth(projects) {
  const now = new Date()
  return projects.filter((p) => {
    if (!p.end_date) return false
    const end = new Date(p.end_date)
    return end.getFullYear() === now.getFullYear() && end.getMonth() === now.getMonth()
  })
}

export function newestSubscribers(members, limit = 5) {
  return [...members]
    .sort((a, b) => new Date(b.created_at ?? 0) - new Date(a.created_at ?? 0))
    .slice(0, limit)
}

// --- Finance page ---

export function computeFinanceSummary(subscriptions, donations, projects) {
  const verifiedSubscriptionsAllTime = subscriptions
    .filter((s) => s.status === 'verified')
    .reduce((sum, s) => sum + Number(s.amount ?? 0), 0)

  // Lifetime total of verified subscription fees, not scoped to the current year.
  const annualSubscriptionIncome = verifiedSubscriptionsAllTime

  const totalDonated = donations
    .filter(isFinanciallyCountedDonation)
    .reduce((sum, d) => sum + Number(d.amount ?? 0), 0)
  const totalProjectBudget = projects.reduce((sum, p) => sum + Number(p.budget ?? 0), 0)

  return {
    verifiedSubscriptionsAllTime,
    annualSubscriptionIncome,
    totalDonated,
    totalProjectBudget,
  }
}

// The dominant component behind a negative available_funds (see
// FundsHelper::availableFunds on the backend, the actual source of truth for
// the number itself) — money currently locked in fund allocations on
// projects that haven't completed/cancelled yet. Used only to explain *why*
// available funds might be negative; it is not itself the balance.
export function computeOpenProjectsAllocated(projects) {
  return projects
    .filter((p) => !['completed', 'cancelled'].includes(p.status))
    .reduce((sum, p) => sum + Number(p.allocated ?? 0), 0)
}

// Reuses the `collected`/`expenses`/`allocated`/`remaining` figures
// ProjectResource already computes server-side — does not re-derive any
// financial total from raw donation/expense/allocation rows here.
//
// `collected` (from the backend) is donations + allocations combined, and
// `remaining` is already `collected - expenses` — the money actually in the
// project's pocket, spent or not. `budget` is a separate, fixed spending
// ceiling set at project creation; it is never itself money the project
// holds, so it must never be added on top of `collected` when computing what
// is left to spend (that previously double-counted budget as if it were
// funds in hand, on top of the funds actually collected/allocated).
export function computeProjectFunding(projects) {
  return projects.map((project) => {
    const budget = Number(project.budget ?? 0)
    const allocatedTotal = Number(project.allocated ?? 0)
    const collected = Number(project.collected ?? 0)
    const donationsTotal = collected - allocatedTotal
    const expensesTotal = Number(project.expenses ?? 0)
    const remainingFunds = Number(project.remaining ?? collected - expensesTotal)

    return {
      project,
      budget,
      donationsTotal,
      allocatedTotal,
      collected,
      expensesTotal,
      remainingFunds,
    }
  })
}

export function groupDonationsByProject(donations) {
  const groups = new Map()

  for (const donation of donations) {
    if (!isFinanciallyCountedDonation(donation)) continue
    const key = donation.project?.id ?? 'unassigned'
    const label = donation.project?.name ?? i18n.t('unassignedProject', { ns: 'dashboard' })

    if (!groups.has(key)) {
      groups.set(key, { projectId: donation.project?.id ?? null, projectName: label, donations: [], total: 0 })
    }

    const group = groups.get(key)
    group.donations.push(donation)
    group.total += Number(donation.amount ?? 0)
  }

  return Array.from(groups.values()).sort((a, b) => b.total - a.total)
}
