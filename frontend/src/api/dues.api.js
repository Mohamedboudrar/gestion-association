import http from './http'

export async function getDues(params) {
  const { data } = await http.get('/dues', { params })
  return data.data ?? []
}

export async function waiveDue(dueId, reason) {
  const { data } = await http.post(`/dues/${dueId}/waive`, { reason })
  return data.data ?? data
}
