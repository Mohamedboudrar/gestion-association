import http from './http'

export async function getSubscriptions() {
  const { data } = await http.get('/subscriptions')
  return data.data ?? []
}

export async function createSubscription(payload) {
  const { data } = await http.post('/subscriptions', payload)
  return data.data ?? data
}

export async function verifySubscription(subscriptionId) {
  const { data } = await http.post(`/subscriptions/${subscriptionId}/verify`)
  return data.data ?? data
}

export async function uploadSubscriptionReceipt(subscriptionId, file) {
  const formData = new FormData()
  formData.append('receipt', file)

  const { data } = await http.post(`/subscriptions/${subscriptionId}/receipt`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data',
    },
  })

  return data.data ?? data
}
