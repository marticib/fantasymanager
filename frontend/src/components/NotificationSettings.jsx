import { useEffect, useState } from 'react'
import { Bell, Send, Smartphone } from 'lucide-react'
import apiClient from '../api/client'
import { currentSubscription, disablePush, enablePush, isIos, isStandalone, pushSupported } from '../lib/push'
import { writeStored } from '../lib/storage'

const WHAT_YOU_GET = [
  'Ofertes noves pels teus jugadors en venda',
  'Subhastes on havies pujat que han acabat',
  'Un rival que t\'ha pres un jugador',
  'Jugadors teus amb la clàusula massa barata',
  'El mercat tanca d\'aquí una hora',
  'Compres automàtiques de clàusules',
  'Resum de la setmana (diumenge al vespre)',
]

function Row({ icon: Icon, title, children }) {
  return (
    <div className="flex flex-col gap-3 rounded-xl bg-surface-raised/40 p-4 sm:flex-row sm:items-start">
      <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-primary/15 text-primary">
        <Icon className="h-5 w-5" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="font-semibold">{title}</p>
        <div className="mt-1 text-sm text-muted-foreground">{children}</div>
      </div>
    </div>
  )
}

const buttonClass = 'min-h-10 rounded-lg px-4 text-sm font-semibold disabled:opacity-50'

