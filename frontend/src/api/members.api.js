import http from './http'

export async function getMembers() {
  const { data } = await http.get('/members')
  return data.data ?? []
}

export async function createMember(payload) {
  const { data } = await http.post('/members', payload)
  return data.data ?? data
}

export async function updateMember(memberId, payload) {
  const { data } = await http.put(`/members/${memberId}`, payload)
  return data.data ?? data
}

export async function deleteMember(memberId) {
  const { data } = await http.delete(`/members/${memberId}`)
  return data
}
