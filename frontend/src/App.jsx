import { Navigate, Route, Routes } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { AuthProvider } from './context/AuthContext'
import { SettingsProvider } from './context/SettingsContext'
import ActivityLogsPage from './pages/ActivityLogsPage'
import CommitteesPage from './pages/CommitteesPage'
import CommitteeProjectPage from './pages/CommitteeProjectPage'
import DashboardPage from './pages/DashboardPage'
import DonationsPage from './pages/DonationsPage'
import ExpensesPage from './pages/ExpensesPage'
import PendingPhaseRequestsPage from './pages/PendingPhaseRequestsPage'
import PendingProjectDeletionRequestsPage from './pages/PendingProjectDeletionRequestsPage'
import FinancePage from './pages/FinancePage'
import LoginPage from './pages/LoginPage'
import MemberPortalLoginPage from './pages/MemberPortalLoginPage'
import MembersPage from './pages/MembersPage'
import ModulePlaceholderPage from './pages/ModulePlaceholderPage'
import NotificationsPage from './pages/NotificationsPage'
import ProjectsPage from './pages/ProjectsPage'
import ProfilePage from './pages/ProfilePage'
import ReportsPage from './pages/ReportsPage'
import SettingsPage from './pages/SettingsPage'
import SubscriptionsPage from './pages/SubscriptionsPage'
import ProtectedRoute from './routes/ProtectedRoute'

// Title/description/highlights/backendStatus text lives in
// locales/{fr,en}/common.json under `modules.<key>` — resolved via t() in
// App() below, not stored here as literal strings, so ModulePlaceholderPage
// renders in whichever language is active.
const moduleRoutes = [
  { path: '/members', key: 'members' },
  { path: '/members/board', key: 'members_board' },
  { path: '/members/subscribers', key: 'members_subscribers' },
  { path: '/members/new', key: 'members_new' },
  { path: '/subscriptions', key: 'subscriptions' },
  { path: '/subscriptions/pending', key: 'subscriptions_pending' },
  { path: '/subscriptions/receipts', key: 'subscriptions_receipts' },
  { path: '/projects', key: 'projects' },
  { path: '/projects/new', key: 'projects_new' },
  { path: '/committees', key: 'committees' },
  { path: '/reports', key: 'reports' },
  { path: '/donations', key: 'donations' },
  { path: '/financial-operations', key: 'financial_operations' },
  { path: '/activity-logs', key: 'activity_logs' },
  { path: '/settings/security', key: 'settings_security' },
]

function App() {
  const { t } = useTranslation('common')

  return (
    <SettingsProvider>
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<LoginPage />} />

        {/* Passkey login for subscribers — a second entry point into the
            SAME AuthContext session as /login (see AuthContext.loginWithPasskey).
            No separate dashboard/portal: on success this redirects to
            /dashboard like any other login, where PresidentLayout's nav
            renders whatever this user's permissions/committee assignments
            allow. */}
        <Route path="/member" element={<MemberPortalLoginPage />} />

        <Route
          path="/dashboard"
          element={
            <ProtectedRoute>
              <DashboardPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/members"
          element={
            <ProtectedRoute>
              <MembersPage mode="all" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/members/subscribers"
          element={
            <ProtectedRoute>
              <MembersPage mode="subscribers" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/members/new"
          element={
            <ProtectedRoute>
              <MembersPage mode="new" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/subscriptions"
          element={
            <ProtectedRoute>
              <SubscriptionsPage mode="all" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/subscriptions/pending"
          element={
            <ProtectedRoute>
              <SubscriptionsPage mode="pending" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/subscriptions/receipts"
          element={
            <ProtectedRoute>
              <SubscriptionsPage mode="receipts" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/projects"
          element={
            <ProtectedRoute>
              <ProjectsPage mode="all" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/projects/:projectId"
          element={
            <ProtectedRoute>
              <CommitteeProjectPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/projects/new"
          element={
            <ProtectedRoute>
              <ProjectsPage mode="new" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/donations"
          element={
            <ProtectedRoute>
              <DonationsPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/finance"
          element={
            <ProtectedRoute>
              <FinancePage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/expenses"
          element={
            <ProtectedRoute>
              <ExpensesPage mode="all" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/expenses/pending"
          element={
            <ProtectedRoute>
              <ExpensesPage mode="pending" />
            </ProtectedRoute>
          }
        />
        <Route
          path="/phase-requests/pending"
          element={
            <ProtectedRoute>
              <PendingPhaseRequestsPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/deletion-requests/pending"
          element={
            <ProtectedRoute>
              <PendingProjectDeletionRequestsPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/committees"
          element={
            <ProtectedRoute>
              <CommitteesPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/reports"
          element={
            <ProtectedRoute>
              <ReportsPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/activity-logs"
          element={
            <ProtectedRoute>
              <ActivityLogsPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/profile"
          element={
            <ProtectedRoute>
              <ProfilePage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/notifications"
          element={
            <ProtectedRoute>
              <NotificationsPage />
            </ProtectedRoute>
          }
        />
        <Route
          path="/settings"
          element={
            <ProtectedRoute>
              <SettingsPage />
            </ProtectedRoute>
          }
        />
        {moduleRoutes.map((route) => (
          <Route
            key={route.path}
            path={route.path}
            element={
              <ProtectedRoute>
                <ModulePlaceholderPage
                  title={t(`modules.${route.key}.title`)}
                  description={t(`modules.${route.key}.description`)}
                  highlights={t(`modules.${route.key}.highlights`, { returnObjects: true })}
                  backendStatus={t(`modules.${route.key}.backendStatus`)}
                />
              </ProtectedRoute>
            }
          />
        ))}
        <Route path="*" element={<Navigate to="/login" replace />} />
      </Routes>
    </AuthProvider>
    </SettingsProvider>
  )
}

export default App
