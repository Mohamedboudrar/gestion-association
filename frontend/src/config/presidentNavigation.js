// Reorganized into workspace-shaped groups (Association / Finance / Approvals /
// Administration) instead of a flat per-resource list. Receipts and the full
// subscription payment history no longer get their own sidebar entries — both
// are still fully reachable from inside the Finance page (see FinancePage.jsx),
// per the "Receipts belongs inside Finance, not the sidebar" design decision.
// "Project Requests" covers both pending-request queues (phase advancement and
// project deletion) as two items — kept distinct so neither loses its own
// dedicated page/route.
//
// Sections/items carry translation keys (titleKey/labelKey) rather than
// literal display strings — this file is plain JS, not a component, so it
// can't call useTranslation() itself. PresidentLayout.jsx resolves each key
// via t() from the 'common' namespace (see its `nav.*` keys) at render time.
export const presidentNavigation = [
  {
    titleKey: 'nav.overview',
    items: [{ labelKey: 'nav.dashboard', path: '/dashboard' }],
  },
  {
    titleKey: 'nav.association',
    items: [
      { labelKey: 'nav.subscribers', path: '/members' },
      { labelKey: 'nav.projects', path: '/projects' },
      { labelKey: 'nav.committees', path: '/committees' },
    ],
  },
  {
    titleKey: 'nav.finance',
    items: [
      { labelKey: 'nav.financeOverview', path: '/finance' },
      { labelKey: 'nav.donations', path: '/donations' },
      { labelKey: 'nav.expenses', path: '/expenses' },
    ],
  },
  {
    titleKey: 'nav.approvals',
    badgeKey: 'approvals',
    items: [
      { labelKey: 'nav.subscriptionPayments', path: '/subscriptions/pending' },
      { labelKey: 'nav.expenseApprovals', path: '/expenses/pending' },
      { labelKey: 'nav.phaseRequests', path: '/phase-requests/pending' },
      { labelKey: 'nav.deletionRequests', path: '/deletion-requests/pending' },
    ],
  },
  {
    titleKey: 'nav.reports',
    items: [{ labelKey: 'nav.reports', path: '/reports' }],
  },
  {
    titleKey: 'nav.administration',
    items: [
      { labelKey: 'nav.activityLogs', path: '/activity-logs' },
      { labelKey: 'nav.settings', path: '/settings' },
    ],
  },
]

import { canAssignCommittee, isTreasurerRole } from '../lib/roles'

// Vice-président, Trésorier, Vice-trésorier, Secrétaire général, Vice-secrétaire
// général, Conseiller — every bureau role except Président. Sections that
// depend on the specific role (Committees, Finance) are added conditionally;
// the underlying pages/API already scope what each role can see/do.
export function buildBureauNavigation(user) {
  const sections = [
    { titleKey: 'nav.overview', items: [{ labelKey: 'nav.dashboard', path: '/dashboard' }] },
    { titleKey: 'nav.subscribers', items: [{ labelKey: 'nav.subscribers', path: '/members' }] },
    { titleKey: 'nav.subscriptions', items: [{ labelKey: 'nav.subscriptionPayments', path: '/subscriptions' }] },
  ]

  const projectItems = [{ labelKey: 'nav.allProjects', path: '/projects' }]
  if (canAssignCommittee(user)) {
    projectItems.push({ labelKey: 'nav.committees', path: '/committees' })
    projectItems.push({ labelKey: 'nav.pendingPhaseRequests', path: '/phase-requests/pending' })
  }
  sections.push({ titleKey: 'nav.projects', items: projectItems })

  if (isTreasurerRole(user)) {
    sections.push({
      titleKey: 'nav.finance',
      items: [
        { labelKey: 'nav.finance', path: '/finance' },
        { labelKey: 'nav.donations', path: '/donations' },
        { labelKey: 'nav.expenses', path: '/expenses' },
        { labelKey: 'nav.pendingExpenseApprovals', path: '/expenses/pending' },
      ],
    })
  }

  sections.push({ titleKey: 'nav.reports', items: [{ labelKey: 'nav.reports', path: '/reports' }] })

  // Every bureau role gets the nav entry — the backend scopes what actually
  // shows (president/vice-président/trésorier see everything, every other
  // bureau role only their assigned projects' activity, possibly none).
  sections.push({ titleKey: 'nav.administration', items: [{ labelKey: 'nav.activityLogs', path: '/activity-logs' }] })

  return sections
}

// Plain "abonne" subscriber — no bureau role. Every item here points at an
// existing page that already self-scopes to "my own records" server-side
// (SubscriptionsPage/ProjectsPage) — no separate subscriber-only pages.
// "My Projects" is always shown (like the previous "Browse Projects") rather
// than conditionally hidden: GET /me doesn't carry project-assignment data
// synchronously (that's only known via the async useCommitteeProjects fetch
// used elsewhere), and an empty scoped list is a fine, honest result.
// No "My Donations" — subscribers never had, and don't get, a personal
// donations view; donation records (donor names, receipts) are for whoever
// manages a project's finances (leader/treasurer) or bureau finance roles
// only. A committee-assigned subscriber sees donation *totals* for their
// own project inside "My Projects" (CommitteeProjectPage's Overview tab),
// never individual records — see App Unification in FRONTEND_SUMMARY.md.
// Notifications/Profile aren't repeated here — PresidentLayout already
// renders them in a fixed footer section for every signed-in user.
export function buildSubscriberNavigation() {
  return [
    { titleKey: 'nav.overview', items: [{ labelKey: 'nav.dashboard', path: '/dashboard' }] },
    { titleKey: 'nav.subscriptions', items: [{ labelKey: 'nav.mySubscription', path: '/subscriptions' }] },
    { titleKey: 'nav.projects', items: [{ labelKey: 'nav.myProjects', path: '/projects' }] },
  ]
}
