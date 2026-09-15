import http from './http'

export async function getProjects() {
  const { data } = await http.get('/projects')
  return data.data ?? []
}

export async function getProject(projectId) {
  const { data } = await http.get(`/projects/${projectId}`)
  return data.data ?? data
}

export async function updateProjectLocation(projectId, latitude, longitude) {
  const { data } = await http.put(`/projects/${projectId}`, { latitude, longitude })
  return data.data ?? data
}

export async function startProject(projectId) {
  const { data } = await http.post(`/projects/${projectId}/start`)
  return data
}

export async function closeProject(projectId) {
  const { data } = await http.post(`/projects/${projectId}/close`)
  return data
}

export async function deleteProject(projectId) {
  const { data } = await http.delete(`/projects/${projectId}`)
  return data
}

export async function requestProjectDeletion(projectId, reason) {
  const { data } = await http.post(`/projects/${projectId}/deletion-requests`, { reason })
  return data.data ?? data
}

export async function getProjectDeletionRequests(projectId) {
  const { data } = await http.get(`/projects/${projectId}/deletion-requests`)
  return data.data ?? []
}

export async function getPendingProjectDeletionRequests() {
  const { data } = await http.get('/deletion-requests/pending')
  return data.data ?? []
}

export async function approveProjectDeletionRequest(deletionRequestId) {
  const { data } = await http.post(`/deletion-requests/${deletionRequestId}/approve`)
  return data.data ?? data
}

export async function rejectProjectDeletionRequest(deletionRequestId, reason) {
  const { data } = await http.post(`/deletion-requests/${deletionRequestId}/reject`, { reason })
  return data.data ?? data
}
