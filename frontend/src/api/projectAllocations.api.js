import http from './http'

export async function getProjectAllocations(projectId) {
  const { data } = await http.get(`/projects/${projectId}/allocations`)
  return data.data ?? []
}

export async function createProjectAllocation(projectId, formData) {
  const { data } = await http.post(`/projects/${projectId}/allocations`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })
  return data.data ?? data
}
