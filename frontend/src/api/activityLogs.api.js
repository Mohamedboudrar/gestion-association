import http from './http'

export async function getActivityLogs(params = {}) {
  const { data } = await http.get('/activity-logs', { params })
  return data
}

export async function getActivityLogFilters() {
  const { data } = await http.get('/activity-logs/filters')
  return data
}

export async function getActivityLog(id) {
  const { data } = await http.get(`/activity-logs/${id}`)
  return data.data ?? data
}
