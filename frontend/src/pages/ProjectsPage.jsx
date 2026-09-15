import { FolderPlus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import { deleteProject, getProjects } from '../api/projects.api'
import http from '../api/http'
import { useAuth } from '../context/auth-context'
import { canDeleteProjectDirectly, canManageProjects, hasRole } from '../lib/roles'
import { stageLabel } from '../lib/projectLifecycle'
import { getErrorMessage } from '../lib/apiErrors'
import { phaseLabel } from '../lib/projectPhase'
import { formatCurrency, statusBadgeClass } from '../components/committee/committeeUtils'
import TomTomMapPicker from '../components/maps/TomTomMapPicker'

const initialForm = {
  name: '',
  description: '',
  start_date: '',
  end_date: '',
  budget: '',
}

const initialLocation = { latitude: null, longitude: null }
const FORM_FIELDS = ['name', 'description', 'start_date', 'end_date', 'budget']

export default function ProjectsPage({ mode = 'all' }) {
  const { t } = useTranslation('association')
  const { user } = useAuth()
  const isPresident = hasRole(user, 'president')
  const canManage = canManageProjects(user)
  const [projects, setProjects] = useState([])
  const [form, setForm] = useState(initialForm)
  const [location, setLocation] = useState(initialLocation)
  const [submitting, setSubmitting] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [deletingId, setDeletingId] = useState(null)

  useEffect(() => {
    loadProjects()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (mode === 'new' && !canManage) {
    return <Navigate to="/projects" replace />
  }

  async function loadProjects() {
    setLoading(true)
    setLoadError('')

    try {
      const data = await getProjects()
      setProjects(data)
    } catch (error) {
      setLoadError(error.response?.data?.message ?? t('projectsPage.loadError'))
    } finally {
      setLoading(false)
    }
  }

  async function handleDeleteProject(projectId) {
    if (!window.confirm(t('projectsPage.deleteConfirm'))) return

    setDeletingId(projectId)
    setLoadError('')

    try {
      await deleteProject(projectId)
      setProjects((current) => current.filter((project) => project.id !== projectId))
    } catch (error) {
      setLoadError(error.response?.data?.message ?? t('projectsPage.deleteError'))
    } finally {
      setDeletingId(null)
    }
  }

  function handleChange(event) {
    const { name, value } = event.target
    setForm((current) => ({ ...current, [name]: value }))
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setErrorMessage('')

    try {
      await http.post('/projects', {
        ...form,
        budget: Number(form.budget),
        latitude: location.latitude,
        longitude: location.longitude,
      })

      setForm(initialForm)
      setLocation(initialLocation)
      await loadProjects()
    } catch (error) {
      setErrorMessage(
        getErrorMessage(error, t('projectsPage.createError')),
      )
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <PresidentLayout
      title={mode === 'new' ? t('projectsPage.createTitle') : t('projectsPage.allTitle')}
      description={t('projectsPage.description')}
      breadcrumbs={['Projects']}
    >
      {loadError ? (
        <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {loadError}
        </div>
      ) : null}

      <div className={canManage ? 'grid grid-cols-1 gap-6 xl:grid-cols-[420px_minmax(0,1fr)]' : ''}>
        {canManage && (mode === 'new' || mode === 'all') ? (
          <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
            <div className="mb-5 flex items-center gap-3">
              <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <FolderPlus size={20} />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{t('projectsPage.createTitle')}</h3>
                <p className="text-sm text-slate-500">{t('projectsPage.createSubtitle')}</p>
              </div>
            </div>

            <form className="space-y-4" onSubmit={handleSubmit}>
              {errorMessage ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                  {errorMessage}
                </div>
              ) : null}

              {FORM_FIELDS.map((field) => (
                field === 'description' ? (
                  <textarea
                    key={field}
                    name={field}
                    value={form[field]}
                    onChange={handleChange}
                    placeholder={t(`projectsPage.fields.${field}`)}
                    className="min-h-[96px] w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 py-3 text-sm outline-none"
                  />
                ) : (
                  <input
                    key={field}
                    type={field.includes('date') ? 'date' : field === 'budget' ? 'number' : 'text'}
                    name={field}
                    value={form[field]}
                    onChange={handleChange}
                    placeholder={t(`projectsPage.fields.${field}`)}
                    className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
                  />
                )
              ))}

              <div className="space-y-2 border-t border-[#eef2ff] pt-4">
                <h4 className="text-sm font-semibold text-slate-800">{t('projectsPage.locationTitle')}</h4>
                <p className="text-xs text-slate-400">{t('projectsPage.locationHint')}</p>
                <TomTomMapPicker
                  latitude={location.latitude}
                  longitude={location.longitude}
                  onChange={(latitude, longitude) => setLocation({ latitude, longitude })}
                />
              </div>

              <p className="text-xs text-slate-400">
                {t('projectsPage.draftHint')}
              </p>

              <button className="w-full rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white">
                {submitting ? t('projectsPage.saving') : t('projectsPage.saveProject')}
              </button>
            </form>
          </section>
        ) : null}

        <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="border-b border-[#eef2ff] px-6 py-5">
            <h3 className="text-lg font-semibold text-slate-900">{t('projectsPage.portfolioTitle')}</h3>
            <p className="text-sm text-slate-500">{t('projectsPage.projectCount', { count: projects.length })}</p>
          </div>
          <div className="divide-y divide-[#eef2ff]">
            {loading ? (
              <div className="space-y-3 px-6 py-8">
                <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
              </div>
            ) : projects.length === 0 ? (
              <div className="px-6 py-8 text-sm text-slate-500">
                {canManage ? t('projectsPage.emptyCanManage') : t('projectsPage.emptyNoManage')}
              </div>
            ) : (
              projects.map((project) => {
              const isOnCommittee = project.members?.some((member) => member.user_id === user?.id)
              const canOpen = isPresident || isOnCommittee

              return (
                <div key={project.id} className="px-6 py-4">
                  <div className="flex items-start justify-between gap-4">
                    <div>
                      <p className="font-semibold text-slate-800">{project.name}</p>
                      <p className="text-sm text-slate-500">{project.description || t('projectsPage.noDescription')}</p>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                      <span className={statusBadgeClass(project.status)}>
                        {stageLabel(project.status)}
                      </span>
                      {canOpen ? (
                        <Link
                          to={`/projects/${project.id}`}
                          className="rounded-full border border-[#dfe5ff] px-3 py-1 text-xs font-semibold text-blue-600 transition hover:bg-blue-50"
                        >
                          {t('projectsPage.open')}
                        </Link>
                      ) : null}
                      {canDeleteProjectDirectly(user, project) ? (
                        <button
                          type="button"
                          disabled={deletingId === project.id}
                          onClick={() => handleDeleteProject(project.id)}
                          aria-label={t('projectsPage.deleteProjectAria')}
                          className="rounded-full border border-rose-200 p-1.5 text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"
                        >
                          <Trash2 size={14} />
                        </button>
                      ) : null}
                    </div>
                  </div>
                  <p className="mt-2 text-xs text-slate-400">
                    {t('projectsPage.metaLine', {
                      budget: formatCurrency(project.budget),
                      phase: phaseLabel(project.phase),
                      progress: project.progress_percentage,
                      members: project.members_count,
                    })}
                  </p>
                </div>
              )
              })
            )}
          </div>
        </section>
      </div>
    </PresidentLayout>
  )
}
