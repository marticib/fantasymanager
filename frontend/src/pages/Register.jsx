import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { LogoIcon } from '../components/Icons'

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
    <div className="flex min-h-screen items-center justify-center px-4 py-10">
      <div className="elevated w-full max-w-sm panel rounded-3xl p-8">
        <span className="mb-6 flex h-12 w-12 items-center justify-center rounded-2xl bg-gradient-to-br from-primary to-primary/60 text-background shadow-[0_10px_30px_-10px_var(--color-primary)]">
          <LogoIcon className="h-6 w-6" />
        </span>
        <h1 className="text-2xl font-bold">Crea el teu compte</h1>
        <p className="mt-1 text-sm text-muted-foreground">Aquest compte és només per accedir a l'app — no és el teu compte de LALIGA Fantasy.</p>
        <form onSubmit={onSubmit} className="mt-6 space-y-4">
          <div>
            <label className="text-xs font-medium text-muted-foreground">Nom</label>
            <input
              required
              value={form.name}
              onChange={update('name')}
              className="mt-1.5 min-h-11 w-full rounded-xl border border-border bg-background/60 px-3.5 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/15"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">Email</label>
            <input
              type="email"
              required
              value={form.email}
              onChange={update('email')}
              className="mt-1.5 min-h-11 w-full rounded-xl border border-border bg-background/60 px-3.5 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/15"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">Contrasenya</label>
            <input
              type="password"
              required
              value={form.password}
              onChange={update('password')}
              className="mt-1.5 min-h-11 w-full rounded-xl border border-border bg-background/60 px-3.5 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/15"
            />
          </div>
          <div>
            <label className="text-xs font-medium text-muted-foreground">Confirma la contrasenya</label>
            <input
              type="password"
              required
              value={form.password_confirmation}
              onChange={update('password_confirmation')}
              className="mt-1.5 min-h-11 w-full rounded-xl border border-border bg-background/60 px-3.5 text-sm outline-none focus:border-primary focus:ring-4 focus:ring-primary/15"
            />
          </div>
          {error && <p className="text-sm text-bear">{error}</p>}
          <button
            type="submit"
            disabled={loading}
            className="min-h-11 w-full rounded-xl bg-primary px-3 text-sm font-semibold text-background shadow-[0_8px_24px_-10px_var(--color-primary)] hover:brightness-110 disabled:opacity-50"
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
