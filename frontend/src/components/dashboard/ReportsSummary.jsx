import { Download } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { getReportDownloadUrl, getReportRequestConfig } from '../../api/reports.api'

export default function ReportsSummary() {
  const { t } = useTranslation('dashboard')

  const reports = [
    { titleKey: 'reportsSummary.projectsReport', path: '/reports/projects' },
    { titleKey: 'reportsSummary.subscriptionsReport', path: '/reports/subscriptions' },
    { titleKey: 'reportsSummary.membersReport', path: '/reports/members' },
  ]

  async function handleDownload(path) {
    const response = await fetch(getReportDownloadUrl(path), getReportRequestConfig())
    const blob = await response.blob()
    const url = URL.createObjectURL(blob)
    window.open(url, '_blank')
  }

  return (
    <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-sm">
      <h2 className="mb-1 text-lg font-semibold text-slate-900">{t('reportsSummary.title')}</h2>
      <p className="mb-4 text-sm text-slate-500">
        {t('reportsSummary.subtitle')}
      </p>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        {reports.map((report) => (
          <button
            key={report.path}
            type="button"
            onClick={() => handleDownload(report.path)}
            className="flex items-center gap-3 rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-4 text-left transition duration-150 hover:-translate-y-0.5 hover:border-blue-300 hover:shadow-sm"
          >
            <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-600">
              <Download size={16} />
            </div>
            <span className="text-sm font-semibold text-slate-800">{t(report.titleKey)}</span>
          </button>
        ))}
      </div>

      {/* TODO(backend): there's no "annual report" concept, publication status, or per-project
          missing-report tracking — only the existing subscribers/subscriptions/projects exports. */}
      <p className="mt-4 border-t border-dashed border-[#eef2ff] pt-3 text-xs text-slate-400">
        {t('reportsSummary.footnote')}
      </p>
    </section>
  )
}
