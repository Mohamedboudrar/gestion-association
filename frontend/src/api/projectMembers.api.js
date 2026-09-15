import http from './http'

export async function getProjectMembers(projectId) {
  const { data } = await http.get(`/projects/${projectId}/members`)
  return data
}

export async function getCommitteeHistory(projectId) {
  const { data } = await http.get(`/projects/${projectId}/committee-history`)
  return data
}

export async function assignProjectMember(projectId, payload) {
  const { data } = await http.post(`/projects/${projectId}/members`, payload)
  return data
}

export async function removeProjectMember(projectId, memberId, reason) {
  const { data } = await http.delete(`/projects/${projectId}/members/${memberId}`, {
    data: { reason },
  })
  return data
}

export async function replaceProjectMember(projectId, memberId, payload) {
  const { data } = await http.post(`/projects/${projectId}/members/${memberId}/replace`, payload)
  return data
}

export async function resignProjectMember(projectId, memberId, reason) {
  const { data } = await http.post(`/projects/${projectId}/members/${memberId}/resign`, { reason })
  return data
}
