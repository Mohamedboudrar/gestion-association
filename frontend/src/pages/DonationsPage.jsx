import { ExternalLink, Gift, Pencil, Trash2, Upload } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import PresidentLayout from '../components/layout/PresidentLayout'
import DonationStatusBadge from '../components/committee/DonationStatusBadge'
import DonationWorkflowActions from '../components/committee/DonationWorkflowActions'
import { formatCurrency } from '../components/committee/committeeUtils'
import {
  approveDonation,
  createDonation,
  deleteDonation,
  getDonations,
  rejectDonation,
  submitDonation,
  updateDonation,
  uploadDonationReceipt,
} from '../api/donations.api'
import { getMembers } from '../api/members.api'
import { getProjects } from '../api/projects.api'
import { useAuth } from '../context/auth-context'
import { canDeleteDonation, canEditDonation, canEnterDonation, canUploadReceipt, isDonationApprovalRole } from '../lib/roles'
import { getErrorMessage } from '../lib/apiErrors'
import { STATUS_STYLES } from '../lib/donationStatus'
import { useStatusLabel } from '../hooks/useStatusLabel'

const initialForm = {
  member_id: '',
  project_id: '',
  donor_name: '',
  amount: '',
  payment_method: '',
  receipt_number: '',
  donation_date: '',
  notes: '',
}

const FORM_FIELDS = ['amount', 'payment_method', 'receipt_number', 'donation_date']

