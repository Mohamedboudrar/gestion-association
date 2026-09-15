import { MapPin } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import TomTomMapViewer from '../maps/TomTomMapViewer'

// Shows every project that has a saved location on one map — see
// TomTomMapViewer's multi-marker mode. Projects without coordinates simply
// don't appear here (no invented placement); if none have one yet, this
// renders a plain empty state instead of an empty/misleading map.
export default function ProjectMap({ projects }) {
  const { t } = useTranslation('dashboard')
  const located = (projects ?? []).filter((project) => project.latitude != null && project.longitude != null)

  return (
    <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-sm">
      <div className="mb-4 flex items-center gap-3">
        <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
          <MapPin size={20} />
        </div>
        <div>
          <h2 className="text-lg font-semibold text-slate-900">{t('projectMap.title')}</h2>
          <p className="text-sm text-slate-500">
            {located.length ? t('projectMap.withLocation', { count: located.length }) : t('projectMap.noneSet')}
          </p>
        </div>
      </div>

      {located.length ? (
        <TomTomMapViewer
          markers={located.map((project) => ({
            id: project.id,
            latitude: project.latitude,
            longitude: project.longitude,
            name: project.name,
            href: `/projects/${project.id}`,
          }))}
        />
      ) : (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-[#e2e7ff] px-6 py-10 text-center">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-50 text-slate-400">
            <MapPin size={20} />
          </div>
          <p className="text-sm font-semibold text-slate-700">{t('projectMap.emptyTitle')}</p>
          <p className="text-xs text-slate-400">{t('projectMap.emptyNote')}</p>
        </div>
      )}
    </section>
  )
}
