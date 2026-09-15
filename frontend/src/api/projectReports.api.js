import http from './http'

export async function getProjectReports(projectId) {
  const { data } = await http.get(`/projects/${projectId}/reports`)
  return data.data ?? []
}
