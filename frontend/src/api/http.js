import axios from 'axios'

const http = axios.create({
  // `||` (not `??`) is deliberate — an .env file with `VITE_API_BASE_URL=`
  // (present but empty) must fall back too, not just an unset/undefined value.
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api',
  headers: {
    Accept: 'application/json',
  },
})

http.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token') ?? sessionStorage.getItem('auth_token')

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  return config
})

export default http
