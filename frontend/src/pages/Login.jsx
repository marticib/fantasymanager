import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { LogoIcon } from '../components/Icons'

export default function Login() {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const onSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      await login(email, password)
      navigate('/')
    } catch (err) {
      setError(err.response?.data?.message || 'Credencials incorrectes.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center px-4 py-10">
      <div className="elevated w-full max-w-sm panel rounded-3xl p-8">
        <span className="mb-6 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary/60 text-background shadow-[0_10px_30px_-10px_var(--color-primary)]">
          <LogoIcon className="h-6 w-6" />
        </span>
        <h1 className="text-2xl font-bold">Fantasy Assistant</h1>
        <p className="mt-1 text-sm text-muted-foreground">Inicia sessió per veure les teves recomanacions d'avui.</p>
        <form onSubmit={onSubmit} className="mt-6 space-y-4">
          <div>
            <label className="text-xs font-medium text-muted-foreground">Email</label>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="mt-1.5 min-h-11 w-full rounded-xl border border-border bg-background/60 px-3.5 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/15"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">Contrasenya</label>
            <input
              type="password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="mt-1.5 min-h-11 w-full rounded-xl border border-border bg-background/60 px-3.5 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/15"
            />
          </div>
          {error && <p className="text-sm text-bear">{error}</p>}
          <button
            type="submit"
            disabled={loading}
            className="min-h-11 w-full rounded-xl bg-primary px-3 text-sm font-semibold text-background shadow-[0_8px_24px_-10px_var(--color-primary)] hover:brightness-110 disabled:opacity-50"
          >
            {loading ? 'Entrant…' : 'Entrar'}
          </button>
        </form>
        <p className="mt-4 text-center text-sm text-muted-foreground">
          No tens compte?{' '}
          <Link to="/register" className="font-semibold text-primary">
            Registra't
          </Link>
        </p>
      </div>
    </div>
  )
}
