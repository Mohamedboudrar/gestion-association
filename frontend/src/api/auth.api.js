import http from './http'

export async function login(payload) {
  const { data } = await http.post('/login', payload)
  return data
}

export async function getMe() {
  const { data } = await http.get('/me')
  return data
}

export async function logout() {
  const { data } = await http.post('/logout')
  return data
}

// Passkey login for subscribers — issues the same kind of Sanctum token as
// login() above, against the same User model, so it feeds the exact same
// AuthContext.login pipeline (see loginWithPasskey in AuthContext.jsx).
export async function passkeyLogin(passkey) {
  const { data } = await http.post('/member/login', { passkey })
  return data
}

export async function requestPasskeyReset(email) {
  const { data } = await http.post('/member/forgot-passkey', { email })
  return data
}

// The caller's own profile/subscription/donation summary — safe-by-construction
// on the backend (always scoped to auth()->user()->member, no id parameter).
// Reused as a data source by DashboardPage/DonationsPage/ProfilePage for a
// plain subscriber, in place of the bureau-wide endpoints those pages use
// for bureau/committee users.
export async function getMemberDashboard() {
  const { data } = await http.get('/member/dashboard')
  return data
}
