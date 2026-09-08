import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import Layout from './components/Layout'
import Login from './pages/Login'
import Register from './pages/Register'
import Onboarding from './pages/Onboarding'
import Dashboard from './pages/Dashboard'
import Market from './pages/Market'
import Team from './pages/Team'
import Lineup from './pages/Lineup'
import Clauses from './pages/Clauses'
import Trading from './pages/Trading'
import Standings from './pages/Standings'
import History from './pages/History'
import Settings from './pages/Settings'
import PlayerDetail from './pages/PlayerDetail'
import Players from './pages/Players'

function RequireAuth({ children }) {
  const { token, loading } = useAuth()
  if (loading) return <div className="p-10 text-text-muted">Carregant…</div>
  if (!token) return <Navigate to="/login" replace />
  return children
}

export default function App() {
  return (
    <BrowserRouter>
      <AuthProvider>
        <Routes>
          <Route path="/login" element={<Login />} />
          <Route path="/register" element={<Register />} />
          <Route
            path="/onboarding"
            element={
              <RequireAuth>
                <Onboarding />
              </RequireAuth>
            }
          />
          <Route
            element={
              <RequireAuth>
                <Layout />
              </RequireAuth>
            }
          >
            <Route path="/" element={<Dashboard />} />
            <Route path="/market" element={<Market />} />
            <Route path="/team" element={<Team />} />
            <Route path="/lineup" element={<Lineup />} />
            <Route path="/clauses" element={<Clauses />} />
            <Route path="/trading" element={<Trading />} />
            <Route path="/players" element={<Players />} />
            <Route path="/standings" element={<Standings />} />
            <Route path="/history" element={<History />} />
            <Route path="/settings" element={<Settings />} />
            <Route path="/players/:id" element={<PlayerDetail />} />
          </Route>
          <Route path="*" element={<Navigate to="/" replace />} />
        </Routes>
      </AuthProvider>
    </BrowserRouter>
  )
}
