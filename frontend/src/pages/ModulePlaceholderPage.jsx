import { Clock, FileSearch, Layers3 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'

export default function ModulePlaceholderPage({
  title,
  description,
  highlights = [],
  backendStatus,
}) {
  const { t } = useTranslation('common')

  return (
    <PresidentLayout
      title={title}
      description={description}
      breadcrumbs={['Modules']}
    >
      <div className="grid gap-6 xl:grid-cols-[minmax(0,1.25fr)_420px]">
        <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="mb-5 flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
              <Layers3 size={20} />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">{t('modulePlaceholder.plannedExperienceTitle')}</h3>
              <p className="text-sm text-slate-500">{t('modulePlaceholder.plannedExperienceSubtitle')}</p>
            </div>
          </div>

          <div className="grid gap-3 md:grid-cols-2">
            {highlights.map((item) => (
              <div
                key={item}
                className="rounded-2xl border border-[#e8edff] bg-[#fbfcff] px-4 py-3 text-sm font-medium text-slate-700"
              >
                {item}
              </div>
            ))}
          </div>
        </section>

        <section className="space-y-6">
          <div className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
            <div className="mb-4 flex items-center gap-3">
              <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-amber-50 text-amber-600">
                <Clock size={20} />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{t('modulePlaceholder.statusTitle')}</h3>
                <p className="text-sm text-slate-500">{t('modulePlaceholder.statusSubtitle')}</p>
              </div>
            </div>

            <p className="text-sm leading-6 text-slate-600">{backendStatus ?? t('modulePlaceholder.defaultBackendStatus')}</p>
          </div>

          <div className="rounded-3xl border border-dashed border-[#dfe5ff] bg-[#f7f9ff] p-6">
            <div className="mb-4 flex items-center gap-3">
              <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-slate-500 shadow-sm">
                <FileSearch size={20} />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{t('modulePlaceholder.nextStepTitle')}</h3>
                <p className="text-sm text-slate-500">
                  {t('modulePlaceholder.nextStepSubtitle')}
                </p>
              </div>
            </div>
          </div>
        </section>
      </div>
    </PresidentLayout>
  )
}
