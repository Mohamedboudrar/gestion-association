import i18n from '../i18n'

// `||` (not `??`) — an .env file with `VITE_API_BASE_URL=` (present but empty)
// must fall back too, not just an unset/undefined value. See api/http.js.
const API_BASE_URL = import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api'

function authHeaders() {
  const token = localStorage.getItem('auth_token') ?? sessionStorage.getItem('auth_token')

  return token ? { Authorization: `Bearer ${token}` } : {}
}

export function getReportDownloadUrl(path) {
  return `${API_BASE_URL}${path}`
}

export function getReportRequestConfig() {
  return {
    headers: authHeaders(),
  }
}

// Fetches a report and returns its PDF blob — or throws a user-friendly error
// if the server responded with something other than a successful file (e.g. a
// JSON 403/404/500 body), so callers never hand a non-PDF response to
// `window.open`/blob-download logic.
export async function downloadReportBlob(path) {
  const response = await fetch(getReportDownloadUrl(path), getReportRequestConfig())

  if (!response.ok) {
    let message = i18n.t('reports:page.downloadError')

    try {
      const body = await response.json()
      message = body?.message ?? message
    } catch {
      // Error response wasn't JSON (e.g. an HTML error page) — keep the generic message.
    }

    throw new Error(message)
  }

  return response.blob()
}