export default function DonationsPage() {
  const { t } = useTranslation('finance')
  const statusLabel = useStatusLabel()
  const { user } = useAuth()
  const [donations, setDonations] = useState([])
  const [members, setMembers] = useState([])
  const [projects, setProjects] = useState([])
  const [form, setForm] = useState(initialForm)
  const [editingId, setEditingId] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [statusFilter, setStatusFilter] = useState('all')
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')

  const STATUS_FILTERS = [
    { value: 'all', label: t('donationsPage.statusFilters.all') },
    { value: 'draft', label: statusLabel('draft') },
    { value: 'pending', label: statusLabel('pending') },
    { value: 'approved', label: statusLabel('approved') },
    { value: 'rejected', label: statusLabel('rejected') },
  ]

  const eligibleProjects = projects.filter((project) => canEnterDonation(user, project))
  const canAddDonation = eligibleProjects.length > 0

  useEffect(() => {
    async function load() {
      setLoading(true)
      setLoadError('')

      try {
        await Promise.all([loadDonations(), loadMembers(), loadProjects()])
      } catch (error) {
        setLoadError(error.response?.data?.message ?? t('donationsPage.loadError'))
      } finally {
        setLoading(false)
      }
    }

    load()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function loadDonations() {
    const data = await getDonations()
    setDonations(data)
  }

  async function loadMembers() {
    const data = await getMembers()
    setMembers(data)
  }

  async function loadProjects() {
    const data = await getProjects()
    setProjects(data)
  }

  function handleChange(event) {
    const { name, value } = event.target
    setForm((current) => ({ ...current, [name]: value }))
  }

  function resetForm() {
    setForm(initialForm)
    setEditingId(null)
  }

  function startEdit(donation) {
    setEditingId(donation.id)
    setForm({
      member_id: donation.member?.id ? String(donation.member.id) : '',
      project_id: donation.project?.id ? String(donation.project.id) : '',
      donor_name: donation.member ? '' : donation.donor_name ?? '',
      amount: donation.amount ?? '',
      payment_method: donation.payment_method ?? '',
      receipt_number: donation.receipt_number ?? '',
      donation_date: donation.donation_date ?? '',
      notes: donation.notes ?? '',
    })
  }

  // A details-issue rejection is permanently read-only — the user can't edit
  // it, only create a brand-new donation. Prefills the create form (not edit
  // — editingId stays null) as a convenience; the old donation remains in
  // history untouched.
  function beginNewFromRejected(donation) {
    setEditingId(null)
    setForm({
      member_id: donation.member?.id ? String(donation.member.id) : '',
      project_id: donation.project?.id ? String(donation.project.id) : '',
      donor_name: donation.member ? '' : donation.donor_name ?? '',
      amount: donation.amount ?? '',
      payment_method: donation.payment_method ?? '',
      receipt_number: '',
      donation_date: donation.donation_date ?? '',
      notes: donation.notes ?? '',
    })
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setSubmitting(true)
    setErrorMessage('')

    try {
      const payload = {
        ...form,
        member_id: form.member_id || null,
        project_id: form.project_id || null,
        donor_name: form.donor_name || null,
        amount: Number(form.amount),
      }

      if (editingId) {
        await updateDonation(editingId, payload)
      } else {
        await createDonation(payload)
      }

      resetForm()
      await loadDonations()
    } catch (error) {
      setErrorMessage(
        getErrorMessage(error, t('donationsPage.saveError')),
      )
    } finally {
      setSubmitting(false)
    }
  }

  async function handleDelete(donationId) {
    if (!window.confirm(t('donationsPage.deleteConfirm'))) return

    setErrorMessage('')

    try {
      await deleteDonation(donationId)
      if (editingId === donationId) {
        resetForm()
      }
      await loadDonations()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('donationsPage.deleteError')))
    }
  }

  async function handleReceiptUpload(donationId, event) {
    const file = event.target.files?.[0]
    if (!file) return

    setErrorMessage('')

    try {
      await uploadDonationReceipt(donationId, file)
      await loadDonations()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('donationsPage.uploadReceiptError')))
    } finally {
      event.target.value = ''
    }
  }

  async function handleWorkflowSubmit(donationId) {
    setErrorMessage('')

    try {
      await submitDonation(donationId)
      await loadDonations()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('donationsPage.submitError')))
    }
  }

  async function handleApprove(donationId) {
    setErrorMessage('')

    try {
      await approveDonation(donationId)
      await loadDonations()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('donationsPage.approveError')))
    }
  }

  async function handleReject(donationId, reason, rejectionType) {
    setErrorMessage('')

    try {
      await rejectDonation(donationId, reason, rejectionType)
      await loadDonations()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('donationsPage.rejectError')))
      throw error
    }
  }

  // Scoped the same way ExpensesPage's status counts are — recomputed from
  // the already-loaded list, no extra request.
  const statusCounts = useMemo(() => {
    const counts = { all: donations.length, draft: 0, pending: 0, approved: 0, rejected: 0 }

    donations.forEach((donation) => {
      if (counts[donation.status] !== undefined) {
        counts[donation.status] += 1
      }
    })

    return counts
  }, [donations])

  const filteredDonations = useMemo(() => {
    if (statusFilter === 'all') return donations
    return donations.filter((donation) => donation.status === statusFilter)
  }, [donations, statusFilter])

  // Donations is the association-wide, cross-project ledger — donor names,
  // amounts, receipts. President/treasurer/vice-treasurer manage it here;
  // everyone else (including committee leaders/treasurers, who manage their
  // own project's donations from that project's own Donations tab instead)
  // is redirected away. No "personal donations" view exists for subscribers.
  if (!isDonationApprovalRole(user)) {
    return <Navigate to="/dashboard" replace />
  }

  return (
    <PresidentLayout
      title={t('donationsPage.title')}
      description={t('donationsPage.description')}
      breadcrumbs={['Donations']}
    >
      {loadError ? (
        <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {loadError}
        </div>
      ) : null}

      <div className={canAddDonation ? 'grid grid-cols-1 gap-6 xl:grid-cols-[420px_minmax(0,1fr)]' : ''}>
        {canAddDonation ? (
        <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="mb-5 flex items-center gap-3">
            <div className="flex h-11 w-11 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
              <Gift size={20} />
            </div>
            <div>
              <h3 className="text-lg font-semibold text-slate-900">
                {editingId ? t('donationsPage.editTitle') : t('donationsPage.createTitle')}
              </h3>
              <p className="text-sm text-slate-500">
                {editingId
                  ? t('donationsPage.editSubtitle')
                  : t('donationsPage.createSubtitle')}
              </p>
            </div>
          </div>

          <form className="space-y-4" onSubmit={handleSubmit}>
            {errorMessage ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-600">
                {errorMessage}
              </div>
            ) : null}

            <select
              name="member_id"
              value={form.member_id}
              onChange={handleChange}
              className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
            >
              <option value="">{t('donationsPage.selectMemberDonor')}</option>
              {members.map((member) => (
                <option key={member.id} value={member.id}>
                  {member.user.name}
                </option>
              ))}
            </select>

            <input
              type="text"
              name="donor_name"
              placeholder={t('donationsPage.externalDonorName')}
              value={form.donor_name}
              onChange={handleChange}
              className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
            />

            <select
              name="project_id"
              value={form.project_id}
              onChange={handleChange}
              className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
            >
              <option value="">{t('donationsPage.selectProject')}</option>
              {eligibleProjects.map((project) => (
                <option key={project.id} value={project.id}>
                  {project.name}
                </option>
              ))}
            </select>

            {FORM_FIELDS.map((field) => (
              <input
                key={field}
                type={field === 'amount' ? 'number' : field.includes('date') ? 'date' : 'text'}
                name={field}
                placeholder={t(`donationsPage.fields.${field}`)}
                value={form[field]}
                onChange={handleChange}
                className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 text-sm outline-none"
              />
            ))}

            <textarea
              name="notes"
              value={form.notes}
              onChange={handleChange}
              placeholder={t('donationsPage.notes')}
              className="min-h-[96px] w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 py-3 text-sm outline-none"
            />

            <div className="flex gap-3">
              <button
                type="submit"
                disabled={submitting}
                className="flex-1 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white"
              >
                {submitting ? t('donationsPage.saving') : editingId ? t('donationsPage.updateDonation') : t('donationsPage.saveDonation')}
              </button>

              {editingId ? (
                <button
                  type="button"
                  onClick={resetForm}
                  className="rounded-2xl border border-[#dfe5ff] px-4 py-3 text-sm font-semibold text-slate-600"
                >
                  {t('donationsPage.cancel')}
                </button>
              ) : null}
            </div>
          </form>
        </section>
        ) : null}

        <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <div className="flex flex-col gap-4 border-b border-[#eef2ff] px-6 py-5 md:flex-row md:items-center md:justify-between">
            <div>
              <h3 className="text-lg font-semibold text-slate-900">{t('donationsPage.ledgerTitle')}</h3>
              <p className="text-sm text-slate-500">{t('donationsPage.recordCount', { count: filteredDonations.length })}</p>
            </div>

            <div className="flex flex-wrap gap-2">
              {STATUS_FILTERS.map(({ value, label }) => (
                <button
                  key={value}
                  type="button"
                  onClick={() => setStatusFilter(value)}
                  className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold transition ${
                    value === 'all' ? 'bg-slate-100 text-slate-600' : STATUS_STYLES[value]
                  } ${
                    statusFilter === value
                      ? 'ring-2 ring-offset-1 ring-blue-400'
                      : 'opacity-60 hover:opacity-100'
                  }`}
                >
                  {label}
                  <span className="rounded-full bg-white/70 px-1.5 py-0.5 text-[10px]">
                    {statusCounts[value] ?? 0}
                  </span>
                </button>
              ))}
            </div>
          </div>

          <div className="divide-y divide-[#eef2ff]">
            {loading ? (
              <div className="space-y-3 px-6 py-8">
                <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
                <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
              </div>
            ) : (
            <>
            {filteredDonations.map((donation) => {
              // donation.project only carries {id, name} — resolve the full
              // project (with its members/committee_role pivot) to check role.
              const fullProject = projects.find((project) => project.id === donation.project?.id)

              return (
              <div key={donation.id} className="flex items-center justify-between gap-4 px-6 py-4">
                <div>
                  <div className="flex flex-wrap items-center gap-2">
                    <p className="font-semibold text-slate-800">{donation.donor_name}</p>
                    <DonationStatusBadge status={donation.status} />
                    {donation.project ? (
                      <span className="rounded-full bg-[#f7f9ff] px-3 py-1 text-xs font-semibold text-slate-600">
                        {donation.project.name}
                      </span>
                    ) : null}
                  </div>

                  <p className="text-sm text-slate-500">
                    {formatCurrency(donation.amount)} • {donation.payment_method}
                  </p>

                  <p className="text-xs text-slate-400">
                    {donation.donation_date} • {t('donationsPage.receiptLabel', { number: donation.receipt_number || t('donationsPage.receiptNotProvided') })}
                  </p>

                  {donation.notes ? (
                    <p className="mt-1 text-xs text-slate-500">{donation.notes}</p>
                  ) : null}

                  {donation.status === 'rejected' && donation.rejection_reason ? (
                    <p className="mt-1 text-sm text-rose-600">{t('donationsPage.rejectedLabel', { reason: donation.rejection_reason })}</p>
                  ) : null}

                  <div className="mt-3">
                    <DonationWorkflowActions
                      user={user}
                      donation={donation}
                      project={fullProject}
                      committeeScope={fullProject}
                      onSubmit={handleWorkflowSubmit}
                      onApprove={handleApprove}
                      onReject={handleReject}
                    />
                  </div>
                </div>

                <div className="flex flex-col items-end gap-2">
                  <div className="flex items-center gap-2">
                    {donation.receipt_url ? (
                      <a
                        href={donation.receipt_url}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-flex items-center gap-1 rounded-xl border border-blue-200 px-3 py-2 text-sm font-medium text-blue-600 transition hover:bg-blue-50"
                      >
                        <ExternalLink size={14} />
                        {t('donationsPage.receipt')}
                      </a>
                    ) : null}

                    {canUploadReceipt(user, fullProject, donation) ? (
                      <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-blue-200 px-3 py-2 text-sm font-medium text-blue-600 transition hover:bg-blue-50">
                        <Upload size={14} />
                        {t('donationsPage.upload')}
                        <input
                          type="file"
                          accept=".pdf,.jpg,.jpeg,.png"
                          className="hidden"
                          onChange={(event) => handleReceiptUpload(donation.id, event)}
                        />
                      </label>
                    ) : null}
                  </div>

                  <div className="flex items-center gap-2">
                    {canEditDonation(user, fullProject, donation) ? (
                      <button
                        type="button"
                        onClick={() => startEdit(donation)}
                        className="rounded-xl border border-[#dfe5ff] px-3 py-2 text-slate-600 transition hover:bg-slate-50"
                      >
                        <Pencil size={14} />
                      </button>
                    ) : null}

                    {canDeleteDonation(user, fullProject, donation) ? (
                      <button
                        type="button"
                        onClick={() => handleDelete(donation.id)}
                        className="rounded-xl border border-rose-200 px-3 py-2 text-rose-500 transition hover:bg-rose-50"
                      >
                        <Trash2 size={14} />
                      </button>
                    ) : null}
                  </div>

                  {canEnterDonation(user, fullProject) &&
                  donation.status === 'rejected' &&
                  donation.rejection_type === 'details' ? (
                    <button
                      type="button"
                      onClick={() => beginNewFromRejected(donation)}
                      className="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
                    >
                      {t('donationsPage.createNewDonation')}
                    </button>
                  ) : null}
                </div>
              </div>
              )
            })}

            {!filteredDonations.length ? (
              <div className="px-6 py-10 text-center text-sm text-slate-500">
                {donations.length === 0 ? t('donationsPage.emptyNoDonations') : t('donationsPage.emptyNoMatch')}
              </div>
            ) : null}
            </>
            )}
          </div>
        </section>
      </div>
    </PresidentLayout>
  )
}
