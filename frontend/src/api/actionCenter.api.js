import http from './http'

// One request backing both the president dashboard's Action Center card and
// PresidentLayout's Approvals sidebar badge — replaces what used to be 4-8
// separate full-list fetches (subscriptions/expenses/donations/phase
// requests, each unfiltered) filtered to "pending" client-side.
export async function getActionCenter() {
  const { data } = await http.get('/action-center')
  return data
}
