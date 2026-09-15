import {
  ChevronLeft,
  ChevronRight,
  FileBarChart,
  FileDown,
  FileUp,
  GitBranch,
  HandCoins,
  Lock,
  ReceiptText,
  Send,
  Trash2,
  Upload,
  UsersRound,
  X,
} from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
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
import {
  approveExpense,
  createExpense,
  deleteExpense,
  getExpenses,
  importExpenses,
  markExpensePaid,
  rejectExpense,
  submitExpense,
  updateExpense,
  uploadExpenseInvoice,
} from '../api/expenses.api'
import { createPhaseRequest, getPhaseRequests } from '../api/phaseRequests.api'
import DonationStatusBadge from '../components/committee/DonationStatusBadge'
import DonationWorkflowActions from '../components/committee/DonationWorkflowActions'
import ExpenseFormFields from '../components/committee/ExpenseFormFields'
import ExpenseStatusBadge from '../components/committee/ExpenseStatusBadge'
import ExpenseWorkflowActions from '../components/committee/ExpenseWorkflowActions'
import {
  buildExpenseTemplateCsv,
  buildExpenseTemplateXlsxBuffer,
  downloadBlob,
  isExpenseDraftValid,
  parseExpenseImportFile,
  validateExpenseDraft,
} from '../lib/expenseImport'
import { getProjectMembers } from '../api/projectMembers.api'
import {
  closeProject,
  deleteProject,
  getProject,
  requestProjectDeletion,
  startProject,
  updateProjectLocation,
} from '../api/projects.api'
import TomTomMapPicker from '../components/maps/TomTomMapPicker'
import TomTomMapViewer from '../components/maps/TomTomMapViewer'
import { getProjectAllocations } from '../api/projectAllocations.api'
import { getProjectReports } from '../api/projectReports.api'
import { downloadReportBlob } from '../api/reports.api'
import PresidentLayout from '../components/layout/PresidentLayout'
import { useAuth } from '../context/auth-context'
import {
  canDeleteDonation,
  canDeleteExpense,
  canDeleteProjectDirectly,
  canEditDonation,
  canEditExpense,
  canEditProjectLocation,
  canEnterDonation,
  canEnterExpense,
  canGenerateProjectReport,
  canRequestProjectDeletion,
  canRequestProjectPhase,
  canStartProject,
  canSubmitExpense,
  canUploadInvoice,
  canUploadReceipt,
  isBureauMember,
  isProjectLocked,
} from '../lib/roles'
import ReasonModal from '../components/committee/ReasonModal'
import { isProjectActive, stageLabel } from '../lib/projectLifecycle'
import { getErrorMessage } from '../lib/apiErrors'
import { nextRequestablePhase, phaseLabel, phaseStyle, PHASE_ORDER } from '../lib/projectPhase'
import {
  committeeRoleLabel,
  formatCurrency,
  formatDate,
  getStatusTone,
} from '../components/committee/committeeUtils'

const initialDonationForm = {
  donor_name: '',
  amount: '',
  payment_method: '',
  receipt_number: '',
  donation_date: '',
  notes: '',
}

const initialExpenseForm = {
  supplier_name: '',
  description: '',
  amount: '',
  payment_method: '',
  invoice_number: '',
  expense_date: '',
  notes: '',
}

const initialPhaseRequestForm = {
  summary: '',
  notes: '',
}

const initialLocation = { latitude: null, longitude: null }
const DONATION_FORM_FIELDS = ['amount', 'payment_method', 'receipt_number', 'donation_date']

function MetricCard({ label, value, accent = 'text-slate-900' }) {
  const valueStr = String(value ?? '')
  const isLongValue = valueStr.length > 12

  return (
    <div className="flex min-w-[160px] flex-1 flex-col rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-4">
      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{label}</p>
      <p
        className={`mt-2 whitespace-nowrap font-semibold ${accent} ${isLongValue ? 'text-lg' : 'text-xl'}`}
        title={typeof value === 'string' ? value : undefined}
      >
        {value}
      </p>
    </div>
  )
}

function EmptyPanel({ title, description }) {
  return (
    <div className="rounded-2xl border border-dashed border-[#dfe5ff] bg-[#f7f9ff] px-5 py-8 text-sm text-slate-500">
      <p className="font-semibold text-slate-700">{title}</p>
      <p className="mt-2">{description}</p>
    </div>
  )
}

