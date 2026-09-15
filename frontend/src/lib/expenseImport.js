import Papa from 'papaparse'
import ExcelJS from 'exceljs'
import i18n from '../i18n'

// Column headers, in order — must exactly match the expense form's input `name`
// attributes (see ExpenseFormFields.jsx) so the downloaded template's headers
// line up 1:1 with what import parsing looks for.
export const EXPENSE_IMPORT_FIELDS = [
  'supplier_name',
  'description',
  'amount',
  'payment_method',
  'invoice_number',
  'expense_date',
  'notes',
]

function cellToString(cell) {
  const value = cell?.value
  if (value == null) return ''

  if (value instanceof Date) {
    return value.toISOString().slice(0, 10)
  }

  if (typeof value === 'object') {
    if ('result' in value) return String(value.result ?? '')
    if (Array.isArray(value.richText)) return value.richText.map((part) => part.text).join('')
    if ('text' in value) return String(value.text ?? '')
  }

  return String(value).trim()
}

function rowToDraft(rawRow) {
  const normalized = {}
  Object.entries(rawRow).forEach(([key, value]) => {
    normalized[String(key).trim().toLowerCase()] = value
  })

  return EXPENSE_IMPORT_FIELDS.reduce((draft, field) => {
    const value = normalized[field]
    draft[field] = value != null ? String(value).trim() : ''
    return draft
  }, {})
}

function parseCsvFile(file) {
  return new Promise((resolve, reject) => {
    Papa.parse(file, {
      header: true,
      skipEmptyLines: true,
      complete: (results) => resolve(results.data.map(rowToDraft)),
      error: (error) => reject(error),
    })
  })
}

async function parseXlsxFile(file) {
  const buffer = await file.arrayBuffer()
  const workbook = new ExcelJS.Workbook()
  await workbook.xlsx.load(buffer)
  const worksheet = workbook.worksheets[0]

  if (!worksheet) return []

  const headers = []
  worksheet.getRow(1).eachCell({ includeEmpty: true }, (cell, colNumber) => {
    headers[colNumber] = String(cell.value ?? '').trim()
  })

  const drafts = []

  worksheet.eachRow((row, rowNumber) => {
    if (rowNumber === 1) return

    const rawRow = {}
    row.eachCell({ includeEmpty: true }, (cell, colNumber) => {
      const header = headers[colNumber]
      if (header) rawRow[header] = cellToString(cell)
    })

    if (Object.values(rawRow).some((value) => value !== '')) {
      drafts.push(rowToDraft(rawRow))
    }
  })

  return drafts
}

// Parses an uploaded .csv or .xlsx file into an array of expense drafts, one
// per data row. Unknown columns are dropped, missing columns default to ''.
export async function parseExpenseImportFile(file) {
  const name = file.name.toLowerCase()

  if (name.endsWith('.csv')) {
    return parseCsvFile(file)
  }

  if (name.endsWith('.xlsx')) {
    return parseXlsxFile(file)
  }

  throw new Error(i18n.t('expenseImport.unsupportedFileType', { ns: 'validation' }))
}

// Mirrors backend StoreExpenseRequest's required-field rules so a draft that
// passes here won't be rejected purely for a missing/malformed field (the
// project-funds-remaining check is still enforced server-side on save).
export function validateExpenseDraft(draft) {
  const errors = {}

  if (!draft.supplier_name?.trim()) {
    errors.supplier_name = i18n.t('expenseImport.supplierNameRequired', { ns: 'validation' })
  }

  if (!draft.description?.trim()) {
    errors.description = i18n.t('expenseImport.descriptionRequired', { ns: 'validation' })
  }

  const amount = Number(draft.amount)
  if (!draft.amount || Number.isNaN(amount) || amount < 0.01) {
    errors.amount = i18n.t('expenseImport.amountInvalid', { ns: 'validation' })
  }

  if (!draft.payment_method?.trim()) {
    errors.payment_method = i18n.t('expenseImport.paymentMethodRequired', { ns: 'validation' })
  }

  if (!draft.expense_date || Number.isNaN(Date.parse(draft.expense_date))) {
    errors.expense_date = i18n.t('expenseImport.expenseDateInvalid', { ns: 'validation' })
  }

  return errors
}

export function isExpenseDraftValid(draft) {
  return Object.keys(validateExpenseDraft(draft)).length === 0
}

export function buildExpenseTemplateCsv() {
  return `${EXPENSE_IMPORT_FIELDS.join(',')}\n`
}

export async function buildExpenseTemplateXlsxBuffer() {
  const workbook = new ExcelJS.Workbook()
  const worksheet = workbook.addWorksheet('Expenses')
  worksheet.addRow(EXPENSE_IMPORT_FIELDS)
  return workbook.xlsx.writeBuffer()
}

export function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}
