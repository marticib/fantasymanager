import axios from 'axios'
import { SESSION_EXPIRED, emitAppEvent } from '../lib/appEvents'

const baseURL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

export const apiClient = axios.create({
  baseURL,
  headers: { Accept: 'application/json' },
})

apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('fantasy_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('fantasy_token')
      if (!window.location.pathname.startsWith('/login')) {
        window.location.assign('/login')
      }
    }
    // The LaLiga session (not the app login) expired: the shell shows one
    // "reconnect" banner instead of every screen failing on its own.
    if (error.response?.status === 409 && error.response?.data?.code === 'LALIGA_SESSION_EXPIRED') {
      emitAppEvent(SESSION_EXPIRED)
    }
    return Promise.reject(error)
  },
)

export default apiClient
