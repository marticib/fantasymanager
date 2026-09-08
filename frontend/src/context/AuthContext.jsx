import { createContext, useContext, useEffect, useState, useCallback } from 'react'
import apiClient from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [token, setToken] = useState(() => localStorage.getItem('fantasy_token'))
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)

  const loadUser = useCallback(async () => {
    if (!localStorage.getItem('fantasy_token')) {
      setLoading(false)
      return
    }
    try {
      const { data } = await apiClient.get('/auth/me')
      setUser(data)
    } catch {
      localStorage.removeItem('fantasy_token')
      setToken(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadUser()
  }, [loadUser])

  const login = async (email, password) => {
    const { data } = await apiClient.post('/auth/login', { email, password })
    localStorage.setItem('fantasy_token', data.token)
    setToken(data.token)
    setUser(data.user)
  }

  const register = async (name, email, password, password_confirmation) => {
    const { data } = await apiClient.post('/auth/register', { name, email, password, password_confirmation })
    localStorage.setItem('fantasy_token', data.token)
    setToken(data.token)
    setUser(data.user)
  }

  const logout = async () => {
    try {
      await apiClient.post('/auth/logout')
    } catch {
      // ignore — we're clearing local state regardless
    }
    localStorage.removeItem('fantasy_token')
    setToken(null)
    setUser(null)
  }

  return (
    <AuthContext.Provider value={{ token, user, loading, login, register, logout }}>{children}</AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}