export default function CommitteeProjectPage() {
  const { t } = useTranslation(['association', 'common'])
  const { projectId } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const [project, setProject] = useState(null)
  const [members, setMembers] = useState([])
  const [donations, setDonations] = useState([])
  const [expenses, setExpenses] = useState([])
  const [allocations, setAllocations] = useState([])
  const [reports, setReports] = useState([])
  const [phaseRequests, setPhaseRequests] = useState([])
  const [closing, setClosing] = useState(false)
  const [starting, setStarting] = useState(false)
  const [deletingProject, setDeletingProject] = useState(false)
  const [showDeletionRequestModal, setShowDeletionRequestModal] = useState(false)
  const [submittingDeletionRequest, setSubmittingDeletionRequest] = useState(false)
  const [showPhaseRequestForm, setShowPhaseRequestForm] = useState(false)
  const [phaseRequestForm, setPhaseRequestForm] = useState(initialPhaseRequestForm)
  const [phaseRequestFiles, setPhaseRequestFiles] = useState([])
  const [savingPhaseRequest, setSavingPhaseRequest] = useState(false)
  const [phaseRequestError, setPhaseRequestError] = useState('')
  const [editingLocation, setEditingLocation] = useState(false)
  const [pendingLocation, setPendingLocation] = useState(initialLocation)
  const [savingLocation, setSavingLocation] = useState(false)
  const [locationError, setLocationError] = useState('')
  const [loading, setLoading] = useState(true)
  const [accessDenied, setAccessDenied] = useState(false)
  const [errorMessage, setErrorMessage] = useState('')
  const [activeTab, setActiveTab] = useState('overview')
  const [donationForm, setDonationForm] = useState(initialDonationForm)
  const [editingDonationId, setEditingDonationId] = useState(null)
  const [savingDonation, setSavingDonation] = useState(false)
  const [expenseForm, setExpenseForm] = useState(initialExpenseForm)
  const [editingExpenseId, setEditingExpenseId] = useState(null)
  const [savingExpense, setSavingExpense] = useState(false)
  const [importDrafts, setImportDrafts] = useState([])
  const [importIndex, setImportIndex] = useState(0)
  const [importError, setImportError] = useState('')
  const [savingImport, setSavingImport] = useState(false)
  const [selectedExpenseIds, setSelectedExpenseIds] = useState([])
  const [deletingSelectedExpenses, setDeletingSelectedExpenses] = useState(false)
  const [submittingSelectedExpenses, setSubmittingSelectedExpenses] = useState(false)

  const STAGE_GUIDANCE = {
    draft: t('committeeProject.stageGuidance.draft'),
    committee_ready: t('committeeProject.stageGuidance.committee_ready'),
    funding_ready: t('committeeProject.stageGuidance.funding_ready'),
  }

  useEffect(() => {
    let cancelled = false

    async function load() {
      setLoading(true)
      setErrorMessage('')
      setAccessDenied(false)

      try {
        // getProject is the real access gate (ProjectPolicy::view, backed by
        // AuthorizationHelper::canAccessProject) — it covers both committee-
        // assigned members and any bureau role (never a blanket permission).
        // Donations/expenses can 403 independently (e.g. a bureau
        // viewer with no project assignment anywhere yet) without blocking
        // the rest of the workspace.
        const [projectData, memberData, donationData, expenseData, allocationData, reportData, phaseRequestData] =
          await Promise.all([
            getProject(projectId),
            getProjectMembers(projectId).catch(() => []),
            getDonations({ project_id: projectId }).catch(() => []),
            getExpenses({ project_id: projectId }).catch(() => []),
            getProjectAllocations(projectId).catch(() => []),
            getProjectReports(projectId).catch(() => []),
            getPhaseRequests(projectId).catch(() => []),
          ])

        if (cancelled) return

        setProject(projectData)
        setMembers(memberData)
        setDonations(donationData ?? [])
        setExpenses(expenseData ?? [])
        setAllocations(allocationData ?? [])
        setReports(reportData ?? [])
        setPhaseRequests(phaseRequestData ?? [])
      } catch (error) {
        if (cancelled) return

        if (error.response?.status === 403 || error.response?.status === 404) {
          setAccessDenied(true)
        } else {
          setErrorMessage(
            error.response?.data?.message ?? t('committeeProject.loadError'),
          )
        }
      } finally {
        if (!cancelled) {
          setLoading(false)
        }
      }
    }

    load()

    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [projectId])

  const collected = Number(project?.collected ?? 0)
  const budget = Number(project?.budget ?? 0)
  const expensesTotal = Number(project?.expenses ?? 0)
  const remaining = Number(project?.remaining ?? 0)

  async function refreshFinancials() {
    const [donationData, expenseData, projectData] = await Promise.all([
      getDonations({ project_id: projectId }),
      getExpenses({ project_id: projectId }),
      getProject(projectId),
    ])
    setDonations(donationData ?? [])
    setExpenses(expenseData ?? [])
    setProject(projectData)
  }

  const committeeRole = members.find((member) => member.user?.id === user?.id)?.pivot?.role
  const committeeScope = { members }
  const locked = isProjectLocked(project)

  // Itemized donation records (donor names, receipts, individual amounts) are
  // only for whoever actually manages this project's finances — a committee
  // leader/treasurer, or bureau. A plain committee member/subscriber only
  // gets the aggregate totals already shown on the Overview tab (Budget/
  // Collected/Expenses/Remaining, from the same backend-computed figures).
  const canViewDonationDetails = canEnterDonation(user, committeeScope) || isBureauMember(user)

  const tabs = [
    ['overview', t('committeeProject.tabs.overview'), UsersRound],
    ['phases', t('committeeProject.tabs.phases'), GitBranch],
    ...(canViewDonationDetails ? [['donations', t('committeeProject.tabs.donations'), HandCoins]] : []),
    ['expenses', t('committeeProject.tabs.expenses'), ReceiptText],
    ['reports', t('committeeProject.tabs.reports'), FileBarChart],
  ]

  function updateDonationForm(event) {
    const { name, value } = event.target
    setDonationForm((current) => ({ ...current, [name]: value }))
  }

  function beginDonationEdit(donation) {
    setEditingDonationId(donation.id)
    setDonationForm({
      donor_name: donation.member ? '' : donation.donor_name ?? '',
      amount: donation.amount ?? '',
      payment_method: donation.payment_method ?? '',
      receipt_number: donation.receipt_number ?? '',
      donation_date: donation.donation_date ?? '',
      notes: donation.notes ?? '',
    })
    setActiveTab('donations')
  }

  function resetDonationForm() {
    setEditingDonationId(null)
    setDonationForm(initialDonationForm)
  }

  // A details-issue rejection is permanently read-only — the leader can't
  // edit it, only create a brand-new donation. Prefills the create form (not
  // edit — editingDonationId stays null) as a convenience; the old donation
  // remains in history untouched.
  function beginNewDonationFromRejected(donation) {
    setEditingDonationId(null)
    setDonationForm({
      donor_name: donation.member ? '' : donation.donor_name ?? '',
      amount: donation.amount ?? '',
      payment_method: donation.payment_method ?? '',
      receipt_number: '',
      donation_date: donation.donation_date ?? '',
      notes: donation.notes ?? '',
    })
    setActiveTab('donations')
  }

  async function handleDonationSubmit(event) {
    event.preventDefault()
    setSavingDonation(true)
    setErrorMessage('')

    try {
      const payload = {
        ...donationForm,
        donor_name: donationForm.donor_name || null,
        project_id: Number(projectId),
        amount: Number(donationForm.amount),
      }

      if (editingDonationId) {
        await updateDonation(editingDonationId, payload)
      } else {
        await createDonation(payload)
      }

      await refreshFinancials()
      resetDonationForm()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('committeeProject.donations.saveError')))
    } finally {
      setSavingDonation(false)
    }
  }

  async function handleDonationReceiptUpload(donationId, event) {
    const file = event.target.files?.[0]
    if (!file) return

    setErrorMessage('')

    try {
      await uploadDonationReceipt(donationId, file)
      const refreshed = await getDonations({ project_id: projectId })
      setDonations(refreshed ?? [])
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.donations.uploadReceiptError'),
      )
    } finally {
      event.target.value = ''
    }
  }

  async function handleDonationDelete(donationId) {
    if (!window.confirm(t('committeeProject.donations.deleteConfirm'))) return

    setErrorMessage('')

    try {
      await deleteDonation(donationId)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.donations.deleteError'),
      )
    }
  }

  async function handleDonationWorkflowSubmit(donationId) {
    setErrorMessage('')

    try {
      await submitDonation(donationId)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.donations.submitError'),
      )
    }
  }

  async function handleDonationApprove(donationId) {
    setErrorMessage('')

    try {
      await approveDonation(donationId)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.donations.approveError'),
      )
    }
  }

  async function handleDonationReject(donationId, reason, rejectionType) {
    setErrorMessage('')

    try {
      await rejectDonation(donationId, reason, rejectionType)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.donations.rejectError'),
      )
      throw error
    }
  }

  function updateExpenseForm(event) {
    const { name, value } = event.target
    setExpenseForm((current) => ({ ...current, [name]: value }))
  }

  function beginExpenseEdit(expense) {
    setEditingExpenseId(expense.id)
    setExpenseForm({
      supplier_name: expense.supplier_name ?? '',
      description: expense.description ?? '',
      amount: expense.amount ?? '',
      payment_method: expense.payment_method ?? '',
      invoice_number: expense.invoice_number ?? '',
      expense_date: expense.expense_date ?? '',
      notes: expense.notes ?? '',
    })
    setActiveTab('expenses')
  }

  function resetExpenseForm() {
    setEditingExpenseId(null)
    setExpenseForm(initialExpenseForm)
  }

  // A details-issue rejection is permanently read-only — the leader can't
  // edit it, only create a brand-new expense. Prefills the create form (not
  // edit — editingExpenseId stays null) as a convenience; the old expense
  // remains in history untouched.
  function beginNewExpenseFromRejected(expense) {
    setEditingExpenseId(null)
    setExpenseForm({
      supplier_name: expense.supplier_name ?? '',
      description: expense.description ?? '',
      amount: expense.amount ?? '',
      payment_method: expense.payment_method ?? '',
      invoice_number: '',
      expense_date: expense.expense_date ?? '',
      notes: expense.notes ?? '',
    })
    setActiveTab('expenses')
  }

  async function handleExpenseSubmit(event) {
    event.preventDefault()
    setSavingExpense(true)
    setErrorMessage('')

    try {
      const payload = {
        ...expenseForm,
        project_id: Number(projectId),
        amount: Number(expenseForm.amount),
      }

      if (editingExpenseId) {
        await updateExpense(editingExpenseId, payload)
      } else {
        await createExpense(payload)
      }

      await refreshFinancials()
      resetExpenseForm()
    } catch (error) {
      setErrorMessage(getErrorMessage(error, t('committeeProject.expenses.saveError')))
    } finally {
      setSavingExpense(false)
    }
  }

  async function handleExpenseInvoiceUpload(expenseId, event) {
    const file = event.target.files?.[0]
    if (!file) return

    setErrorMessage('')

    try {
      await uploadExpenseInvoice(expenseId, file)
      const refreshed = await getExpenses({ project_id: projectId })
      setExpenses(refreshed ?? [])
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.expenses.uploadInvoiceError'),
      )
    } finally {
      event.target.value = ''
    }
  }

  async function handleExpenseDelete(expenseId) {
    if (!window.confirm(t('committeeProject.expenses.deleteConfirm'))) return

    setErrorMessage('')

    try {
      await deleteExpense(expenseId)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.expenses.deleteError'),
      )
    }
  }

  function toggleExpenseSelected(expenseId) {
    setSelectedExpenseIds((current) =>
      current.includes(expenseId) ? current.filter((id) => id !== expenseId) : [...current, expenseId],
    )
  }

  function toggleSelectAllExpenses(deletableExpenseIds) {
    const allSelected = deletableExpenseIds.length > 0 && deletableExpenseIds.every((id) => selectedExpenseIds.includes(id))
    setSelectedExpenseIds(allSelected ? [] : deletableExpenseIds)
  }

  // Deletes are independent of each other (unlike the import's budget check,
  // there's no shared invariant across the batch) — Promise.allSettled so one
  // failure doesn't block the rest, then whatever failed stays selected.
  async function handleBulkDeleteExpenses() {
    const count = selectedExpenseIds.length
    if (count === 0) return
    if (!window.confirm(t('committeeProject.expenses.bulkDeleteConfirm', { count }))) return

    setDeletingSelectedExpenses(true)
    setErrorMessage('')

    const results = await Promise.allSettled(selectedExpenseIds.map((id) => deleteExpense(id)))
    const failedIds = selectedExpenseIds.filter((_, index) => results[index].status === 'rejected')

    if (failedIds.length > 0) {
      setErrorMessage(t('committeeProject.expenses.bulkDeletePartial', { done: count - failedIds.length, count, failed: failedIds.length }))
    }

    setSelectedExpenseIds(failedIds)
    await refreshFinancials()
    setDeletingSelectedExpenses(false)
  }

  // The selection checkbox column is shared with bulk delete, so it can include
  // expenses that aren't actually submittable (e.g. already-pending ones) —
  // narrow down to the submittable subset (draft, or rejected for an invoice
  // issue) before submitting, same as canSubmitExpense gates the single-row
  // "Submit"/"Submit Again" button.
  async function handleBulkSubmitExpenses() {
    const submittableIds = selectedExpenseIds.filter((id) => {
      const expense = expenses.find((e) => e.id === id)
      return expense && canSubmitExpense(user, committeeScope, expense)
    })
    const count = submittableIds.length
    if (count === 0) return
    if (!window.confirm(t('committeeProject.expenses.bulkSubmitConfirm', { count }))) return

    setSubmittingSelectedExpenses(true)
    setErrorMessage('')

    const results = await Promise.allSettled(submittableIds.map((id) => submitExpense(id)))
    const failedIds = submittableIds.filter((_, index) => results[index].status === 'rejected')

    if (failedIds.length > 0) {
      setErrorMessage(t('committeeProject.expenses.bulkSubmitPartial', { done: count - failedIds.length, count, failed: failedIds.length }))
    }

    setSelectedExpenseIds((current) => current.filter((id) => !submittableIds.includes(id) || failedIds.includes(id)))
    await refreshFinancials()
    setSubmittingSelectedExpenses(false)
  }

  async function handleExpenseWorkflowSubmit(expenseId) {
    setErrorMessage('')

    try {
      await submitExpense(expenseId)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.expenses.submitError'),
      )
    }
  }

  async function handleExpenseApprove(expenseId) {
    setErrorMessage('')

    try {
      await approveExpense(expenseId)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.expenses.approveError'),
      )
    }
  }

  async function handleExpenseReject(expenseId, reason, rejectionType) {
    setErrorMessage('')

    try {
      await rejectExpense(expenseId, reason, rejectionType)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.expenses.rejectError'),
      )
      throw error
    }
  }

  async function handleExpenseMarkPaid(expenseId) {
    setErrorMessage('')

    try {
      await markExpensePaid(expenseId)
      await refreshFinancials()
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.expenses.markPaidError'),
      )
    }
  }

  async function handleImportFileChange(event) {
    const file = event.target.files?.[0]
    if (!file) return

    setImportError('')

    try {
      const drafts = await parseExpenseImportFile(file)

      if (drafts.length === 0) {
        setImportError(t('committeeProject.expenses.noDataRowsError'))
        return
      }

      setImportDrafts(drafts)
      setImportIndex(0)
    } catch (error) {
      setImportError(error.message ?? t('committeeProject.expenses.parseFileError'))
    } finally {
      event.target.value = ''
    }
  }

  function updateImportDraftField(event) {
    const { name, value } = event.target
    setImportDrafts((current) =>
      current.map((draft, index) => (index === importIndex ? { ...draft, [name]: value } : draft)),
    )
  }

  function goToPreviousDraft() {
    setImportIndex((current) => Math.max(0, current - 1))
  }

  function goToNextDraft() {
    setImportIndex((current) => Math.min(importDrafts.length - 1, current + 1))
  }

  function handleCancelImport() {
    setImportDrafts([])
    setImportIndex(0)
    setImportError('')
  }

  // Atomic — the backend validates the whole batch's cumulative budget
  // impact before creating anything, so either every draft is imported or
  // none are (no partial imports, no per-row retry-from-here logic needed).
  async function handleSaveAllImports() {
    if (!importDrafts.every(isExpenseDraftValid)) return

    setSavingImport(true)
    setImportError('')

    try {
      await importExpenses(
        Number(projectId),
        importDrafts.map((draft) => ({ ...draft, amount: Number(draft.amount) })),
      )

      setImportDrafts([])
      setImportIndex(0)
      await refreshFinancials()
    } catch (error) {
      const budgetInfo = error.response?.data?.budget

      setImportError(
        budgetInfo
          ? t('committeeProject.expenses.importBudgetError', {
              message: error.response.data.message,
              projectBudget: formatCurrency(budgetInfo.project_budget),
              alreadyAllocated: formatCurrency(budgetInfo.already_allocated),
              remainingBudget: formatCurrency(budgetInfo.remaining_budget),
              newTotal: formatCurrency(budgetInfo.new_total),
              exceededBy: formatCurrency(budgetInfo.exceeded_by),
            })
          : (error.response?.data?.message ?? t('committeeProject.expenses.importGenericError')),
      )
    } finally {
      setSavingImport(false)
    }
  }

  async function handleDownloadTemplate(format) {
    if (format === 'csv') {
      downloadBlob(new Blob([buildExpenseTemplateCsv()], { type: 'text/csv' }), 'expense-import-template.csv')
      return
    }

    const buffer = await buildExpenseTemplateXlsxBuffer()
    downloadBlob(
      new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }),
      'expense-import-template.xlsx',
    )
  }

  function openPhaseRequestForm() {
    setPhaseRequestForm(initialPhaseRequestForm)
    setPhaseRequestFiles([])
    setPhaseRequestError('')
    setShowPhaseRequestForm(true)
  }

  function cancelPhaseRequestForm() {
    setShowPhaseRequestForm(false)
    setPhaseRequestForm(initialPhaseRequestForm)
    setPhaseRequestFiles([])
    setPhaseRequestError('')
  }

  function updatePhaseRequestForm(event) {
    const { name, value } = event.target
    setPhaseRequestForm((current) => ({ ...current, [name]: value }))
  }

  function updatePhaseRequestFiles(event) {
    setPhaseRequestFiles(Array.from(event.target.files ?? []))
  }

  async function handlePhaseRequestSubmit(event) {
    event.preventDefault()
    setPhaseRequestError('')

    const targetPhase = nextRequestablePhase(project?.phase)

    if (!targetPhase) {
      setPhaseRequestError(t('committeeProject.phases.noNextPhaseError'))
      return
    }

    if (!phaseRequestForm.summary.trim()) {
      setPhaseRequestError(t('committeeProject.phases.summaryRequiredError'))
      return
    }

    setSavingPhaseRequest(true)

    try {
      const formData = new FormData()
      formData.append('to_phase', targetPhase)
      formData.append('summary', phaseRequestForm.summary)
      if (phaseRequestForm.notes) formData.append('notes', phaseRequestForm.notes)
      phaseRequestFiles.forEach((file) => formData.append('proofs[]', file))

      await createPhaseRequest(projectId, formData)

      const [projectData, phaseRequestData] = await Promise.all([
        getProject(projectId),
        getPhaseRequests(projectId),
      ])
      setProject(projectData)
      setPhaseRequests(phaseRequestData ?? [])
      cancelPhaseRequestForm()
    } catch (error) {
      setPhaseRequestError(getErrorMessage(error, t('committeeProject.phases.submitError')))
    } finally {
      setSavingPhaseRequest(false)
    }
  }

  function openLocationEditor() {
    setPendingLocation({ latitude: project?.latitude ?? null, longitude: project?.longitude ?? null })
    setLocationError('')
    setEditingLocation(true)
  }

  function cancelLocationEditor() {
    setEditingLocation(false)
    setPendingLocation(initialLocation)
    setLocationError('')
  }

  async function handleSaveLocation() {
    setSavingLocation(true)
    setLocationError('')

    try {
      const updated = await updateProjectLocation(projectId, pendingLocation.latitude, pendingLocation.longitude)
      setProject(updated)
      setEditingLocation(false)
    } catch (error) {
      setLocationError(error.response?.data?.message ?? t('committeeProject.overview.locationSaveError'))
    } finally {
      setSavingLocation(false)
    }
  }

  async function handleStartProject() {
    setStarting(true)
    setErrorMessage('')

    try {
      const response = await startProject(projectId)
      setProject(response.data ?? response)
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.startError'),
      )
    } finally {
      setStarting(false)
    }
  }

  async function handleCloseProject() {
    if (!window.confirm(t('committeeProject.closeConfirm'))) return

    setClosing(true)
    setErrorMessage('')

    try {
      const response = await closeProject(projectId)
      setProject(response.data ?? response)
      if (response.report) {
        setReports((current) => [response.report, ...current])
      }
      setActiveTab('reports')
    } catch (error) {
      setErrorMessage(
        error.response?.data?.message ?? t('committeeProject.closeError'),
      )
    } finally {
      setClosing(false)
    }
  }

  async function handleDeleteProject() {
    if (!window.confirm(t('committeeProject.deleteConfirm'))) return

    setDeletingProject(true)
    setErrorMessage('')

    try {
      await deleteProject(projectId)
      navigate('/projects')
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('committeeProject.deleteError'))
      setDeletingProject(false)
    }
  }

  async function handleRequestDeletion(reason) {
    setSubmittingDeletionRequest(true)
    setErrorMessage('')

    try {
      await requestProjectDeletion(projectId, reason)
      setShowDeletionRequestModal(false)
      setProject(await getProject(projectId))
    } catch (error) {
      setErrorMessage(error.response?.data?.message ?? t('committeeProject.deletionRequestModal.submitError'))
    } finally {
      setSubmittingDeletionRequest(false)
    }
  }

  async function handleDownloadReport(report) {
    setErrorMessage('')

    try {
      const blob = await downloadReportBlob(report.download_path)
      const url = URL.createObjectURL(blob)
      window.open(url, '_blank')
    } catch (error) {
      setErrorMessage(error.message ?? t('committeeProject.reports.downloadError'))
    }
  }

  if (accessDenied) {
    return (
      <PresidentLayout
        title={t('committeeProject.accessDeniedTitle')}
        description={t('committeeProject.accessDeniedDescription')}
        breadcrumbs={[t('common:nav.projects'), t('committeeProject.accessDeniedTitle')]}
      >
        <div className="flex flex-col items-center gap-3 rounded-3xl border border-[#dfe5ff] bg-white px-6 py-16 text-center shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
          <Lock className="text-slate-300" size={32} />
          <h2 className="text-lg font-semibold text-slate-900">{t('committeeProject.accessDeniedTitle')}</h2>
          <p className="max-w-md text-sm text-slate-500">
            {t('committeeProject.accessDeniedBody')}
          </p>
          <Link
            to="/projects"
            className="mt-2 rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
          >
            {t('committeeProject.backToProjects')}
          </Link>
        </div>
      </PresidentLayout>
    )
  }

  const canClose = canGenerateProjectReport(user, committeeScope) && !locked && isProjectActive(project)
  const canStart = canStartProject(user, project)
  const canDelete = canDeleteProjectDirectly(user, project)
  const canRequestDeletion = canRequestProjectDeletion(user, project)

  return (
    <PresidentLayout
      title={project?.name ?? t('committeeProject.projectWorkspaceFallback')}
      description={t('committeeProject.headerDescription')}
      breadcrumbs={[t('common:nav.projects'), project?.name ?? t('committeeProject.projectWorkspaceFallback')]}
      headerActions={
        project ? (
          <>
            {canStart ? (
              <button
                type="button"
                onClick={handleStartProject}
                disabled={starting}
                className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-transparent bg-emerald-600 px-4 text-sm font-semibold text-white transition hover:bg-emerald-700 disabled:opacity-60"
              >
                {starting ? t('committeeProject.starting') : t('committeeProject.startProject')}
              </button>
            ) : null}
            {canClose ? (
              <button
                type="button"
                onClick={handleCloseProject}
                disabled={closing}
                className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-transparent bg-rose-600 px-4 text-sm font-semibold text-white transition hover:bg-rose-700 disabled:opacity-60"
              >
                <Lock size={16} />
                {closing ? t('committeeProject.closing') : t('committeeProject.closeProject')}
              </button>
            ) : null}
            {canDelete ? (
              <button
                type="button"
                onClick={handleDeleteProject}
                disabled={deletingProject}
                className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-600 transition hover:bg-rose-50 disabled:opacity-60"
              >
                <Trash2 size={16} />
                {deletingProject ? t('committeeProject.deleting') : t('committeeProject.deleteProject')}
              </button>
            ) : null}
            {project.pending_deletion_request_id ? (
              <span className="inline-flex h-11 shrink-0 items-center rounded-full bg-amber-50 px-4 text-sm font-semibold text-amber-700">
                {t('committeeProject.deletionPending')}
              </span>
            ) : canRequestDeletion ? (
              <button
                type="button"
                onClick={() => setShowDeletionRequestModal(true)}
                className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-rose-200 bg-white px-4 text-sm font-semibold text-rose-600 transition hover:bg-rose-50"
              >
                <Trash2 size={16} />
                {t('committeeProject.requestDeletion')}
              </button>
            ) : null}
            <Link
              to="/projects"
              className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
            >
              {t('committeeProject.backToProjects')}
            </Link>
          </>
        ) : null
      }
    >
      {errorMessage ? (
        <div className="rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
          {errorMessage}
        </div>
      ) : null}

      {locked ? (
        <div className="flex items-center gap-2 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600">
          <Lock size={16} />
          {t('committeeProject.lockedNotice', { status: stageLabel(project.status) })}
        </div>
      ) : null}

      {!locked && project && !isProjectActive(project) ? (
        <div className={`rounded-2xl px-4 py-3 text-sm font-semibold ${getStatusTone(project.status)}`}>
          {t('committeeProject.stageLine', {
            stage: stageLabel(project.status),
            guidance: STAGE_GUIDANCE[project.status] ?? t('committeeProject.stageGuidance.default'),
          })}
        </div>
      ) : null}

      {loading ? (
        <div className="space-y-3 rounded-2xl border border-[#dfe5ff] bg-white px-6 py-8 shadow-sm">
          <div className="h-4 w-1/3 animate-pulse rounded bg-slate-100" />
          <div className="h-4 w-full animate-pulse rounded bg-slate-100" />
          <div className="h-4 w-5/6 animate-pulse rounded bg-slate-100" />
        </div>
      ) : (
        <>
          <section className="rounded-3xl border border-[#dfe5ff] bg-white p-6 shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
            <div className="flex flex-col gap-5 xl:flex-row xl:items-start xl:justify-start">
              <div>
                <div className="flex flex-wrap items-center gap-3">

                  {committeeRole ? (
                    <span className="rounded-full bg-[#f7f9ff] px-3 py-1 text-xs font-semibold text-slate-600">
                      {committeeRoleLabel(committeeRole)}
                    </span>
                  ) : null}
                </div>

              </div>

              <div className="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4 flex-1">
                <MetricCard label={t('committeeProject.metrics.budget')} value={formatCurrency(project?.budget)} />
                <MetricCard label={t('committeeProject.metrics.collected')} value={formatCurrency(collected)} accent="text-blue-700" />
                <MetricCard label={t('committeeProject.metrics.expenses')} value={formatCurrency(expensesTotal)} accent="text-rose-600" />
                <MetricCard label={t('committeeProject.metrics.remaining')} value={formatCurrency(remaining)} accent="text-emerald-600" />
              </div>
            </div>
          </section>

          <section className="rounded-3xl border border-[#dfe5ff] bg-white shadow-[0_10px_24px_rgba(148,163,184,0.08)]">
            <div className="flex flex-wrap gap-2 border-b border-[#eef2ff] px-4 py-4">
              {tabs.map(([tabId, label, Icon]) => (
                <button
                  key={tabId}
                  type="button"
                  onClick={() => setActiveTab(tabId)}
                  className={`inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-semibold transition ${
                    activeTab === tabId
                      ? 'bg-[#e8efff] text-blue-700'
                      : 'bg-transparent text-slate-500 hover:bg-slate-50 hover:text-slate-900'
                  }`}
                >
                  <Icon size={16} />
                  {label}
                </button>
              ))}
            </div>

            <div className="p-6">
              {activeTab === 'overview' ? (
                <div className="space-y-6">
                  <div className="grid grid-cols-1 gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(320px,0.8fr)]">
                    <div className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-5">
                      <div className="flex items-center justify-between">
                        <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.overview.currentPhaseTitle')}</h4>
                        {project?.pending_phase_request_id ? (
                          <span className="rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                            {t('committeeProject.overview.pendingReview')}
                          </span>
                        ) : null}
                      </div>
                      <div className="mt-4">
                        <span className={`rounded-full px-3 py-1 text-sm font-semibold ${phaseStyle(project?.phase)}`}>
                          {phaseLabel(project?.phase)}
                        </span>
                        <div className="mt-4 flex flex-wrap items-center gap-2">
                          {PHASE_ORDER.map((phase, index) => {
                            const currentIndex = PHASE_ORDER.indexOf(project?.phase)
                            const reached = currentIndex >= 0 && index <= currentIndex
                            return (
                              <span
                                key={phase}
                                className={`rounded-full px-2.5 py-1 text-xs font-semibold ${
                                  reached ? phaseStyle(phase) : 'bg-slate-50 text-slate-400'
                                }`}
                              >
                                {phaseLabel(phase)}
                              </span>
                            )
                          })}
                        </div>
                        <div className="mt-4 grid grid-cols-1 gap-3 md:grid-cols-3">
                          <div className="rounded-2xl bg-white p-4">
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{t('committeeProject.overview.start')}</p>
                            <p className="mt-2 font-semibold text-slate-800">{formatDate(project?.start_date)}</p>
                          </div>
                          <div className="rounded-2xl bg-white p-4">
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{t('committeeProject.overview.end')}</p>
                            <p className="mt-2 font-semibold text-slate-800">{formatDate(project?.end_date)}</p>
                          </div>
                          <div className="rounded-2xl bg-white p-4">
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{t('committeeProject.overview.members')}</p>
                            <p className="mt-2 font-semibold text-slate-800">{members.length}</p>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-5">
                      <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.overview.committeeMembersTitle')}</h4>
                      <div className="mt-4 space-y-3">
                        {members.length ? (
                          members.map((member) => (
                            <div key={member.id} className="rounded-2xl bg-white px-4 py-3">
                              <p className="font-semibold text-slate-800">{member.user?.name ?? t('committeeProject.overview.unknownMember')}</p>
                              <p className="text-sm text-slate-500">{member.user?.email ?? t('committeeProject.overview.noEmail')}</p>
                              <p className="mt-1 text-xs text-slate-400">{member.pivot?.role || t('committeeProject.overview.committeeMemberFallback')}</p>
                            </div>
                          ))
                        ) : (
                          <EmptyPanel
                            title={t('committeeProject.overview.emptyCommitteeTitle')}
                            description={t('committeeProject.overview.emptyCommitteeDescription')}
                          />
                        )}
                      </div>
                    </div>
                  </div>

                  <div className="rounded-2xl border border-[#eef2ff] bg-white p-5">
                    <div className="mb-4 flex items-center justify-between">
                      <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.overview.locationTitle')}</h4>
                      {!editingLocation && canEditProjectLocation(user, project) ? (
                        <button
                          type="button"
                          onClick={openLocationEditor}
                          className="rounded-full border border-[#dfe5ff] bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                          {project?.latitude != null ? t('committeeProject.overview.editLocation') : t('committeeProject.overview.setLocation')}
                        </button>
                      ) : null}
                    </div>

                    {editingLocation ? (
                      <div className="space-y-3">
                        {locationError ? (
                          <p className="text-sm font-medium text-rose-600">{locationError}</p>
                        ) : null}
                        <TomTomMapPicker
                          latitude={pendingLocation.latitude}
                          longitude={pendingLocation.longitude}
                          onChange={(latitude, longitude) => setPendingLocation({ latitude, longitude })}
                        />
                        <div className="flex gap-3">
                          <button
                            type="button"
                            disabled={savingLocation}
                            onClick={handleSaveLocation}
                            className="flex-1 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
                          >
                            {savingLocation ? t('committeeProject.overview.saving') : t('committeeProject.overview.saveLocation')}
                          </button>
                          <button
                            type="button"
                            onClick={cancelLocationEditor}
                            className="rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm font-semibold text-slate-700"
                          >
                            {t('committeeProject.overview.cancel')}
                          </button>
                        </div>
                      </div>
                    ) : project?.latitude != null && project?.longitude != null ? (
                      <div className="space-y-3">
                        <TomTomMapViewer latitude={project.latitude} longitude={project.longitude} />
                        <p className="text-sm text-slate-600">
                          {t('committeeProject.overview.latLngLine', { lat: project.latitude.toFixed(6), lng: project.longitude.toFixed(6) })}
                        </p>
                        <div className="flex flex-wrap gap-2">
                          <a
                            href={`https://www.google.com/maps?q=${project.latitude},${project.longitude}`}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                          >
                            {t('committeeProject.overview.openInGoogleMaps')}
                          </a>
                          <a
                            href={`https://plan.tomtom.com/en?center=${project.longitude},${project.latitude}`}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                          >
                            {t('committeeProject.overview.openInTomTom')}
                          </a>
                        </div>
                      </div>
                    ) : (
                      <p className="text-sm text-slate-500">{t('committeeProject.overview.noLocationSpecified')}</p>
                    )}
                  </div>

                  <div className={`grid grid-cols-1 gap-6 ${canViewDonationDetails ? 'lg:grid-cols-2' : ''}`}>
                    {canViewDonationDetails ? (
                    <div className="rounded-2xl border border-[#eef2ff] bg-white p-5">
                      <div className="mb-4 flex items-center justify-between">
                        <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.overview.projectDonationsTitle')}</h4>
                        <span className="text-sm font-semibold text-blue-700">{formatCurrency(collected)}</span>
                      </div>
                      <div className="space-y-3">
                        {donations.length ? (
                          donations.map((donation) => (
                            <div key={donation.id} className="rounded-2xl bg-[#fbfcff] px-4 py-3">
                              <p className="font-semibold text-slate-800">
                                {donation.donor_name || donation.member?.user?.name || t('committeeProject.overview.unknownDonor')}
                              </p>
                              <p className="text-sm text-slate-500">
                                {formatCurrency(donation.amount)} • {formatDate(donation.donation_date)}
                              </p>
                            </div>
                          ))
                        ) : (
                          <EmptyPanel
                            title={t('committeeProject.overview.emptyDonationsTitle')}
                            description={t('committeeProject.overview.emptyDonationsDescription')}
                          />
                        )}
                      </div>
                    </div>
                    ) : null}

                    <div className="rounded-2xl border border-[#eef2ff] bg-white p-5">
                      <div className="mb-4 flex items-center justify-between">
                        <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.overview.projectExpensesTitle')}</h4>
                        <span className="text-sm font-semibold text-rose-600">{formatCurrency(expensesTotal)}</span>
                      </div>
                      <div className="space-y-3">
                        {expenses.length ? (
                          expenses.map((expense) => (
                            <div key={expense.id} className="rounded-2xl bg-[#fbfcff] px-4 py-3">
                              <p className="font-semibold text-slate-800">{expense.supplier_name || t('committeeProject.overview.unknownSupplier')}</p>
                              <p className="text-sm text-slate-500">
                                {formatCurrency(expense.amount)} • {formatDate(expense.expense_date)}
                              </p>
                            </div>
                          ))
                        ) : (
                          <EmptyPanel
                            title={t('committeeProject.overview.emptyExpensesTitle')}
                            description={t('committeeProject.overview.emptyExpensesDescription')}
                          />
                        )}
                      </div>
                    </div>
                  </div>

                  <div className="rounded-2xl border border-[#eef2ff] bg-white p-5">
                    <div className="mb-4 flex items-center justify-between">
                      <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.overview.fundAllocationsTitle')}</h4>
                      <span className="text-sm font-semibold text-emerald-600">
                        {formatCurrency(allocations.reduce((sum, allocation) => sum + Number(allocation.amount ?? 0), 0))}
                      </span>
                    </div>
                    <div className="space-y-3">
                      {allocations.length ? (
                        allocations.map((allocation) => (
                          <div
                            key={allocation.id}
                            className="flex flex-col gap-2 rounded-2xl bg-[#fbfcff] px-4 py-3 md:flex-row md:items-center md:justify-between"
                          >
                            <div>
                              <p className="font-semibold text-slate-800">
                                {formatCurrency(allocation.amount)} • {formatDate(allocation.allocation_date)}
                              </p>
                              <p className="text-sm text-slate-500">{t('committeeProject.overview.recordedBy', { name: allocation.recorded_by || t('committeeProject.overview.unknown') })}</p>
                            </div>
                            {allocation.proof_file_url ? (
                              <a
                                href={allocation.proof_file_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-sm font-semibold text-blue-700"
                              >
                                {t('committeeProject.overview.viewProof')}
                              </a>
                            ) : null}
                          </div>
                        ))
                      ) : (
                        <EmptyPanel
                          title={t('committeeProject.overview.emptyAllocationsTitle')}
                          description={t('committeeProject.overview.emptyAllocationsDescription')}
                        />
                      )}
                    </div>
                  </div>
                </div>
              ) : null}

              {activeTab === 'phases' ? (
                <div className="space-y-6">
                  <div className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-5">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.phases.currentPhaseTitle')}</h4>
                        <span className={`mt-2 inline-block rounded-full px-3 py-1 text-sm font-semibold ${phaseStyle(project?.phase)}`}>
                          {phaseLabel(project?.phase)}
                        </span>
                      </div>

                      {project?.pending_phase_request_id ? (
                        <span className="rounded-full bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-700">
                          {t('committeeProject.phases.pendingReview')}
                        </span>
                      ) : canRequestProjectPhase(user, project) && nextRequestablePhase(project?.phase) && !showPhaseRequestForm ? (
                        <button
                          type="button"
                          onClick={openPhaseRequestForm}
                          className="rounded-full bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700"
                        >
                          {t('committeeProject.phases.requestNextPhase')}
                        </button>
                      ) : null}
                    </div>

                    {showPhaseRequestForm ? (
                      <form onSubmit={handlePhaseRequestSubmit} className="mt-5 space-y-4 rounded-2xl bg-white p-5">
                        {phaseRequestError ? (
                          <p className="text-sm font-medium text-rose-600">{phaseRequestError}</p>
                        ) : null}

                        <div>
                          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-400">{t('committeeProject.phases.targetPhase')}</p>
                          <p className="mt-1 font-semibold text-slate-800">
                            {phaseLabel(nextRequestablePhase(project?.phase))}
                          </p>
                        </div>

                        <textarea
                          name="summary"
                          value={phaseRequestForm.summary}
                          onChange={updatePhaseRequestForm}
                          placeholder={t('committeeProject.phases.summaryPlaceholder')}
                          className="min-h-[96px] w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 py-3 text-sm outline-none"
                        />

                        <textarea
                          name="notes"
                          value={phaseRequestForm.notes}
                          onChange={updatePhaseRequestForm}
                          placeholder={t('committeeProject.phases.notesPlaceholder')}
                          className="min-h-[72px] w-full rounded-2xl border border-[#dfe5ff] bg-[#fbfcff] px-4 py-3 text-sm outline-none"
                        />

                        <div>
                          <label className="inline-flex cursor-pointer items-center gap-2 rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                            <Upload size={14} />
                            {t('committeeProject.phases.uploadProofs')}
                            <input
                              type="file"
                              multiple
                              accept=".pdf,.jpg,.jpeg,.png"
                              className="hidden"
                              onChange={updatePhaseRequestFiles}
                            />
                          </label>
                          {phaseRequestFiles.length ? (
                            <ul className="mt-2 space-y-1 text-xs text-slate-500">
                              {phaseRequestFiles.map((file, index) => (
                                <li key={`${file.name}-${index}`}>{file.name}</li>
                              ))}
                            </ul>
                          ) : null}
                        </div>

                        <div className="flex gap-3">
                          <button
                            type="submit"
                            disabled={savingPhaseRequest}
                            className="flex-1 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white disabled:opacity-60"
                          >
                            {savingPhaseRequest ? t('committeeProject.phases.submitting') : t('committeeProject.phases.submitRequest')}
                          </button>
                          <button
                            type="button"
                            onClick={cancelPhaseRequestForm}
                            className="rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm font-semibold text-slate-700"
                          >
                            {t('committeeProject.phases.cancel')}
                          </button>
                        </div>
                      </form>
                    ) : null}
                  </div>

                  <div className="rounded-2xl border border-[#eef2ff] bg-white p-5">
                    <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.phases.timelineTitle')}</h4>
                    <div className="mt-4 space-y-3">
                      {phaseRequests.length ? (
                        phaseRequests.map((phaseRequestItem) => (
                          <div key={phaseRequestItem.id} className="rounded-2xl bg-[#fbfcff] px-4 py-4">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                              <p className="font-semibold text-slate-800">
                                {phaseLabel(phaseRequestItem.from_phase)} → {phaseLabel(phaseRequestItem.to_phase)}
                              </p>
                              <span
                                className={`rounded-full px-3 py-1 text-xs font-semibold ${
                                  phaseRequestItem.status === 'approved'
                                    ? 'bg-emerald-50 text-emerald-700'
                                    : phaseRequestItem.status === 'rejected'
                                      ? 'bg-rose-50 text-rose-700'
                                      : 'bg-amber-50 text-amber-700'
                                }`}
                              >
                                {t(`common:status.${phaseRequestItem.status}`, { ns: 'common', defaultValue: phaseRequestItem.status })}
                              </span>
                            </div>
                            <p className="mt-1 text-xs text-slate-400">
                              {t('committeeProject.phases.requestedBy', { name: phaseRequestItem.requested_by?.name ?? t('committeeProject.phases.unknown'), date: formatDate(phaseRequestItem.requested_at) })}
                            </p>
                            <p className="mt-2 text-sm text-slate-600">{phaseRequestItem.summary}</p>
                            {phaseRequestItem.notes ? (
                              <p className="mt-1 text-sm text-slate-500">{phaseRequestItem.notes}</p>
                            ) : null}
                            {phaseRequestItem.proofs?.length ? (
                              <div className="mt-2 flex flex-wrap gap-2">
                                {phaseRequestItem.proofs.map((proof) => (
                                  <a
                                    key={proof.id}
                                    href={proof.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="rounded-full border border-[#dfe5ff] bg-white px-3 py-1 text-xs font-semibold text-blue-600"
                                  >
                                    {proof.original_name}
                                  </a>
                                ))}
                              </div>
                            ) : null}
                            {phaseRequestItem.reviewed_by ? (
                              <p className="mt-2 text-xs text-slate-400">
                                {t('committeeProject.phases.reviewedBy', { name: phaseRequestItem.reviewed_by.name, date: formatDate(phaseRequestItem.reviewed_at) })}
                              </p>
                            ) : null}
                            {phaseRequestItem.status === 'rejected' && phaseRequestItem.rejection_reason ? (
                              <p className="mt-1 text-xs font-medium text-rose-600">
                                {t('committeeProject.phases.reasonLabel', { reason: phaseRequestItem.rejection_reason })}
                              </p>
                            ) : null}
                          </div>
                        ))
                      ) : (
                        <EmptyPanel
                          title={t('committeeProject.phases.emptyTitle')}
                          description={t('committeeProject.phases.emptyDescription')}
                        />
                      )}
                    </div>
                  </div>
                </div>
              ) : null}

              {activeTab === 'donations' && canViewDonationDetails ? (
                <div className="grid grid-cols-1 gap-6 xl:grid-cols-[400px_minmax(0,1fr)]">
                  {canEnterDonation(user, committeeScope) && !locked && isProjectActive(project) ? (
                  <section className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-5">
                    <div className="mb-5">
                      <h4 className="text-base font-semibold text-slate-900">
                        {editingDonationId ? t('committeeProject.donations.editTitle') : t('committeeProject.donations.createTitle')}
                      </h4>
                      <p className="mt-1 text-sm text-slate-500">
                        {editingDonationId
                          ? t('committeeProject.donations.editSubtitle')
                          : t('committeeProject.donations.createSubtitle')}
                      </p>
                    </div>

                    <form className="space-y-4" onSubmit={handleDonationSubmit}>
                      <input
                        type="text"
                        name="donor_name"
                        value={donationForm.donor_name}
                        onChange={updateDonationForm}
                        placeholder={t('committeeProject.donations.externalDonorName')}
                        className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-white px-4 text-sm outline-none"
                      />

                      {DONATION_FORM_FIELDS.map((field) => (
                        <input
                          key={field}
                          type={field === 'amount' ? 'number' : field.includes('date') ? 'date' : 'text'}
                          name={field}
                          value={donationForm[field]}
                          onChange={updateDonationForm}
                          placeholder={t(`committeeProject.donations.fields.${field}`)}
                          className="h-11 w-full rounded-2xl border border-[#dfe5ff] bg-white px-4 text-sm outline-none"
                        />
                      ))}

                      <textarea
                        name="notes"
                        value={donationForm.notes}
                        onChange={updateDonationForm}
                        placeholder={t('committeeProject.donations.notes')}
                        className="min-h-[96px] w-full rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm outline-none"
                      />

                      <div className="flex gap-3">
                        <button
                          type="submit"
                          disabled={savingDonation}
                          className="flex-1 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white"
                        >
                          {savingDonation ? t('committeeProject.donations.saving') : editingDonationId ? t('committeeProject.donations.update') : t('committeeProject.donations.save')}
                        </button>
                        {editingDonationId ? (
                          <button
                            type="button"
                            onClick={resetDonationForm}
                            className="rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm font-semibold text-slate-700"
                          >
                            {t('committeeProject.donations.cancel')}
                          </button>
                        ) : null}
                      </div>
                    </form>
                  </section>
                  ) : null}

                  <section className="rounded-2xl border border-[#eef2ff] bg-white">
                    <div className="flex items-center justify-between border-b border-[#eef2ff] px-5 py-4">
                      <div>
                        <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.donations.listTitle')}</h4>
                        <p className="text-sm text-slate-500">{t('committeeProject.donations.recordCount', { count: donations.length })}</p>
                      </div>
                    </div>

                    <div className="divide-y divide-[#eef2ff]">
                      {donations.length ? (
                        donations.map((donation) => (
                          <div key={donation.id} className="flex flex-col gap-4 px-5 py-4 md:flex-row md:items-start md:justify-between">
                            <div>
                              <div className="flex flex-wrap items-center gap-2">
                                <p className="font-semibold text-slate-900">{donation.donor_name || t('committeeProject.donations.unknownDonor')}</p>
                                <DonationStatusBadge status={donation.status} />
                              </div>
                              <p className="text-sm text-slate-500">
                                {formatCurrency(donation.amount)} • {donation.payment_method || t('committeeProject.donations.noPaymentMethod')}
                              </p>
                              <p className="mt-1 text-xs text-slate-400">
                                {formatDate(donation.donation_date)} • {t('committeeProject.donations.receiptLabel', { number: donation.receipt_number || t('committeeProject.donations.notProvided') })}
                              </p>
                              {donation.notes ? <p className="mt-2 text-sm text-slate-500">{donation.notes}</p> : null}
                              {donation.status === 'rejected' && donation.rejection_reason ? (
                                <p className="mt-2 text-sm text-rose-600">{t('committeeProject.donations.rejectedLabel', { reason: donation.rejection_reason })}</p>
                              ) : null}

                              <div className="mt-3">
                                <DonationWorkflowActions
                                  user={user}
                                  donation={donation}
                                  project={project}
                                  committeeScope={committeeScope}
                                  onSubmit={handleDonationWorkflowSubmit}
                                  onApprove={handleDonationApprove}
                                  onReject={handleDonationReject}
                                />
                              </div>
                            </div>

                            <div className="flex flex-wrap gap-2">
                              {donation.receipt_url ? (
                                <a
                                  href={donation.receipt_url}
                                  target="_blank"
                                  rel="noreferrer"
                                  className="rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                                >
                                  {t('committeeProject.donations.viewReceipt')}
                                </a>
                              ) : null}

                              {canUploadReceipt(user, committeeScope, donation) && !locked ? (
                                <label className="inline-flex cursor-pointer items-center gap-2 rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                                  <Upload size={14} />
                                  {donation.receipt_url ? t('committeeProject.donations.replaceReceipt') : t('committeeProject.donations.uploadReceipt')}
                                  <input
                                    type="file"
                                    className="hidden"
                                    onChange={(event) => handleDonationReceiptUpload(donation.id, event)}
                                  />
                                </label>
                              ) : null}

                              {canEditDonation(user, committeeScope, donation) && !locked ? (
                                <button
                                  type="button"
                                  onClick={() => beginDonationEdit(donation)}
                                  className="rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                                >
                                  {t('committeeProject.donations.edit')}
                                </button>
                              ) : null}

                              {canDeleteDonation(user, committeeScope, donation) && !locked ? (
                                <button
                                  type="button"
                                  onClick={() => handleDonationDelete(donation.id)}
                                  className="inline-flex items-center gap-2 rounded-full border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-600"
                                >
                                  <Trash2 size={14} />
                                  {t('committeeProject.donations.delete')}
                                </button>
                              ) : null}

                              {canEnterDonation(user, committeeScope) &&
                              !locked &&
                              donation.status === 'rejected' &&
                              donation.rejection_type === 'details' ? (
                                <button
                                  type="button"
                                  onClick={() => beginNewDonationFromRejected(donation)}
                                  className="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
                                >
                                  {t('committeeProject.donations.createNew')}
                                </button>
                              ) : null}
                            </div>
                          </div>
                        ))
                      ) : (
                        <div className="px-5 py-8">
                          <EmptyPanel
                            title={t('committeeProject.donations.emptyTitle')}
                            description={t('committeeProject.donations.emptyDescription')}
                          />
                        </div>
                      )}
                    </div>
                  </section>
                </div>
              ) : null}

              {activeTab === 'expenses' ? (
                <div className="space-y-4">
                  {canEnterExpense(user, committeeScope) && !locked && isProjectActive(project) ? (
                    <section className="flex flex-wrap items-center gap-3 rounded-2xl border border-[#eef2ff] bg-white px-5 py-4">
                      <label className="inline-flex h-11 shrink-0 cursor-pointer items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50">
                        <FileUp size={16} />
                        {t('committeeProject.expenses.importCsvXlsx')}
                        <input
                          type="file"
                          accept=".csv,.xlsx"
                          className="hidden"
                          onChange={handleImportFileChange}
                        />
                      </label>

                      <button
                        type="button"
                        onClick={() => handleDownloadTemplate('csv')}
                        className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                      >
                        <FileDown size={16} />
                        {t('committeeProject.expenses.downloadTemplateCsv')}
                      </button>

                      <button
                        type="button"
                        onClick={() => handleDownloadTemplate('xlsx')}
                        className="inline-flex h-11 shrink-0 items-center gap-2 whitespace-nowrap rounded-full border border-[#dfe5ff] bg-white px-4 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                      >
                        <FileDown size={16} />
                        {t('committeeProject.expenses.downloadTemplateXlsx')}
                      </button>

                      {importError && importDrafts.length === 0 ? (
                        <p className="w-full text-xs font-medium text-rose-600">{importError}</p>
                      ) : null}
                    </section>
                  ) : null}

                <div className="grid grid-cols-1 gap-6 xl:grid-cols-[400px_minmax(0,1fr)]">
                  {canEnterExpense(user, committeeScope) && !locked && isProjectActive(project) ? (
                  <section className="rounded-2xl border border-[#eef2ff] bg-[#fbfcff] p-5">
                    <div className="mb-5">
                      <h4 className="text-base font-semibold text-slate-900">
                        {editingExpenseId ? t('committeeProject.expenses.editTitle') : t('committeeProject.expenses.createTitle')}
                      </h4>
                      <p className="mt-1 text-sm text-slate-500">
                        {editingExpenseId
                          ? t('committeeProject.expenses.editSubtitle')
                          : t('committeeProject.expenses.createSubtitle')}
                      </p>
                    </div>

                    <form className="space-y-4" onSubmit={handleExpenseSubmit}>
                      <ExpenseFormFields values={expenseForm} onChange={updateExpenseForm} />

                      <div className="flex gap-3">
                        <button
                          type="submit"
                          disabled={savingExpense}
                          className="flex-1 rounded-2xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white"
                        >
                          {savingExpense ? t('committeeProject.expenses.saving') : editingExpenseId ? t('committeeProject.expenses.update') : t('committeeProject.expenses.save')}
                        </button>
                        {editingExpenseId ? (
                          <button
                            type="button"
                            onClick={resetExpenseForm}
                            className="rounded-2xl border border-[#dfe5ff] bg-white px-4 py-3 text-sm font-semibold text-slate-700"
                          >
                            {t('committeeProject.expenses.cancel')}
                          </button>
                        ) : null}
                      </div>
                    </form>
                  </section>
                  ) : null}

                  {(() => {
                    const deletableExpenseIds = expenses
                      .filter((expense) => canDeleteExpense(user, committeeScope, expense) && !locked)
                      .map((expense) => expense.id)
                    const allExpensesSelected =
                      deletableExpenseIds.length > 0 && deletableExpenseIds.every((id) => selectedExpenseIds.includes(id))

                    return (
                      <section className="rounded-2xl border border-[#eef2ff] bg-white">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#eef2ff] px-5 py-4">
                          <div className="flex items-center gap-3">
                            {deletableExpenseIds.length > 0 ? (
                              <input
                                type="checkbox"
                                checked={allExpensesSelected}
                                onChange={() => toggleSelectAllExpenses(deletableExpenseIds)}
                                aria-label={t('committeeProject.expenses.selectAllAria')}
                                className="h-4 w-4 rounded border-[#dfe5ff] text-rose-600"
                              />
                            ) : null}
                            <div>
                              <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.expenses.listTitle')}</h4>
                              <p className="text-sm text-slate-500">{t('committeeProject.expenses.recordCount', { count: expenses.length })}</p>
                            </div>
                          </div>

                          {selectedExpenseIds.length > 0 ? (
                            <div className="flex flex-wrap items-center gap-2">
                              {(() => {
                                const submittableSelectedCount = selectedExpenseIds.filter((id) => {
                                  const expense = expenses.find((e) => e.id === id)
                                  return expense && canSubmitExpense(user, committeeScope, expense)
                                }).length

                                return submittableSelectedCount > 0 ? (
                                  <button
                                    type="button"
                                    onClick={handleBulkSubmitExpenses}
                                    disabled={submittingSelectedExpenses}
                                    className="inline-flex items-center gap-2 rounded-full border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-600 transition hover:bg-blue-100 disabled:opacity-60"
                                  >
                                    <Send size={14} />
                                    {submittingSelectedExpenses
                                      ? t('committeeProject.expenses.submitting')
                                      : t('committeeProject.expenses.submitSelected', { count: submittableSelectedCount })}
                                  </button>
                                ) : null
                              })()}

                              <button
                                type="button"
                                onClick={handleBulkDeleteExpenses}
                                disabled={deletingSelectedExpenses}
                                className="inline-flex items-center gap-2 rounded-full border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-600 transition hover:bg-rose-100 disabled:opacity-60"
                              >
                                <Trash2 size={14} />
                                {deletingSelectedExpenses
                                  ? t('committeeProject.expenses.deleting')
                                  : t('committeeProject.expenses.deleteSelected', { count: selectedExpenseIds.length })}
                              </button>
                            </div>
                          ) : null}
                        </div>

                        <div className="divide-y divide-[#eef2ff]">
                          {expenses.length ? (
                            expenses.map((expense) => {
                              const isDeletable = canDeleteExpense(user, committeeScope, expense) && !locked

                              return (
                                <div key={expense.id} className="flex flex-col gap-4 px-5 py-4 md:flex-row md:items-start md:justify-between">
                                  <div className="flex items-start gap-3">
                                    {isDeletable ? (
                                      <input
                                        type="checkbox"
                                        checked={selectedExpenseIds.includes(expense.id)}
                                        onChange={() => toggleExpenseSelected(expense.id)}
                                        aria-label={t('committeeProject.expenses.selectExpenseAria', { name: expense.supplier_name || expense.id })}
                                        className="mt-1 h-4 w-4 shrink-0 rounded border-[#dfe5ff] text-rose-600"
                                      />
                                    ) : null}
                                    <div>
                                      <div className="flex flex-wrap items-center gap-2">
                                        <p className="font-semibold text-slate-900">{expense.supplier_name || t('committeeProject.expenses.unknownSupplier')}</p>
                                        <ExpenseStatusBadge status={expense.status} />
                                      </div>
                                      <p className="text-sm text-slate-500">
                                        {formatCurrency(expense.amount)} • {expense.payment_method || t('committeeProject.expenses.noPaymentMethod')}
                                      </p>
                                      <p className="mt-1 text-xs text-slate-400">
                                        {formatDate(expense.expense_date)} • {t('committeeProject.expenses.invoiceLabel', { number: expense.invoice_number || t('committeeProject.expenses.notProvided') })}
                                      </p>
                                      {expense.description ? <p className="mt-2 text-sm text-slate-600">{expense.description}</p> : null}
                                      {expense.notes ? <p className="mt-1 text-sm text-slate-500">{expense.notes}</p> : null}
                                      {expense.status === 'rejected' && expense.rejection_reason ? (
                                        <p className="mt-2 text-sm text-rose-600">{t('committeeProject.expenses.rejectedLabel', { reason: expense.rejection_reason })}</p>
                                      ) : null}

                                      <div className="mt-3">
                                        <ExpenseWorkflowActions
                                          user={user}
                                          expense={expense}
                                          project={project}
                                          committeeScope={committeeScope}
                                          onSubmit={handleExpenseWorkflowSubmit}
                                          onApprove={handleExpenseApprove}
                                          onReject={handleExpenseReject}
                                          onMarkPaid={handleExpenseMarkPaid}
                                        />
                                      </div>
                                    </div>
                                  </div>

                                  <div className="flex flex-wrap gap-2">
                                    {expense.invoice_url ? (
                                      <a
                                        href={expense.invoice_url}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                                      >
                                        {t('committeeProject.expenses.viewInvoice')}
                                      </a>
                                    ) : null}

                                    {canUploadInvoice(user, committeeScope, expense) && !locked ? (
                                      <label className="inline-flex cursor-pointer items-center gap-2 rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700">
                                        <Upload size={14} />
                                        {expense.invoice_url ? t('committeeProject.expenses.replaceInvoice') : t('committeeProject.expenses.uploadInvoice')}
                                        <input
                                          type="file"
                                          className="hidden"
                                          onChange={(event) => handleExpenseInvoiceUpload(expense.id, event)}
                                        />
                                      </label>
                                    ) : null}

                                    {canEditExpense(user, committeeScope, expense) && !locked ? (
                                      <button
                                        type="button"
                                        onClick={() => beginExpenseEdit(expense)}
                                        className="rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700"
                                      >
                                        {t('committeeProject.expenses.edit')}
                                      </button>
                                    ) : null}

                                    {isDeletable ? (
                                      <button
                                        type="button"
                                        onClick={() => handleExpenseDelete(expense.id)}
                                        className="inline-flex items-center gap-2 rounded-full border border-rose-200 bg-white px-4 py-2 text-sm font-semibold text-rose-600"
                                      >
                                        <Trash2 size={14} />
                                        {t('committeeProject.expenses.delete')}
                                      </button>
                                    ) : null}

                                    {canEnterExpense(user, committeeScope) &&
                                    !locked &&
                                    expense.status === 'rejected' &&
                                    expense.rejection_type === 'details' ? (
                                      <button
                                        type="button"
                                        onClick={() => beginNewExpenseFromRejected(expense)}
                                        className="rounded-full bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700"
                                      >
                                        {t('committeeProject.expenses.createNew')}
                                      </button>
                                    ) : null}
                                  </div>
                                </div>
                              )
                            })
                          ) : (
                            <div className="px-5 py-8">
                              <EmptyPanel
                                title={t('committeeProject.expenses.emptyTitle')}
                                description={t('committeeProject.expenses.emptyDescription')}
                              />
                            </div>
                          )}
                        </div>
                      </section>
                    )
                  })()}
                </div>
                </div>
              ) : null}

              {activeTab === 'reports' ? (
                <section className="rounded-2xl border border-[#eef2ff] bg-white">
                  <div className="border-b border-[#eef2ff] px-5 py-4">
                    <h4 className="text-base font-semibold text-slate-900">{t('committeeProject.reports.title')}</h4>
                    <p className="text-sm text-slate-500">
                      {t('committeeProject.reports.subtitle')}
                    </p>
                  </div>

                  <div className="divide-y divide-[#eef2ff]">
                    {reports.length ? (
                      reports.map((report) => (
                        <div key={report.id} className="flex flex-col gap-3 px-5 py-4 md:flex-row md:items-center md:justify-between">
                          <div>
                            <p className="font-semibold text-slate-900">
                              {t('committeeProject.reports.generatedOn', { date: formatDate(report.created_at) })}
                            </p>
                            <p className="text-sm text-slate-500">
                              {t('committeeProject.reports.summaryLine', {
                                donations: formatCurrency(report.summary?.donations_total),
                                allocations: formatCurrency(report.summary?.allocations_total),
                                expenses: formatCurrency(report.summary?.expenses_total),
                                returned: formatCurrency(report.summary?.returned_to_pool),
                              })}
                            </p>
                            <p className="mt-1 text-xs text-slate-400">{t('committeeProject.reports.by', { name: report.generated_by ?? t('committeeProject.reports.unknown') })}</p>
                          </div>

                          <button
                            type="button"
                            onClick={() => handleDownloadReport(report)}
                            className="inline-flex shrink-0 items-center gap-2 rounded-full border border-[#dfe5ff] bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                          >
                            <FileBarChart size={14} />
                            {t('committeeProject.reports.downloadPdf')}
                          </button>
                        </div>
                      ))
                    ) : (
                      <div className="px-5 py-8">
                        <EmptyPanel
                          title={t('committeeProject.reports.emptyTitle')}
                          description={t('committeeProject.reports.emptyDescription')}
                        />
                      </div>
                    )}
                  </div>
                </section>
              ) : null}
            </div>
          </section>
        </>
      )}

      {importDrafts.length > 0 ? (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 px-4 py-8">
          <div className="w-full max-w-2xl overflow-y-auto rounded-3xl bg-white p-6 shadow-2xl" style={{ maxHeight: '90vh' }}>
            <div className="mb-5 flex items-center justify-between gap-3">
              <div>
                <h3 className="text-lg font-semibold text-slate-900">{t('committeeProject.importModal.title')}</h3>
                <p className="text-sm text-slate-500">
                  {t('committeeProject.importModal.indexOfTotal', { index: importIndex + 1, total: importDrafts.length })}
                </p>
              </div>
              <button
                type="button"
                onClick={handleCancelImport}
                className="rounded-full p-2 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
              >
                <X size={18} />
              </button>
            </div>

            {importError ? (
              <div className="mb-4 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {importError}
              </div>
            ) : null}

            <ExpenseFormFields
              values={importDrafts[importIndex]}
              onChange={updateImportDraftField}
              errors={validateExpenseDraft(importDrafts[importIndex])}
            />

            <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={goToPreviousDraft}
                  disabled={importIndex === 0}
                  className="inline-flex items-center gap-1 rounded-2xl border border-[#dfe5ff] bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-40"
                >
                  <ChevronLeft size={16} />
                  {t('committeeProject.importModal.prev')}
                </button>
                <button
                  type="button"
                  onClick={goToNextDraft}
                  disabled={importIndex === importDrafts.length - 1}
                  className="inline-flex items-center gap-1 rounded-2xl border border-[#dfe5ff] bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50 disabled:opacity-40"
                >
                  {t('committeeProject.importModal.next')}
                  <ChevronRight size={16} />
                </button>
              </div>

              <div className="flex gap-2">
                <button
                  type="button"
                  onClick={handleCancelImport}
                  className="rounded-2xl border border-[#dfe5ff] bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                >
                  {t('committeeProject.importModal.cancel')}
                </button>
                <button
                  type="button"
                  onClick={handleSaveAllImports}
                  disabled={savingImport || !importDrafts.every(isExpenseDraftValid)}
                  className="rounded-2xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:opacity-50"
                >
                  {savingImport ? t('committeeProject.importModal.saving') : t('committeeProject.importModal.saveAll', { count: importDrafts.length })}
                </button>
              </div>
            </div>
          </div>
        </div>
      ) : null}

      <ReasonModal
        open={showDeletionRequestModal}
        submitting={submittingDeletionRequest}
        title={t('committeeProject.deletionRequestModal.title')}
        description={t('committeeProject.deletionRequestModal.description')}
        placeholder={t('committeeProject.deletionRequestModal.placeholder')}
        confirmLabel={t('committeeProject.deletionRequestModal.confirmLabel')}
        confirmClassName="bg-rose-600 hover:bg-rose-700"
        onCancel={() => setShowDeletionRequestModal(false)}
        onConfirm={handleRequestDeletion}
      />
    </PresidentLayout>
  )
}
