import http from './http'

export async function getPhaseRequests(projectId) {
  const { data } = await http.get(`/projects/${projectId}/phase-requests`)
  return data.data ?? []
}

export async function getPendingPhaseRequests() {
  const { data } = await http.get('/phase-requests/pending')
  return data.data ?? []
}

export async function createPhaseRequest(projectId, formData) {
  const { data } = await http.post(`/projects/${projectId}/phase-requests`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })
  return data.data ?? data
}

export async function approvePhaseRequest(phaseRequestId) {
  const { data } = await http.post(`/phase-requests/${phaseRequestId}/approve`)
  return data.data ?? data
}

export async function rejectPhaseRequest(phaseRequestId, reason) {
  const { data } = await http.post(`/phase-requests/${phaseRequestId}/reject`, { reason })
  return data.data ?? data
}
