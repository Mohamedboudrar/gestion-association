const TOKEN_KEY = 'auth_token'
const USER_KEY = 'auth_user'
const REMEMBER_KEY = 'auth_remember'

function getStorage(remember) {
  return remember ? localStorage : sessionStorage
}

export function persistAuth({ token, user, remember }) {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
  sessionStorage.removeItem(TOKEN_KEY)
  sessionStorage.removeItem(USER_KEY)

  const storage = getStorage(remember)
  storage.setItem(TOKEN_KEY, token)
  storage.setItem(USER_KEY, JSON.stringify(user))
  localStorage.setItem(REMEMBER_KEY, JSON.stringify(remember))
}

export function readStoredAuth() {
  const remembered = JSON.parse(localStorage.getItem(REMEMBER_KEY) ?? 'false')
  const primaryStorage = remembered ? localStorage : sessionStorage
  const secondaryStorage = remembered ? sessionStorage : localStorage

  const token = primaryStorage.getItem(TOKEN_KEY) ?? secondaryStorage.getItem(TOKEN_KEY)
  const rawUser = primaryStorage.getItem(USER_KEY) ?? secondaryStorage.getItem(USER_KEY)

  return {
    token,
    user: rawUser ? JSON.parse(rawUser) : null,
    remembered,
  }
}

export function clearStoredAuth() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(USER_KEY)
  localStorage.removeItem(REMEMBER_KEY)
  sessionStorage.removeItem(TOKEN_KEY)
  sessionStorage.removeItem(USER_KEY)
}
