import http from './http'

export async function getNotifications(params = {}) {
  const { data } = await http.get('/notifications', { params })
  return data
}

export async function markNotificationRead(notificationId) {
  const { data } = await http.post(`/notifications/${notificationId}/read`)
  return data.data ?? data
}

export async function markAllNotificationsRead() {
  const { data } = await http.post('/notifications/read-all')
  return data
}

export async function deleteNotification(notificationId) {
  const { data } = await http.delete(`/notifications/${notificationId}`)
  return data
}
