import { lazy, Suspense } from 'react'
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom'
import { AuthProvider, useAuth } from './context/AuthContext'
import Layout from './components/Layout'
import Login from './pages/Login'
import Register from './pages/Register'

// One chunk per page: the first load only downloads the page being opened
// (charts etc. come in with the pages that use them).
const Onboarding = lazy(() => import('./pages/Onboarding'))
const Dashboard = lazy(() => import('./pages/Dashboard'))
const Market = lazy(() => import('./pages/Market'))
const Team = lazy(() => import('./pages/Team'))
const Sales = lazy(() => import('./pages/Sales'))
const Clauses = lazy(() => import('./pages/Clauses'))
const Trading = lazy(() => import('./pages/Trading'))
const Standings = lazy(() => import('./pages/Standings'))
const History = lazy(() => import('./pages/History'))
const Settings = lazy(() => import('./pages/Settings'))
const PlayerDetail = lazy(() => import('./pages/PlayerDetail'))
const Players = lazy(() => import('./pages/Players'))
const RivalTeam = lazy(() => import('./pages/RivalTeam'))

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
                <Suspense fallback={<div className="p-10 text-text-muted">Carregant…</div>}>
                  <Onboarding />
                </Suspense>
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
            <Route path="/sales" element={<Sales />} />
            <Route path="/clauses" element={<Clauses />} />
            <Route path="/trading" element={<Trading />} />
            <Route path="/players" element={<Players />} />
            <Route path="/standings" element={<Standings />} />
            <Route path="/standings/:teamId" element={<RivalTeam />} />
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
