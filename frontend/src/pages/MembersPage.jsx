import { Trash2, UserPlus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import { createMember, deleteMember, getMembers } from '../api/members.api'
import { useAuth } from '../context/auth-context'
import { canCreateMember, canDeleteMember } from '../lib/roles'
import { getErrorMessage } from '../lib/apiErrors'

const initialForm = {
  name: '',
  email: '',
  phone: '',
  address: '',
}

const FORM_FIELDS = ['name', 'email', 'phone', 'address']

export default function MembersPage({ mode = 'all' }) {
  const { t } = useTranslation('association')
  const { user } = useAuth()
  const canCreate = canCreateMember(user)
  const canDelete = canDeleteMember(user)
  const [members, setMembers] = useState([])
  const [form, setForm] = useState(initialForm)
  const [submitting, setSubmitting] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [deleteError, setDeleteError] = useState('')
  const [deletingId, setDeletingId] = useState(null)

  useEffect(() => {
    loadMembers()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const filteredMembers = useMemo(() => {
    if (mode === 'subscribers') {
      return members.filter((member) => !member.is_bureau_member)
    }

    return members
  }, [mode, members])

  if (mode === 'new' && !canCreate) {
    return <Navigate to="/members" replace />
  }

  async function loadMembers() {
    setLoading(true)
    setLoadError('')

    try {
      const data = await getMembers()
      setMembers(data)
    } catch (error) {
      setLoadError(error.response?.data?.message ?? t('membersPage.loadError'))
    } finally {
      setLoading(false)
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
      await createMember(form)
      setForm(initialForm)
      await loadMembers()
    } catch (error) {
      setErrorMessage(
        getErrorMessage(error, t('membersPage.createError')),
      )
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(memberId) {
    if (!window.confirm(t('membersPage.deleteConfirm'))) return

    setDeleteError('')
    setDeletingId(memberId)

    try {
      await deleteMember(memberId)
      await loadMembers()
    } catch (error) {
      setDeleteError(getErrorMessage(error, t('membersPage.deleteError')))
    } finally {
      setDeletingId(null)
    }
  }

  const pageTitle =
    mode === 'new' ? t('membersPage.addSubscriber') : mode === 'subscribers' ? t('membersPage.subscribers') : t('membersPage.allSubscribers')

  const pageDescription =
    mode === 'new'
      ? t('membersPage.newDescription')
      : t('membersPage.allDescription')

  const showCreateForm = (mode === 'new' || mode === 'all') && canCreate

  return (
    <PresidentLayout
      title={pageTitle}
      description={pageDescription}
      breadcrumbs={['Subscribers']}
    >
      {loadError ? (
        <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {loadError}
        </div>
      ) : null}

      {deleteError ? (
        <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {deleteError}
        </div>
      ) : null}

      <div className={showCreateForm ? 'grid grid-cols-1 gap-6 xl:grid-cols-[420px_minmax(0,1fr)]' : ''}>
        {showCreateForm ? (
          <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
            <div className="mb-5 flex items-center gap-3">
              <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <UserPlus size={20} />
              </div>
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{t('membersPage.createTitle')}</h3>
                <p className="text-sm text-slate-500">{t('membersPage.createSubtitle')}</p>
              </div>
            </div>

            <form className="space-y-4" onSubmit={handleSubmit}>
              {errorMessage ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                  {errorMessage}
                </div>
              ) : null}

              {FORM_FIELDS.map((field) => (
                <input
                  key={field}
                  type="text"
                  name={field}
                  placeholder={t(`membersPage.fields.${field}`)}
                  value={form[field]}
                  onChange={handleChange}
                  className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
                />
              ))}

              <button
                type="submit"
                disabled={submitting}
                className="w-full rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white"
              >
                {submitting ? t('membersPage.creating') : t('membersPage.createSubscriber')}
              </button>
            </form>
          </section>
        ) : null}

        <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="border-b border-[#eef2ff] px-6 py-5">
            <h3 className="text-lg font-semibold text-slate-900">{t('membersPage.directoryTitle')}</h3>
            <p className="text-sm text-slate-500">{t('membersPage.subscriberCount', { count: filteredMembers.length })}</p>
          </div>

          <div className="divide-y divide-[#eef2ff]">
            {loading ? (
              <div className="space-y-3 px-6 py-8">
                <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
              </div>
            ) : filteredMembers.length === 0 ? (
              <div className="px-6 py-8 text-sm text-slate-500">
                {mode === 'subscribers' ? t('membersPage.emptySubscribersMode') : t('membersPage.emptyAllMode')}
              </div>
            ) : (
              filteredMembers.map((member) => (
              <div key={member.id} className="flex items-center justify-between gap-4 px-6 py-4">
                <div>
                  <p className="font-semibold text-slate-800">{member.user.name}</p>
                  <p className="text-sm text-slate-500">{member.user.email}</p>
                  <p className="text-xs text-slate-400">
                    {member.phone || t('membersPage.noPhone')} • {member.address || t('membersPage.noAddress')}
                  </p>
                </div>

                {canDelete ? (
                  <button
                    type="button"
                    onClick={() => handleDelete(member.id)}
                    disabled={deletingId === member.id}
                    aria-label={t('membersPage.deleteAria')}
                    className="rounded-xl border border-rose-200 px-3 py-2 text-rose-500 transition hover:bg-rose-50 disabled:opacity-60"
                  >
                    <Trash2 size={16} />
                  </button>
                ) : null}
              </div>
              ))
            )}
          </div>
        </section>
      </div>
    </PresidentLayout>
  )
}
