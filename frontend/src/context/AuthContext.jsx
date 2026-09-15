import { useEffect, useMemo, useState } from 'react'
import { getMe, login as loginRequest, logout as logoutRequest, passkeyLogin } from '../api/auth.api'
import { AuthContext } from './auth-context'
import { clearStoredAuth, persistAuth, readStoredAuth } from '../lib/authStorage'

export function AuthProvider({ children }) {
  const storedAuth = readStoredAuth()
  const [user, setUser] = useState(storedAuth.user)
  const [isAuthenticated, setIsAuthenticated] = useState(Boolean(storedAuth.token))
  const [isBootstrapping, setIsBootstrapping] = useState(Boolean(storedAuth.token))

  useEffect(() => {
    async function bootstrap() {
      if (!storedAuth.token) {
        setIsBootstrapping(false)
        return
      }

      try {
        const currentUser = await getMe()
        setUser(currentUser)
        setIsAuthenticated(true)
        persistAuth({
          token: storedAuth.token,
          user: currentUser,
          remember: storedAuth.remembered,
        })
      } catch {
        clearStoredAuth()
        setUser(null)
        setIsAuthenticated(false)
      } finally {
        setIsBootstrapping(false)
      }
    }

    bootstrap()
  }, [storedAuth.remembered, storedAuth.token])

  // Shared by both login paths below: persist the token first (so /api/me
  // carries the Bearer header), fetch the authoritative user, then persist
  // again with it. One Laravel User model, two ways to obtain a token for
  // it (email+password or passkey) — same session shape either way.
  async function finishLogin(token, remember) {
    persistAuth({ token, user: null, remember })

    const currentUser = await getMe()

    persistAuth({ token, user: currentUser, remember })

    setUser(currentUser)
    setIsAuthenticated(true)
  }

  async function login(credentials, remember) {
    const auth = await loginRequest(credentials)
    await finishLogin(auth.token, remember)
  }

  async function loginWithPasskey(passkey, remember) {
    const auth = await passkeyLogin(passkey)
    await finishLogin(auth.token, remember)
  }

  async function logout() {
    try {
      await logoutRequest()
    } catch {
      // noop
    } finally {
      clearStoredAuth()
      setUser(null)
      setIsAuthenticated(false)
    }
  }

  const value = useMemo(
    () => ({
      user,
      isAuthenticated,
      isBootstrapping,
      login,
      loginWithPasskey,
      logout,
    }),
    [user, isAuthenticated, isBootstrapping],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
