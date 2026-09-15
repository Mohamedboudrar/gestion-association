import http from './http'

export async function getDonations(params) {
  const { data } = await http.get('/donations', { params })
  return data.data ?? []
}

export async function createDonation(payload) {
  const { data } = await http.post('/donations', payload)
  return data.data ?? data
}

export async function updateDonation(donationId, payload) {
  const { data } = await http.put(`/donations/${donationId}`, payload)
  return data.data ?? data
}

export async function deleteDonation(donationId) {
  const { data } = await http.delete(`/donations/${donationId}`)
  return data
}

export async function uploadDonationReceipt(donationId, file) {
  const formData = new FormData()
  formData.append('receipt', file)

  const { data } = await http.post(`/donations/${donationId}/receipt`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })

  return data.data ?? data
}

export async function submitDonation(donationId) {
  const { data } = await http.post(`/donations/${donationId}/submit`)
  return data.data ?? data
}

export async function approveDonation(donationId) {
  const { data } = await http.post(`/donations/${donationId}/approve`)
  return data.data ?? data
}

export async function rejectDonation(donationId, reason, rejectionType) {
  const { data } = await http.post(`/donations/${donationId}/reject`, { reason, rejection_type: rejectionType })
  return data.data ?? data
}
