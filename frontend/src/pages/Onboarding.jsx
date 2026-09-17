import { useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import apiClient from '../api/client'
import { bookmarkletHref } from '../bookmarklet/tokenGrabber'

export default function Onboarding() {
  const navigate = useNavigate()
  const [account, setAccount] = useState(null)
  const [accessToken, setAccessToken] = useState('')
  const [refreshToken, setRefreshToken] = useState('')
  const [tokenError, setTokenError] = useState('')
  const [tokenLoading, setTokenLoading] = useState(false)

  const [oauthUrl, setOauthUrl] = useState('')
  const [oauthStarting, setOauthStarting] = useState(false)
  const [oauthStartError, setOauthStartError] = useState('')
  const [pastedRedirect, setPastedRedirect] = useState('')
  const [oauthFinishing, setOauthFinishing] = useState(false)
  const [oauthFinishError, setOauthFinishError] = useState('')

  const [leagues, setLeagues] = useState(null)
  const [leaguesError, setLeaguesError] = useState('')
  const [leaguesLoading, setLeaguesLoading] = useState(false)
  const [teamOverride, setTeamOverride] = useState('')
  const [selectingLeagueId, setSelectingLeagueId] = useState(null)
  const [selectError, setSelectError] = useState('')

  const [syncMessage, setSyncMessage] = useState('')

  const loadAccount = async () => {
    const { data } = await apiClient.get('/fantasy-account')
    setAccount(data)
    return data
  }

  useEffect(() => {
    loadAccount()
  }, [])

  const startOAuth = async () => {
    setOauthStarting(true)
    setOauthStartError('')
    try {
      const { data } = await apiClient.post('/fantasy-account/oauth/start')
      setOauthUrl(data.url)
      window.open(data.url, '_blank', 'noopener')
    } catch (err) {
      setOauthStartError(err.response?.data?.message || 'No s\'ha pogut generar l\'enllaç de login.')
    } finally {
      setOauthStarting(false)
    }
  }

  // Guards against a double-click firing two submits before React commits
  // the `disabled` state to the DOM: the PKCE session is single-use
  // (Cache::pull), so a second request right behind the first always finds
  // it already consumed and reports "expired" even though barely any time
  // passed — confirmed live, not a timing issue with the login itself.
  const finishingRef = useRef(false)

  const finishOAuth = async (e) => {
    e.preventDefault()
    if (finishingRef.current) return
    finishingRef.current = true
    setOauthFinishing(true)
    setOauthFinishError('')
    try {
      await apiClient.post('/fantasy-account/oauth/finish', { redirect: pastedRedirect })
      setOauthUrl('')
      setPastedRedirect('')
      await loadAccount()
    } catch (err) {
      setOauthFinishError(err.response?.data?.message || 'No s\'ha pogut completar el login.')
    } finally {
      setOauthFinishing(false)
      finishingRef.current = false
    }
  }

  const saveTokens = async (e) => {
    e.preventDefault()
    setTokenError('')
    setTokenLoading(true)
    try {
      await apiClient.post('/fantasy-account/tokens', {
        access_token: accessToken,
        refresh_token: refreshToken || null,
      })
      await loadAccount()
    } catch (err) {
      setTokenError(err.response?.data?.error || err.response?.data?.message || 'Tokens invàlids.')
    } finally {
      setTokenLoading(false)
    }
  }

  const loadLeagues = async () => {
    setLeaguesLoading(true)
    setLeaguesError('')
    try {
      const { data } = await apiClient.get('/leagues')
      setLeagues(data.data)
    } catch (err) {
      setLeaguesError(err.response?.data?.message || 'No s\'han pogut carregar les lligues.')
    } finally {
      setLeaguesLoading(false)
    }
  }

  const selectLeague = async (league) => {
    setSelectingLeagueId(league.id)
    setSelectError('')
    try {
      await apiClient.post(`/leagues/${league.id}/select`, {
        team_external_id: teamOverride || undefined,
      })
      await loadAccount()
    } catch (err) {
      setSelectError(err.response?.data?.message || 'No s\'ha pogut seleccionar la lliga.')
    } finally {
      setSelectingLeagueId(null)
    }
  }

  const runInitialSync = async () => {
    setSyncMessage('Sincronitzant… això pot trigar un minut la primera vegada.')
    try {
      await apiClient.post('/fantasy/sync')
      setSyncMessage('Sincronització en cua. Ves al dashboard en un minut.')
    } catch (err) {
      setSyncMessage(err.response?.data?.message || 'Error llançant la sincronització.')
    }
  }

  return (
    <div className="mx-auto min-h-screen max-w-2xl px-4 py-10">
      <h1 className="text-2xl font-bold">Configuració inicial</h1>
      <p className="mt-1 text-sm text-muted-foreground">
        Tres passos: connecta la teva sessió de LALIGA Fantasy, tria la lliga i sincronitza.
      </p>

      <section className="mt-8 rounded-2xl border border-border bg-surface p-6">
        <h2 className="font-semibold">1. Sessió de LALIGA Fantasy</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Enganxa el <code className="rounded bg-background px-1">access_token</code> (i si el tens, el{' '}
          <code className="rounded bg-background px-1">refresh_token</code>) d'una sessió ja iniciada. Mai et demanem la teva
          contrasenya de LALIGA — no la guardem ni la veiem.
        </p>
        {account?.hasTokens ? (
          <p className="mt-3 text-sm text-bull">✓ Sessió configurada{account.nickname ? ` (${account.nickname})` : ''}.</p>
        ) : (
          <>
            <div className="mt-4 rounded-xl border border-primary/30 bg-primary/5 p-4">
              <p className="text-sm font-semibold">Recomanat: inicia sessió amb LaLiga</p>
              <p className="mt-1 text-xs text-muted-foreground">
                És el login real de LaLiga (funciona amb Google/Apple, sense contrasenya pròpia). Com que Azure B2C
                de LaLiga només accepta tornar a la seva pròpia app, no pot ser un sol clic — cal un pas manual per
                copiar el codi, però la resta ho fem nosaltres i la sessió dura fins a 90 dies.
              </p>

              {!oauthUrl ? (
                <button
                  onClick={startOAuth}
                  disabled={oauthStarting}
                  className="mt-3 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-bg hover:opacity-90 disabled:opacity-50"
                >
                  {oauthStarting ? 'Generant…' : '1. Obrir login de LaLiga'}
                </button>
              ) : (
                <div className="mt-3 space-y-3">
                  <p className="text-xs text-muted-foreground">
                    S'ha obert una pestanya nova — inicia sessió amb LaLiga (Google, Apple o email). Quan acabi, el
                    navegador intentarà obrir <span className="font-mono">authredirect://…</span> i fallarà —{' '}
                    <strong>això és normal</strong>. Amb les DevTools obertes (pestanya Network, "Preserve log"
                    activat), busca la petició a <span className="font-mono">authredirect://com.lfp.laligafantasy…</span>{' '}
                    (apareix com a cancel·lada) i copia'n la URL completa.
                  </p>
                  <a href={oauthUrl} target="_blank" rel="noopener noreferrer" className="text-xs text-primary hover:underline">
                    Tornar a obrir l'enllaç de login
                  </a>
                  <form onSubmit={finishOAuth} className="space-y-2">
                    <textarea
                      required
                      placeholder="authredirect://com.lfp.laligafantasy?code=... (o només el codi)"
                      value={pastedRedirect}
                      onChange={(e) => setPastedRedirect(e.target.value)}
                      rows={2}
                      className="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs font-mono outline-none focus:border-primary"
                    />
                    {oauthFinishError && <p className="text-sm text-bear">{oauthFinishError}</p>}
                    <button
                      type="submit"
                      disabled={oauthFinishing}
                      className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-bg hover:opacity-90 disabled:opacity-50"
                    >
                      {oauthFinishing ? 'Connectant…' : '2. Connectar'}
                    </button>
                  </form>
                </div>
              )}
              {oauthStartError && <p className="mt-2 text-sm text-bear">{oauthStartError}</p>}
            </div>

            <details className="mt-4 rounded-xl border border-border p-4">
              <summary className="cursor-pointer text-sm font-semibold text-muted-foreground">
                Alternativa: bookmarklet (si ja tens sessió oberta a LaLiga)
              </summary>
              <p className="mt-2 text-xs text-muted-foreground">
                Escolta la teva pròpia sessió del navegador i agafa els tokens sense haver de tornar a iniciar sessió.
              </p>
              <ol className="mt-3 list-decimal space-y-1 pl-4 text-xs text-muted-foreground">
                <li>
                  Arrossega aquest botó a la barra de marcadors:{' '}
                  <a
                    href={bookmarkletHref}
                    onClick={(e) => e.preventDefault()}
                    className="inline-block cursor-grab rounded-md bg-primary px-3 py-1 font-semibold text-bg active:cursor-grabbing"
                  >
                    🎣 Agafa el token
                  </a>
                </li>
                <li>
                  Obre <span className="font-mono">fantasy.laliga.com</span> en una altra pestanya (ja amb sessió iniciada).
                </li>
                <li>Clica el marcador — apareixerà un panell a dalt a la dreta d'aquella pestanya.</li>
                <li>Navega una mica (mercat, plantilla…) fins que el panell mostri els dos tokens.</li>
                <li>Copia'ls amb els botons "Copia" i enganxa'ls aquí sota.</li>
              </ol>
            </details>

            <details className="mt-3 rounded-xl border border-border p-4">
              <summary className="cursor-pointer text-sm font-semibold text-muted-foreground">Alternativa: enganxar manualment</summary>
              <form onSubmit={saveTokens} className="mt-3 space-y-3">
                <textarea
                  required
                  placeholder="access_token"
                  value={accessToken}
                  onChange={(e) => setAccessToken(e.target.value)}
                  rows={3}
                  className="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs font-mono outline-none focus:border-primary"
                />
                <textarea
                  placeholder="refresh_token (opcional, però recomanat)"
                  value={refreshToken}
                  onChange={(e) => setRefreshToken(e.target.value)}
                  rows={2}
                  className="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs font-mono outline-none focus:border-primary"
                />
                {tokenError && <p className="text-sm text-bear">{tokenError}</p>}
                <button
                  type="submit"
                  disabled={tokenLoading}
                  className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-bg hover:opacity-90 disabled:opacity-50"
                >
                  {tokenLoading ? 'Desant…' : 'Desar sessió'}
                </button>
              </form>
            </details>
          </>
        )}
      </section>

      <section className="mt-6 rounded-2xl border border-border bg-surface p-6">
        <h2 className="font-semibold">2. Tria la teva lliga</h2>
        <button
          onClick={loadLeagues}
          disabled={!account?.hasTokens || leaguesLoading}
          className="mt-3 rounded-lg bg-primary/15 px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/25 disabled:opacity-40"
        >
          {leaguesLoading ? 'Carregant…' : 'Detectar les meves lligues'}
        </button>
        {leaguesError && <p className="mt-2 text-sm text-bear">{leaguesError}</p>}

        {leagues && (
          <div className="mt-4 space-y-2">
            <input
              placeholder="ID d'equip manual (només si l'autodetecció falla)"
              value={teamOverride}
              onChange={(e) => setTeamOverride(e.target.value)}
              className="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs outline-none focus:border-primary"
            />
            {leagues.map((league) => (
              <div
                key={league.id}
                className="flex items-center justify-between rounded-lg border border-border px-4 py-3"
              >
                <div>
                  <p className="text-sm font-medium">{league.name}</p>
                  <p className="text-xs text-muted-foreground">
                    {league.mode} · {league.teamCount} equips
                  </p>
                </div>
                <button
                  onClick={() => selectLeague(league)}
                  disabled={selectingLeagueId === league.id}
                  className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${
                    account?.activeLeagueId === league.id
                      ? 'bg-bull/15 text-bull'
                      : 'bg-primary/15 text-primary hover:bg-primary/25'
                  }`}
                >
                  {account?.activeLeagueId === league.id
                    ? '✓ Seleccionada'
                    : selectingLeagueId === league.id
                      ? 'Seleccionant…'
                      : 'Seleccionar'}
                </button>
              </div>
            ))}
            {selectError && <p className="text-sm text-bear">{selectError}</p>}
          </div>
        )}
      </section>

      <section className="mt-6 rounded-2xl border border-border bg-surface p-6">
        <h2 className="font-semibold">3. Primera sincronització</h2>
        <p className="mt-1 text-sm text-muted-foreground">
          Importa la teva plantilla, saldo, mercat i jugadors, i genera les primeres recomanacions.
        </p>
        <button
          onClick={runInitialSync}
          disabled={!account?.activeTeamId}
          className="mt-3 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-bg hover:opacity-90 disabled:opacity-40"
        >
          Sincronitzar ara
        </button>
        {syncMessage && <p className="mt-2 text-sm text-muted-foreground">{syncMessage}</p>}
        {account?.activeTeamId && (
          <button onClick={() => navigate('/')} className="ml-3 text-sm font-semibold text-primary hover:underline">
            Ves al dashboard →
          </button>
        )}
      </section>
    </div>
  )
}
