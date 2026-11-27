import { useEffect, useState } from 'react'
import { API_BASE_URL, fetchHealth, type HealthResponse } from './lib/api.ts'
import './App.css'

type HealthState =
  | { status: 'loading' }
  | { status: 'connected'; data: HealthResponse }
  | { status: 'error'; message: string }

function App() {
  const [state, setState] = useState<HealthState>({ status: 'loading' })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    let active = true

    async function runHealthCheck() {
      try {
        const data = await fetchHealth()
        if (!active) return

        if (data.status !== 'ok') {
          setState({
            status: 'error',
            message: `Backend melaporkan status tidak sehat (${data.status}).`,
          })
          return
        }

        setState({ status: 'connected', data })
      } catch (error) {
        if (!active) return

        const message =
          error instanceof Error
            ? error.message
            : 'Terjadi kesalahan saat memeriksa koneksi backend.'
        setState({ status: 'error', message })
      }
    }

    void runHealthCheck()

    return () => {
      active = false
    }
  }, [attempt])

  function handleRetry() {
    setState({ status: 'loading' })
    setAttempt((current) => current + 1)
  }

  return (
    <main className="page">
      <section className="card" aria-labelledby="app-title">
        <header className="card-header">
          <p className="eyebrow">Stage 1 Foundation</p>
          <h1 id="app-title" className="title">
            BarberFlow
          </h1>
          <p className="subtitle">
            Status koneksi frontend ke layanan API backend Laravel.
          </p>
        </header>

        <div
          className={`status-panel status-${state.status}`}
          role={state.status === 'error' ? 'alert' : 'status'}
          aria-live="polite"
        >
          {state.status === 'loading' && (
            <div className="status-row">
              <span className="status-dot dot-loading" aria-hidden="true" />
              <p className="status-label">Memeriksa koneksi...</p>
            </div>
          )}

          {state.status === 'connected' && (
            <>
              <div className="status-row">
                <span className="status-dot dot-connected" aria-hidden="true" />
                <p className="status-label">Terhubung</p>
              </div>
              <dl className="meta-list">
                <div className="meta-item">
                  <dt>Service</dt>
                  <dd>
                    <code>{state.data.service}</code>
                  </dd>
                </div>
                <div className="meta-item">
                  <dt>Database</dt>
                  <dd>
                    <code>{state.data.database}</code>
                  </dd>
                </div>
                <div className="meta-item">
                  <dt>API Base URL</dt>
                  <dd>
                    <code>{API_BASE_URL}</code>
                  </dd>
                </div>
              </dl>
            </>
          )}

          {state.status === 'error' && (
            <div className="error-block">
              <div className="status-row">
                <span className="status-dot dot-error" aria-hidden="true" />
                <p className="status-label">Koneksi Gagal</p>
              </div>
              <p className="error-message">{state.message}</p>
              <p className="error-target">
                Target API: <code>{API_BASE_URL}/api/health</code>
              </p>
            </div>
          )}
        </div>

        <footer className="card-footer">
          <button
            type="button"
            className="retry-button"
            onClick={handleRetry}
            disabled={state.status === 'loading'}
          >
            {state.status === 'loading' ? 'Memeriksa...' : 'Coba Lagi'}
          </button>
        </footer>
      </section>
    </main>
  )
}

export default App
