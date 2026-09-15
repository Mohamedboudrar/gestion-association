import http from './http'

export async function getExpenses(params) {
  const { data } = await http.get('/expenses', { params })
  return data.data ?? []
}

export async function createExpense(payload) {
  const { data } = await http.post('/expenses', payload)
  return data.data ?? data
}

// Atomic — the backend rejects the entire batch (creates nothing) if its
// summed total would exceed the project's remaining budget. `drafts` is an
// array of { supplier_name, description, amount, payment_method,
// invoice_number, expense_date, notes }.
export async function importExpenses(projectId, drafts) {
  const { data } = await http.post('/expenses/import', {
    project_id: projectId,
    expenses: drafts,
  })
  return data.data ?? data
}

export async function updateExpense(expenseId, payload) {
  const { data } = await http.put(`/expenses/${expenseId}`, payload)
  return data.data ?? data
}

export async function deleteExpense(expenseId) {
  const { data } = await http.delete(`/expenses/${expenseId}`)
  return data
}

export async function uploadExpenseInvoice(expenseId, file) {
  const formData = new FormData()
  formData.append('invoice', file)

  const { data } = await http.post(`/expenses/${expenseId}/invoice`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })

  return data.data ?? data
}

export async function submitExpense(expenseId) {
  const { data } = await http.post(`/expenses/${expenseId}/submit`)
  return data.data ?? data
}

export async function approveExpense(expenseId) {
  const { data } = await http.post(`/expenses/${expenseId}/approve`)
  return data.data ?? data
}

export async function rejectExpense(expenseId, reason, rejectionType) {
  const { data } = await http.post(`/expenses/${expenseId}/reject`, { reason, rejection_type: rejectionType })
  return data.data ?? data
}

export async function markExpensePaid(expenseId) {
  const { data } = await http.post(`/expenses/${expenseId}/mark-paid`)
  return data.data ?? data
}