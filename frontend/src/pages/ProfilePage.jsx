import { Mail, MapPin, Phone, ShieldCheck, UserCircle2, WalletCards } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import DueStatusBadge from '../components/committee/DueStatusBadge'
import { formatCurrency } from '../components/committee/committeeUtils'
import { useAuth } from '../context/auth-context'
import { getMembers, updateMember } from '../api/members.api'
import { getMemberDashboard } from '../api/auth.api'
import { isBureauMember } from '../lib/roles'
import { useRoleLabel } from '../hooks/useStatusLabel'

function getInitials(name) {
  return String(name ?? 'U')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((value) => value[0])
    .join('')
    .toUpperCase()
}

export default function ProfilePage() {
  const { t } = useTranslation('profile')
  const roleLabel = useRoleLabel()
  const { user } = useAuth()
  const isBureau = isBureauMember(user)

  const displayName = user?.name ?? t('bureauFallback')
  const email = user?.email ?? ''
  const roleSlug = user?.roles?.[0]?.name ?? user?.role ?? null
  const role = isBureau
    ? (roleSlug ? roleLabel(roleSlug) : t('bureauFallback'))
    : t('subscriberRole')
  const initials = getInitials(displayName)
  const [member, setMember] = useState(null)
  const [loadingMember, setLoadingMember] = useState(true)
  const [isEditing, setIsEditing] = useState(false)
  const [saving, setSaving] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [formValues, setFormValues] = useState({ phone: '', address: '' })
  const [duesHistory, setDuesHistory] = useState([])
  const [loadingDues, setLoadingDues] = useState(true)

  useEffect(() => {
    let cancelled = false

    // Bureau users edit their own linked Member record the existing way
    // (needs member.id from the full list). A plain subscriber never had
    // members.view-style list access before reaching this page — their own
    // profile comes from the same safe, self-scoped endpoint the Member
    // Portal dashboard used, display-only (no member.id, so no edit here).
    async function loadOwnMember() {
      setLoadingMember(true)

      try {
        if (isBureau) {
          const members = await getMembers()
          const own = (members ?? []).find((entry) => entry.user?.id === user?.id) ?? null

          if (!cancelled) {
            setMember(own)
            setFormValues({ phone: own?.phone ?? '', address: own?.address ?? '' })
          }
        } else {
          const summary = await getMemberDashboard()

          if (!cancelled) {
            setMember({ phone: summary.profile?.phone, address: summary.profile?.address })
          }
        }
      } finally {
        if (!cancelled) setLoadingMember(false)
      }
    }

    if (user?.id) loadOwnMember()

    return () => {
      cancelled = true
    }
  }, [user?.id, isBureau])

  // Dues history is always sourced from the same self-scoped endpoint
  // (Member Portal dashboard already returns it), regardless of whether
  // this is a bureau or plain subscriber account — every account with a
  // linked Member record has one, since bureau accounts get a Member record
  // too (see app:create-bureau-accounts).
  useEffect(() => {
    let cancelled = false

    async function loadDues() {
      setLoadingDues(true)

      try {
        const summary = await getMemberDashboard()

        if (!cancelled) setDuesHistory(summary.dues_history ?? [])
      } catch {
        if (!cancelled) setDuesHistory([])
      } finally {
        if (!cancelled) setLoadingDues(false)
      }
    }

    if (user?.id) loadDues()

    return () => {
      cancelled = true
    }
  }, [user?.id])

  const profileItems = [
    { label: t('emailAddress'), value: email, icon: Mail },
    { label: t('role'), value: role, icon: ShieldCheck },
  ]

  function handleChange(event) {
    const { name, value } = event.target

    setFormValues((current) => ({
      ...current,
      [name]: value,
    }))
  }

  function handleCancel() {
    setFormValues({ phone: member?.phone ?? '', address: member?.address ?? '' })
    setErrorMessage('')
    setIsEditing(false)
  }

  async function handleSave() {
    if (!member?.id) {
      setErrorMessage(t('noMemberLinkedError'))
      return
    }

    setSaving(true)
    setErrorMessage('')

    try {
      const updated = await updateMember(member.id, {
        phone: formValues.phone.trim(),
        address: formValues.address.trim(),
      })
      setMember(updated)
      setIsEditing(false)
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('saveError'),
      )
    } finally {
      setSaving(false)
    }
  }

  return (
    <PresidentLayout
      title={t('title')}
      description={isBureau ? t('bureauDescription') : t('subscriberDescription')}
      breadcrumbs={['Settings']}
    >
      <div className="grid grid-cols-1 gap-6 xl:grid-cols-[380px_minmax(0,1fr)]">
        <section className="rounded-3xl border border-[#dfe5ff] bg-white p-7 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="flex flex-col items-center text-center">
            <div className="flex h-28 w-28 items-center justify-center rounded-full bg-gradient-to-br from-sky-200 to-blue-500 text-3xl font-bold text-white shadow-lg shadow-blue-100">
              {initials}
            </div>

            <h4 className="mt-5 text-2xl font-bold leading-tight text-slate-900">{displayName}</h4>
            <p className="mt-1 text-sm text-slate-500">{role}</p>

            <div className="mt-6 grid w-full grid-cols-2 gap-3">
              <div className="rounded-2xl bg-[#f7f9ff] px-4 py-3 text-left">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                  {t('status')}
                </p>
                <p className="mt-1 text-sm font-semibold text-emerald-600">{t('active')}</p>
              </div>
              <div className="rounded-2xl bg-[#f7f9ff] px-4 py-3 text-left">
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                  {t('access')}
                </p>
                <p className="mt-1 text-sm font-semibold text-slate-800">{role}</p>
              </div>
            </div>
          </div>
        </section>

        <section className="rounded-3xl border border-[#dfe5ff] bg-white p-7 shadow-sm">
          <div className="mb-6 flex items-center justify-between">
            <div>
              <h4 className="text-lg font-semibold text-slate-900">{t('accountInformation')}</h4>
              <p className="mt-0.5 text-sm text-slate-500">{t('accountInformationNote')}</p>
            </div>

            {isBureau ? (
              <div className="flex items-center gap-2">
                {isEditing ? (
                  <button
                    type="button"
                    onClick={handleCancel}
                    disabled={saving}
                    className="rounded-xl border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition duration-150 hover:bg-slate-50 disabled:opacity-60"
                  >
                    {t('cancel')}
                  </button>
                ) : null}

                <button
                  type="button"
                  onClick={isEditing ? handleSave : () => setIsEditing(true)}
                  disabled={saving || loadingMember || (!isEditing && !member)}
                  className="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-lg shadow-blue-200 transition duration-150 hover:bg-blue-700 active:scale-[0.97] disabled:opacity-60"
                >
                  {saving ? t('saving') : isEditing ? t('saveChanges') : t('editProfile')}
                </button>
              </div>
            ) : null}
          </div>

          {errorMessage ? (
            <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
              {errorMessage}
            </div>
          ) : null}

          {!loadingMember && !member ? (
            <div className="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
              {t('noMemberLinked')}
            </div>
          ) : null}

          <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div className="rounded-2xl border border-[#e8edff] bg-[#fbfcff] p-4">
              <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <UserCircle2 size={18} />
              </div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                {t('fullName')}
              </p>
              <p className="mt-2 text-sm font-semibold text-slate-800">{displayName}</p>
            </div>

            <div className="rounded-2xl border border-[#e8edff] bg-[#fbfcff] p-4">
              <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <Phone size={18} />
              </div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                {t('phone')}
              </p>
              {isEditing ? (
                <input
                  type="text"
                  name="phone"
                  value={formValues.phone}
                  onChange={handleChange}
                  className="mt-2 h-11 w-full rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm font-semibold text-slate-800 outline-none"
                />
              ) : (
                <p className="mt-2 text-sm font-semibold text-slate-800">
                  {member?.phone || t('notProvided')}
                </p>
              )}
            </div>

            <div className="rounded-2xl border border-[#e8edff] bg-[#fbfcff] p-4">
              <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <MapPin size={18} />
              </div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                {t('address')}
              </p>
              {isEditing ? (
                <input
                  type="text"
                  name="address"
                  value={formValues.address}
                  onChange={handleChange}
                  className="mt-2 h-11 w-full rounded-xl border border-[#dfe5ff] bg-white px-3 text-sm font-semibold text-slate-800 outline-none"
                />
              ) : (
                <p className="mt-2 text-sm font-semibold text-slate-800">
                  {member?.address || t('notProvided')}
                </p>
              )}
            </div>

            {profileItems.map(({ label, value, icon: Icon }) => (
              <div
                key={label}
                className="rounded-2xl border border-[#e8edff] bg-[#fbfcff] p-4"
              >
                <div className="mb-3 flex h-10 w-10 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                  <Icon size={18} />
                </div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">
                  {label}
                </p>
                <p className="mt-2 text-sm font-semibold text-slate-800">{value}</p>
              </div>
            ))}
          </div>
        </section>
      </div>

      <section className="mt-6 rounded-3xl border border-[#dfe5ff] bg-white p-7 shadow-sm">
        <div className="mb-5 flex items-center gap-3">
          <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
            <WalletCards size={20} />
          </div>
          <div>
            <h4 className="text-lg font-semibold text-slate-900">{t('duesHistory')}</h4>
            <p className="text-sm text-slate-500">{t('duesHistoryNote')}</p>
          </div>
        </div>

        {loadingDues ? (
          <div className="space-y-3">
            <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
            <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
          </div>
        ) : duesHistory.length === 0 ? (
          <p className="text-sm text-slate-500">{t('noDuesOnFile')}</p>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="text-left text-xs uppercase tracking-[0.1em] text-slate-400">
                <tr>
                  <th className="py-2 pr-4 font-semibold">{t('table.year')}</th>
                  <th className="py-2 pr-4 font-semibold">{t('table.amountDue')}</th>
                  <th className="py-2 pr-4 font-semibold">{t('table.paid')}</th>
                  <th className="py-2 pr-4 font-semibold">{t('table.remaining')}</th>
                  <th className="py-2 pr-4 font-semibold">{t('table.status')}</th>
                </tr>
              </thead>
              <tbody>
                {duesHistory.map((due) => (
                  <tr key={due.id} className="border-t border-[#eef2ff]">
                    <td className="py-3 pr-4 font-semibold text-slate-800">{due.year}</td>
                    <td className="py-3 pr-4 text-slate-600">{formatCurrency(due.amount_due)}</td>
                    <td className="py-3 pr-4 text-slate-600">{formatCurrency(due.amount_paid)}</td>
                    <td className="py-3 pr-4 text-slate-600">{formatCurrency(due.balance)}</td>
                    <td className="py-3 pr-4">
                      <DueStatusBadge status={due.status} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </PresidentLayout>
  )
}
