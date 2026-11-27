import { useCallback, useEffect, useState } from 'react'
import { AdminView } from './components/AdminView.tsx'
import { CustomerView } from './components/CustomerView.tsx'
import { fetchHealth } from './lib/api.ts'
import './App.css'

type ActiveTab = 'customer' | 'admin'
type HealthStatus = 'checking' | 'connected' | 'offline'

function parseHashTab(hash: string): ActiveTab {
  return hash.toLowerCase() === '#admin' ? 'admin' : 'customer'
}

function App() {
  const [activeTab, setActiveTab] = useState<ActiveTab>(() =>
    parseHashTab(window.location.hash),
  )
  const [healthStatus, setHealthStatus] = useState<HealthStatus>('checking')

  const runHealthCheck = useCallback(async () => {
    try {
      const data = await fetchHealth()
      return data.status === 'ok' ? 'connected' : 'offline'
    } catch {
      return 'offline'
    }
  }, [])

  useEffect(() => {
    let active = true

    async function loadInitialHealth() {
      const nextStatus = await runHealthCheck()
      if (active) {
        setHealthStatus(nextStatus)
      }
    }

    void loadInitialHealth()

    return () => {
      active = false
    }
  }, [runHealthCheck])

  async function handleCheckHealth() {
    setHealthStatus('checking')
    const nextStatus = await runHealthCheck()
    setHealthStatus(nextStatus)
  }

  useEffect(() => {
    function handleHashChange() {
      setActiveTab(parseHashTab(window.location.hash))
    }

    window.addEventListener('hashchange', handleHashChange)
    return () => {
      window.removeEventListener('hashchange', handleHashChange)
    }
  }, [])

  function navigateTo(tab: ActiveTab) {
    const nextHash = tab === 'admin' ? '#admin' : '#customer'
    if (window.location.hash !== nextHash) {
      window.location.hash = nextHash
    }
    setActiveTab(tab)
  }

  const healthLabel =
    healthStatus === 'connected'
      ? 'Terhubung'
      : healthStatus === 'checking'
        ? 'Memeriksa'
        : 'Offline'

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="topbar-inner">
          <div className="brand">
            <span className="brand-mark" aria-hidden="true">
              BF
            </span>
            <div>
              <h1 className="brand-title">BarberFlow</h1>
              <p className="brand-subtitle">Booking &amp; Antrean Barber</p>
            </div>
          </div>

          <div className="topbar-controls">
            <nav className="main-nav" aria-label="Navigasi utama">
              <button
                type="button"
                className={`nav-tab ${activeTab === 'customer' ? 'nav-tab-active' : ''}`}
                aria-current={activeTab === 'customer' ? 'page' : undefined}
                onClick={() => navigateTo('customer')}
              >
                Pelanggan
              </button>
              <button
                type="button"
                className={`nav-tab ${activeTab === 'admin' ? 'nav-tab-active' : ''}`}
                aria-current={activeTab === 'admin' ? 'page' : undefined}
                onClick={() => navigateTo('admin')}
              >
                Admin
              </button>
            </nav>

            <button
              type="button"
              className={`health-pill health-${healthStatus}`}
              onClick={() => void handleCheckHealth()}
              aria-live="polite"
              title="Klik untuk memeriksa ulang koneksi server"
            >
              <span className="health-dot" aria-hidden="true" />
              <span>{healthLabel}</span>
            </button>
          </div>
        </div>
      </header>

      <main className="app-main">
        {activeTab === 'admin' ? <AdminView /> : <CustomerView />}
      </main>
    </div>
  )
}

export default App