export default function NotificationSettings() {
  const [channels, setChannels] = useState(null)
  const [subscribed, setSubscribed] = useState(false)
  const [busy, setBusy] = useState('')
  const [message, setMessage] = useState(null)
  const [telegramUrl, setTelegramUrl] = useState(null)

  const load = () =>
    apiClient
      .get('/notifications/channels')
      .then((res) => setChannels(res.data))
      .catch(() => setChannels(false))

  // Arriving from the bell's "Rebre-ho al mòbil" link.
  useEffect(() => {
    if (channels && window.location.hash === '#notificacions') document.getElementById('notificacions')?.scrollIntoView({ behavior: 'smooth' })
  }, [channels])

  useEffect(() => {
    load()
    currentSubscription()
      .then((s) => setSubscribed(Boolean(s)))
      .catch(() => {})
  }, [])

  const run = async (key, fn) => {
    setBusy(key)
    setMessage(null)
    try {
      await fn()
    } catch (err) {
      setMessage({ tone: 'error', text: err.response?.data?.message || err.message || "No s'ha pogut completar." })
    } finally {
      setBusy('')
    }
  }

  const togglePush = () =>
    run('push', async () => {
      if (subscribed) {
        await disablePush()
        setSubscribed(false)
        writeStored(window.localStorage, 'fantasy:push-enabled', false)
        setMessage({ tone: 'ok', text: 'Notificacions desactivades en aquest dispositiu.' })
      } else {
        await enablePush(channels.push.publicKey)
        setSubscribed(true)
        writeStored(window.localStorage, 'fantasy:push-enabled', true)
        setMessage({ tone: 'ok', text: 'Notificacions activades en aquest dispositiu.' })
      }
      load()
    })

  const connectTelegram = () =>
    run('tg-link', async () => {
      const { data } = await apiClient.post('/notifications/telegram/link')
      setTelegramUrl(data.url)
      window.open(data.url, '_blank', 'noopener')
    })

  const verifyTelegram = () =>
    run('tg-verify', async () => {
      await apiClient.post('/notifications/telegram/verify')
      setTelegramUrl(null)
      setMessage({ tone: 'ok', text: 'Telegram connectat. T\'hem enviat un missatge de benvinguda.' })
      load()
    })

  const disconnectTelegram = () =>
    run('tg-off', async () => {
      await apiClient.delete('/notifications/telegram')
      load()
    })

  const sendTest = () =>
    run('test', async () => {
      const { data } = await apiClient.post('/notifications/test')
      const parts = []
      if (data.push) parts.push(`${data.push} ${data.push === 1 ? 'dispositiu' : 'dispositius'}`)
      if (data.telegram) parts.push('Telegram')
      setMessage(
        parts.length
          ? { tone: 'ok', text: `Prova enviada a: ${parts.join(' i ')}.` }
          : { tone: 'error', text: 'No hi ha cap canal connectat on enviar-la.' },
      )
    })

  const pushBody = () => {
    if (!channels.push.configured) return <p>Encara no està activat al servidor (falten les claus; mira les instruccions de desplegament).</p>
    if (!pushSupported()) {
      return isIos() && !isStandalone() ? (
        <p>
          A l'iPhone primer has d'afegir l'app a la pantalla d'inici: botó <strong className="text-foreground">Compartir</strong> →{' '}
          <strong className="text-foreground">Afegeix a la pantalla d'inici</strong>. Després obre-la des de la icona i torna aquí.
        </p>
      ) : (
        <p>Aquest navegador no admet notificacions.</p>
      )
    }
    return (
      <>
        <p>Arriben encara que tinguis l'app tancada. {channels.push.devices > 0 && `Tens ${channels.push.devices} ${channels.push.devices === 1 ? 'dispositiu connectat' : 'dispositius connectats'}.`}</p>
        <button
          onClick={togglePush}
          disabled={busy === 'push'}
          className={`mt-3 ${buttonClass} ${subscribed ? 'border border-border text-muted-foreground hover:text-foreground' : 'bg-primary text-background hover:brightness-110'}`}
        >
          {busy === 'push' ? 'Un moment…' : subscribed ? 'Desactivar en aquest dispositiu' : 'Activar en aquest dispositiu'}
        </button>
      </>
    )
  }

  const telegramBody = () => {
    if (!channels.telegram.configured) return <p>Encara no està activat al servidor (cal crear un bot amb @BotFather; mira les instruccions de desplegament).</p>
    if (channels.telegram.connected) {
      return (
        <>
          <p>Connectat: rebràs els avisos al teu xat amb @{channels.telegram.botUsername}.</p>
          <button onClick={disconnectTelegram} disabled={busy === 'tg-off'} className={`mt-3 border border-border text-muted-foreground hover:text-bear ${buttonClass}`}>
            Desconnectar
          </button>
        </>
      )
    }
    return (
      <>
        <p>Rep els avisos com a missatges de Telegram.</p>
        {telegramUrl ? (
          <div className="mt-3 space-y-2">
            <p>
              S'ha obert el xat amb el bot. Prem <strong className="text-foreground">Iniciar</strong> (o{' '}
              <a href={telegramUrl} target="_blank" rel="noreferrer" className="text-primary underline">
                obre'l aquí
              </a>
              ) i després:
            </p>
            <button onClick={verifyTelegram} disabled={busy === 'tg-verify'} className={`bg-primary text-background hover:brightness-110 ${buttonClass}`}>
              {busy === 'tg-verify' ? 'Comprovant…' : 'Ja l\'he iniciat'}
            </button>
          </div>
        ) : (
          <button onClick={connectTelegram} disabled={busy === 'tg-link'} className={`mt-3 bg-primary text-background hover:brightness-110 ${buttonClass}`}>
            Connectar Telegram
          </button>
        )}
      </>
    )
  }

  return (
    <section id="notificacions" className="panel mt-6 p-6">
      <h2 className="font-semibold">Notificacions</h2>
      <p className="mt-1 text-sm text-muted-foreground">Rep els avisos importants al mòbil, encara que no tinguis l'app oberta.</p>

      {channels === null && <div className="skeleton mt-4 h-24 rounded-xl" />}
      {channels === false && <p className="mt-4 text-sm text-bear">No s'han pogut carregar les opcions de notificació.</p>}
      {channels && (
        <div className="mt-4 space-y-3">
          <Row icon={Smartphone} title="Mòbil i navegador">
            {pushBody()}
          </Row>
          <Row icon={Send} title="Telegram">
            {telegramBody()}
          </Row>
          <Row icon={Bell} title="Què t'avisarem">
            <ul className="mt-1 grid gap-x-6 gap-y-1 sm:grid-cols-2">
              {WHAT_YOU_GET.map((w) => (
                <li key={w}>· {w}</li>
              ))}
            </ul>
            {(subscribed || channels.telegram.connected) && (
              <button onClick={sendTest} disabled={busy === 'test'} className={`mt-3 border border-border hover:border-primary/50 hover:text-foreground ${buttonClass}`}>
                {busy === 'test' ? 'Enviant…' : 'Envia una prova'}
              </button>
            )}
          </Row>
          {message && (
            <p className={`animate-fade text-sm ${message.tone === 'error' ? 'text-bear' : 'text-bull'}`} role="status">
              {message.text}
            </p>
          )}
        </div>
      )}
    </section>
  )
}
