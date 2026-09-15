import { Download } from 'lucide-react'
import { useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import { downloadReportBlob } from '../api/reports.api'
import { useAuth } from '../context/auth-context'
import { canViewReports } from '../lib/roles'

export default function ReportsPage() {
  const { t } = useTranslation('reports')
  const { user } = useAuth()
  const [errorMessage, setErrorMessage] = useState('')

  const reportCards = [
    { titleKey: 'page.cards.membersPdf', path: '/reports/members', type: 'pdf' },
    { titleKey: 'page.cards.subscriptionsPdf', path: '/reports/subscriptions', type: 'pdf' },
    { titleKey: 'page.cards.projectsPdf', path: '/reports/projects', type: 'pdf' },
    { titleKey: 'page.cards.membersExcel', path: '/reports/members/excel', type: 'excel' },
    { titleKey: 'page.cards.subscriptionsExcel', path: '/reports/subscriptions/excel', type: 'excel' },
    { titleKey: 'page.cards.projectsExcel', path: '/reports/projects/excel', type: 'excel' },
  ]

  if (!canViewReports(user)) {
    return <Navigate to="/dashboard" replace />
  }

  async function handleDownload(path) {
    setErrorMessage('')

    try {
      const blob = await downloadReportBlob(path)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank')
    } catch (error) {
      setErrorMessage(error.message ?? t('page.downloadError'))
    }
  }

  return (
    <PresidentLayout
      title={t('page.title')}
      description={t('page.description')}
      breadcrumbs={['Reports']}
    >
      {errorMessage ? (
        <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {errorMessage}
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-5 md:grid-cols-2 xl:grid-cols-3">
        {reportCards.map((report) => (
          <button
            key={report.path}
            type="button"
            onClick={() => handleDownload(report.path)}
            className="rounded-3xl border border-[#dfe5ff] bg-white p-6 text-left shadow-sm transition duration-150 hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-md active:translate-y-0"
          >
            <div className="mb-4 flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
              <Download size={18} />
            </div>
            <p className="text-base font-semibold text-slate-800">{t(report.titleKey)}</p>
            <p className="mt-1.5 text-sm text-slate-500">{t('page.downloadTypeLine', { type: report.type.toUpperCase() })}</p>
          </button>
        ))}
      </div>
    </PresidentLayout>
  )
}
