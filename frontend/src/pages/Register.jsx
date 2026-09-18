import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'

export default function Register() {
  const { register } = useAuth()
  const navigate = useNavigate()
  const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' })
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  const update = (key) => (e) => setForm((f) => ({ ...f, [key]: e.target.value }))

  const onSubmit = async (e) => {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      await register(form.name, form.email, form.password, form.password_confirmation)
      navigate('/onboarding')
    } catch (err) {
      const errors = err.response?.data?.errors
      setError(errors ? Object.values(errors).flat().join(' ') : 'No s\'ha pogut crear el compte.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-background px-4">
      <div className="w-full max-w-sm rounded-2xl border border-border bg-surface p-8">
        <h1 className="text-xl font-bold">Crea el teu compte</h1>
        <p className="mt-1 text-sm text-muted-foreground">Aquest compte és només per accedir a l'app — no és el teu compte de LALIGA Fantasy.</p>
        <form onSubmit={onSubmit} className="mt-6 space-y-4">
          <div>
            <label className="text-xs font-medium text-muted-foreground">Nom</label>
            <input
              required
              value={form.name}
              onChange={update('name')}
              className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">Email</label>
            <input
              type="email"
              required
              value={form.email}
              onChange={update('email')}
              className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">Contrasenya</label>
            <input
              type="password"
              required
              value={form.password}
              onChange={update('password')}
              className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">Confirma la contrasenya</label>
            <input
              type="password"
              required
              value={form.password_confirmation}
              onChange={update('password_confirmation')}
              className="mt-1 w-full rounded-lg border border-border bg-background px-3 py-2 text-sm outline-none focus:border-primary"
            />
          </div>
          {error && <p className="text-sm text-bear">{error}</p>}
          <button
            type="submit"
            disabled={loading}
            className="w-full rounded-lg bg-primary px-3 py-2 text-sm font-semibold text-background hover:opacity-90 disabled:opacity-50"
          >
            {loading ? 'Creant…' : 'Crear compte'}
          </button>
        </form>
        <p className="mt-4 text-center text-sm text-muted-foreground">
          Ja tens compte?{' '}
          <Link to="/login" className="font-semibold text-primary">
            Inicia sessió
          </Link>
        </p>
      </div>
    </div>
  )
}
