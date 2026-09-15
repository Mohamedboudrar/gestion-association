import http from './http'

export async function getSettings() {
  const { data } = await http.get('/settings')
  return data.data ?? data
}

export async function updateSettings(payload, logoFile) {
  const formData = new FormData()
  formData.append('_method', 'PUT')
  formData.append('association_name', payload.association_name ?? '')
  formData.append('description', payload.description ?? '')
  formData.append('address', payload.address ?? '')
  formData.append('phone', payload.phone ?? '')
  formData.append('email', payload.email ?? '')
  formData.append('website', payload.website ?? '')
  formData.append('annual_subscription_amount', payload.annual_subscription_amount ?? 0)
  formData.append('currency', payload.currency ?? '')

  if (logoFile) {
    formData.append('logo', logoFile)
  }

  const { data } = await http.post('/settings', formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })

  return data.data ?? data
}
