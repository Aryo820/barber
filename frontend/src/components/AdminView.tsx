import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type FormEvent,
} from 'react'
import {
  ApiError,
  fetchAdminMe,
  fetchAdminQueue,
  updateAppointmentStatus,
  updateBarberStatus,
  type AdminProfile,
  type BarberStatus,
  type QueueBoardResponse,
  type QueueItem,
  type UpdateAppointmentStatusInput,
} from '../lib/api.ts'
import { getSupabaseClient } from '../lib/supabase.ts'

const POLL_INTERVAL_MS = 5000

const BARBER_STATUS_OPTIONS: Array<{
  value: BarberStatus
  label: string
  activeClass: string
}> = [
  { value: 'idle', label: 'Tersedia', activeClass: 'segmented-btn-idle' },
  { value: 'busy', label: 'Sibuk', activeClass: 'segmented-btn-busy' },
  { value: 'break', label: 'Istirahat', activeClass: 'segmented-btn-break' },
]

function formatSyncTime(date: Date): string {
  return new Intl.DateTimeFormat('id-ID', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    hourCycle: 'h23',
  })
    .format(date)
    .replace(/\./g, ':')
}

function formatOnlineSlotLabel(item: QueueItem): string {
  if (item.slot) {
    return `Online ${item.slot}`
  }
  if (item.scheduled_time) {
    const parsed = new Date(item.scheduled_time)
    if (!Number.isNaN(parsed.getTime())) {
      const hhmm = new Intl.DateTimeFormat('id-ID', {
        timeZone: 'Asia/Jakarta',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
      })
        .format(parsed)
        .replace(/\./g, ':')
      return `Online ${hhmm}`
    }
  }
  return 'Online'
}

function getBarberStatusBadge(status: BarberStatus): {
  label: string
  className: string
} {
  switch (status) {
    case 'idle':
      return { label: 'Tersedia', className: 'badge-emerald' }
    case 'busy':
      return { label: 'Sibuk', className: 'badge-amber' }
    case 'break':
      return { label: 'Istirahat', className: 'badge-slate' }
  }
}

function extractErrorMessage(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    const firstValidation = error.errors
      ? Object.values(error.errors).flat().find(Boolean)
      : undefined
    return firstValidation || error.message || fallback
  }
  if (error instanceof Error && error.message.trim()) {
    return error.message
  }
  return fallback
}

type AuthState =
  | { status: 'checking' }
  | { status: 'unauthenticated'; notice?: string }
  | { status: 'verifying'; email: string | null }
  | { status: 'forbidden'; email: string | null; message: string }
  | { status: 'verify_error'; email: string | null; message: string }
  | { status: 'authenticated'; accessToken: string; profile: AdminProfile }

function getKnownEmail(state: AuthState): string | null {
  if (state.status === 'authenticated') return state.profile.email
  if ('email' in state) return state.email
  return null
}

export function AdminView() {
  const supabase = useMemo(() => getSupabaseClient(), [])

  const [authState, setAuthState] = useState<AuthState>({ status: 'checking' })
  const [emailInput, setEmailInput] = useState('')
  const [passwordInput, setPasswordInput] = useState('')
  const [signingIn, setSigningIn] = useState(false)
  const [loginError, setLoginError] = useState<string | null>(null)

  // Admin board state
  const [board, setBoard] = useState<QueueBoardResponse | null>(null)
  const [boardLoading, setBoardLoading] = useState(false)
  const [boardError, setBoardError] = useState<string | null>(null)
  const [actionError, setActionError] = useState<string | null>(null)
  const [lastSyncedAt, setLastSyncedAt] = useState<string | null>(null)
  const [busyBarbers, setBusyBarbers] = useState<Record<number, boolean>>({})
  const [busyRows, setBusyRows] = useState<Record<number, boolean>>({})
  const [selectedBarberByRow, setSelectedBarberByRow] = useState<
    Record<number, number | null>
  >({})

  const isMountedRef = useRef(true)
  const verifySeqRef = useRef(0)
  const sessionEpochRef = useRef(0)
  const accessTokenRef = useRef<string | null>(null)
  const authStateRef = useRef<AuthState>(authState)
  const boardInFlightRef = useRef(false)
  const pendingBoardRefreshRef = useRef(false)

  const setAuthStateSynced = useCallback(
    (next: AuthState | ((prev: AuthState) => AuthState)) => {
      setAuthState((prev) => {
        const resolved = typeof next === 'function' ? next(prev) : next
        authStateRef.current = resolved
        return resolved
      })
    },
    [],
  )

  const invalidateSession = useCallback(
    (nextAuthState: AuthState | ((prev: AuthState) => AuthState)) => {
      sessionEpochRef.current += 1
      verifySeqRef.current += 1
      accessTokenRef.current = null
      boardInFlightRef.current = false
      pendingBoardRefreshRef.current = false
      setBoard(null)
      setBoardLoading(false)
      setActionError(null)
      setBoardError(null)
      setLastSyncedAt(null)
      setBusyBarbers({})
      setBusyRows({})
      setAuthStateSynced(nextAuthState)
    },
    [setAuthStateSynced],
  )

  useEffect(() => {
    isMountedRef.current = true
    return () => {
      isMountedRef.current = false
    }
  }, [])

  const handleSessionExpired = useCallback(
    async (expectedEpoch?: number) => {
      if (
        expectedEpoch !== undefined &&
        expectedEpoch !== sessionEpochRef.current
      ) {
        return
      }
      invalidateSession({
        status: 'unauthenticated',
        notice: 'Sesi login telah berakhir. Silakan masuk kembali.',
      })
      if (supabase) {
        await supabase.auth.signOut().catch(() => undefined)
      }
    },
    [invalidateSession, supabase],
  )

  const verifySession = useCallback(
    async (
      nextToken: string,
      email: string | null,
      options: { silent?: boolean } = {},
    ) => {
      const { silent = false } = options
      const seq = ++verifySeqRef.current

      if (silent && authStateRef.current.status === 'authenticated') {
        accessTokenRef.current = nextToken
        setAuthStateSynced((prev) =>
          prev.status === 'authenticated'
            ? { ...prev, accessToken: nextToken }
            : prev,
        )
      } else {
        setAuthStateSynced({ status: 'verifying', email })
      }

      const epochAtStart = sessionEpochRef.current

      try {
        const profile = await fetchAdminMe(nextToken)
        if (
          !isMountedRef.current ||
          seq !== verifySeqRef.current ||
          epochAtStart !== sessionEpochRef.current
        ) {
          return
        }
        accessTokenRef.current = nextToken
        setAuthStateSynced({
          status: 'authenticated',
          accessToken: nextToken,
          profile,
        })
      } catch (error) {
        if (
          !isMountedRef.current ||
          seq !== verifySeqRef.current ||
          epochAtStart !== sessionEpochRef.current
        ) {
          return
        }

        if (error instanceof ApiError && error.status === 403) {
          invalidateSession({
            status: 'forbidden',
            email,
            message:
              error.message ||
              'Akun ini tidak memiliki hak akses admin BarberFlow.',
          })
          return
        }

        if (error instanceof ApiError && error.status === 401) {
          await handleSessionExpired(epochAtStart)
          return
        }

        // Keep live board mounted on transient network errors during silent token refresh
        if (silent && authStateRef.current.status === 'authenticated') {
          return
        }

        setAuthStateSynced({
          status: 'verify_error',
          email,
          message: extractErrorMessage(
            error,
            'Gagal memverifikasi akses admin ke server.',
          ),
        })
      }
    },
    [handleSessionExpired, invalidateSession, setAuthStateSynced],
  )

  // Manage Supabase session lifecycle (single listener; INITIAL_SESSION handles mount)
  useEffect(() => {
    if (!supabase) return

    const {
      data: { subscription },
    } = supabase.auth.onAuthStateChange((event, session) => {
      if (!isMountedRef.current) return

      if (!session) {
        invalidateSession((prev) =>
          prev.status === 'unauthenticated' && prev.notice
            ? prev
            : { status: 'unauthenticated' },
        )
        return
      }

      const nextToken = session.access_token
      const nextEmail = session.user?.email ?? null
      const nextUserId = session.user?.id ?? null
      const currentAuth = authStateRef.current

      const isSameAuthenticatedUser =
        currentAuth.status === 'authenticated' &&
        (currentAuth.profile.id === nextUserId || event === 'TOKEN_REFRESHED')

      if (isSameAuthenticatedUser) {
        if (currentAuth.accessToken === nextToken) {
          return
        }
        void verifySession(nextToken, nextEmail, { silent: true })
        return
      }

      void verifySession(nextToken, nextEmail, { silent: false })
    })

    return () => {
      subscription.unsubscribe()
    }
  }, [invalidateSession, supabase, verifySession])

  const isAuthenticated = authState.status === 'authenticated'

  const refreshAdminQueue = useCallback(
    async (manual = false) => {
      const token = accessTokenRef.current
      if (!token) return
      if (boardInFlightRef.current) {
        pendingBoardRefreshRef.current = true
        return
      }

      const epoch = sessionEpochRef.current
      boardInFlightRef.current = true
      if (manual) {
        setBoardLoading(true)
      }

      try {
        do {
          pendingBoardRefreshRef.current = false
          const currentToken = accessTokenRef.current
          if (!currentToken || epoch !== sessionEpochRef.current) {
            return
          }

          try {
            const data = await fetchAdminQueue(currentToken)
            if (!isMountedRef.current || epoch !== sessionEpochRef.current) {
              return
            }
            setBoard(data)
            setBoardError(null)
            setLastSyncedAt(formatSyncTime(new Date()))
          } catch (error) {
            if (!isMountedRef.current || epoch !== sessionEpochRef.current) {
              return
            }
            if (error instanceof ApiError && error.status === 401) {
              pendingBoardRefreshRef.current = false
              await handleSessionExpired(epoch)
              return
            }
            if (error instanceof ApiError && error.status === 403) {
              pendingBoardRefreshRef.current = false
              invalidateSession({
                status: 'forbidden',
                email: getKnownEmail(authStateRef.current),
                message:
                  error.message || 'Akun ini tidak memiliki izin admin.',
              })
              return
            }
            setBoardError(
              extractErrorMessage(error, 'Gagal memuat daftar antrean admin.'),
            )
          } finally {
            if (isMountedRef.current && epoch === sessionEpochRef.current) {
              setBoardLoading(false)
            }
          }
        } while (
          pendingBoardRefreshRef.current &&
          isMountedRef.current &&
          epoch === sessionEpochRef.current
        )
      } finally {
        if (epoch === sessionEpochRef.current) {
          boardInFlightRef.current = false
        }
      }
    },
    [handleSessionExpired, invalidateSession],
  )

  // Visibility-aware 5s polling while admin is authenticated (not torn down on token refresh)
  useEffect(() => {
    if (!isAuthenticated) return

    let active = true
    let timerId: number | null = null
    const effectEpoch = sessionEpochRef.current

    async function pollAdminQueue() {
      const token = accessTokenRef.current
      if (
        !active ||
        !token ||
        effectEpoch !== sessionEpochRef.current ||
        boardInFlightRef.current
      ) {
        return
      }
      boardInFlightRef.current = true

      try {
        do {
          pendingBoardRefreshRef.current = false
          const currentToken = accessTokenRef.current
          if (
            !active ||
            !currentToken ||
            effectEpoch !== sessionEpochRef.current
          ) {
            return
          }

          try {
            const data = await fetchAdminQueue(currentToken)
            if (
              !active ||
              !isMountedRef.current ||
              effectEpoch !== sessionEpochRef.current
            ) {
              return
            }
            setBoard(data)
            setBoardError(null)
            setLastSyncedAt(formatSyncTime(new Date()))
          } catch (error) {
            if (
              !active ||
              !isMountedRef.current ||
              effectEpoch !== sessionEpochRef.current
            ) {
              return
            }
            if (error instanceof ApiError && error.status === 401) {
              pendingBoardRefreshRef.current = false
              await handleSessionExpired(effectEpoch)
              return
            }
            if (error instanceof ApiError && error.status === 403) {
              pendingBoardRefreshRef.current = false
              invalidateSession({
                status: 'forbidden',
                email: getKnownEmail(authStateRef.current),
                message:
                  error.message || 'Akun ini tidak memiliki izin admin.',
              })
              return
            }
            setBoardError(
              extractErrorMessage(error, 'Gagal memuat daftar antrean admin.'),
            )
          } finally {
            if (
              active &&
              isMountedRef.current &&
              effectEpoch === sessionEpochRef.current
            ) {
              setBoardLoading(false)
            }
          }
        } while (
          pendingBoardRefreshRef.current &&
          active &&
          isMountedRef.current &&
          effectEpoch === sessionEpochRef.current
        )
      } finally {
        if (effectEpoch === sessionEpochRef.current) {
          boardInFlightRef.current = false
        }
      }
    }

    function clearTimer() {
      if (timerId !== null) {
        window.clearInterval(timerId)
        timerId = null
      }
    }

    function startPolling() {
      clearTimer()
      if (document.visibilityState !== 'visible') return
      timerId = window.setInterval(() => {
        if (document.visibilityState === 'visible') {
          void pollAdminQueue()
        }
      }, POLL_INTERVAL_MS)
    }

    function handleVisibilityChange() {
      if (document.visibilityState === 'visible') {
        void pollAdminQueue()
        startPolling()
      } else {
        clearTimer()
      }
    }

    if (document.visibilityState === 'visible') {
      void pollAdminQueue()
      startPolling()
    }

    document.addEventListener('visibilitychange', handleVisibilityChange)

    return () => {
      active = false
      clearTimer()
      document.removeEventListener('visibilitychange', handleVisibilityChange)
    }
  }, [isAuthenticated, handleSessionExpired, invalidateSession])

  async function handleRetryVerify() {
    if (!supabase) return
    const { data, error } = await supabase.auth.getSession()
    if (!isMountedRef.current) return
    if (error || !data.session) {
      invalidateSession({ status: 'unauthenticated' })
      return
    }
    void verifySession(
      data.session.access_token,
      data.session.user?.email ?? null,
      { silent: false },
    )
  }

  async function handleLoginSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (!supabase) return

    setLoginError(null)
    const trimmedEmail = emailInput.trim()

    if (!trimmedEmail || !passwordInput) {
      setLoginError('Email dan kata sandi wajib diisi.')
      return
    }

    setSigningIn(true)
    try {
      const { error } = await supabase.auth.signInWithPassword({
        email: trimmedEmail,
        password: passwordInput,
      })

      if (error) {
        setLoginError(
          error.message === 'Invalid login credentials'
            ? 'Email atau kata sandi tidak sesuai.'
            : error.message,
        )
        return
      }

      setPasswordInput('')
    } catch (error) {
      setLoginError(
        extractErrorMessage(error, 'Gagal masuk. Silakan coba lagi.'),
      )
    } finally {
      if (isMountedRef.current) {
        setSigningIn(false)
      }
    }
  }

  async function handleSignOut() {
    if (!supabase) return
    invalidateSession({ status: 'unauthenticated' })
    await supabase.auth.signOut().catch(() => undefined)
  }

  async function handleBarberStatusChange(
    barberId: number,
    nextStatus: BarberStatus,
  ) {
    const token = accessTokenRef.current
    if (!token || busyBarbers[barberId]) return
    const epoch = sessionEpochRef.current
    setActionError(null)
    setBusyBarbers((prev) => ({ ...prev, [barberId]: true }))

    try {
      await updateBarberStatus(token, barberId, nextStatus)
      if (!isMountedRef.current || epoch !== sessionEpochRef.current) return
      await refreshAdminQueue(false)
    } catch (error) {
      if (!isMountedRef.current || epoch !== sessionEpochRef.current) return
      if (error instanceof ApiError && error.status === 401) {
        await handleSessionExpired(epoch)
        return
      }
      if (error instanceof ApiError && error.status === 403) {
        invalidateSession({
          status: 'forbidden',
          email: getKnownEmail(authStateRef.current),
          message: error.message || 'Akun ini tidak memiliki izin admin.',
        })
        return
      }
      setActionError(
        extractErrorMessage(error, 'Gagal mengubah status barber.'),
      )
    } finally {
      if (isMountedRef.current && epoch === sessionEpochRef.current) {
        setBusyBarbers((prev) => ({ ...prev, [barberId]: false }))
      }
    }
  }

  async function handleAppointmentAction(
    item: QueueItem,
    nextStatus: UpdateAppointmentStatusInput['status'],
  ) {
    const token = accessTokenRef.current
    if (!token || busyRows[item.id]) return
    const epoch = sessionEpochRef.current
    setActionError(null)
    setBusyRows((prev) => ({ ...prev, [item.id]: true }))

    const payload: UpdateAppointmentStatusInput = { status: nextStatus }
    if (
      nextStatus === 'confirmed' &&
      item.barber === null &&
      selectedBarberByRow[item.id] != null
    ) {
      payload.barber_id = selectedBarberByRow[item.id]
    }

    try {
      await updateAppointmentStatus(token, item.id, payload)
      if (!isMountedRef.current || epoch !== sessionEpochRef.current) return
      await refreshAdminQueue(false)
    } catch (error) {
      if (!isMountedRef.current || epoch !== sessionEpochRef.current) return
      if (error instanceof ApiError && error.status === 401) {
        await handleSessionExpired(epoch)
        return
      }
      if (error instanceof ApiError && error.status === 403) {
        invalidateSession({
          status: 'forbidden',
          email: getKnownEmail(authStateRef.current),
          message: error.message || 'Akun ini tidak memiliki izin admin.',
        })
        return
      }
      setActionError(
        extractErrorMessage(
          error,
          `Gagal memperbarui status tiket ${item.ticket_code}.`,
        ),
      )
    } finally {
      if (isMountedRef.current && epoch === sessionEpochRef.current) {
        setBusyRows((prev) => ({ ...prev, [item.id]: false }))
      }
    }
  }

  // Review Focus #5: Clear setup notice when Supabase env vars are missing
  if (!supabase) {
    return (
      <section
        className="panel admin-narrow"
        aria-labelledby="supabase-setup-heading"
      >
        <header className="panel-header">
          <div>
            <h2 id="supabase-setup-heading" className="panel-title">
              Konfigurasi Supabase Diperlukan
            </h2>
            <p className="panel-subtitle">
              Panel Admin memerlukan autentikasi Supabase agar dapat diakses.
            </p>
          </div>
          <span className="badge badge-amber">Belum Dikonfigurasi</span>
        </header>

        <div className="alert alert-warn" role="status">
          Variabel lingkungan <code>VITE_SUPABASE_URL</code> dan{' '}
          <code>VITE_SUPABASE_ANON_KEY</code> belum diisi pada aplikasi
          frontend.
        </div>

        <ol className="setup-steps">
          <li>
            Buka atau buat berkas <strong>frontend/.env</strong>.
          </li>
          <li>
            Tambahkan konfigurasi proyek Supabase Anda:
            <pre className="code-block">
              {`VITE_SUPABASE_URL=https://your-project.supabase.co\nVITE_SUPABASE_ANON_KEY=your-anon-key`}
            </pre>
          </li>
          <li>
            Muat ulang server Vite (<code>npm run dev</code>) lalu segarkan
            halaman ini.
          </li>
        </ol>
      </section>
    )
  }

  if (authState.status === 'checking' || authState.status === 'verifying') {
    return (
      <section className="panel admin-narrow" aria-live="polite">
        <h2 className="panel-title">Memeriksa Sesi Admin</h2>
        <p className="panel-subtitle">
          {authState.status === 'verifying'
            ? `Memverifikasi hak akses untuk ${authState.email ?? 'akun Anda'}...`
            : 'Menyiapkan sesi autentikasi...'}
        </p>
      </section>
    )
  }

  if (authState.status === 'forbidden') {
    return (
      <section className="panel admin-narrow" aria-labelledby="forbidden-title">
        <header className="panel-header">
          <div>
            <h2 id="forbidden-title" className="panel-title">
              Akses Admin Ditolak
            </h2>
            <p className="panel-subtitle">
              Masuk sebagai {authState.email ?? 'akun tidak dikenal'}.
            </p>
          </div>
          <span className="badge badge-rose">403 Forbidden</span>
        </header>

        <div className="alert alert-error" role="alert">
          {authState.message}
        </div>

        <div style={{ marginTop: 16 }}>
          <button
            type="button"
            className="btn btn-dark btn-full"
            onClick={() => void handleSignOut()}
          >
            Keluar
          </button>
        </div>
      </section>
    )
  }

  if (authState.status === 'verify_error') {
    return (
      <section
        className="panel admin-narrow"
        aria-labelledby="verify-error-title"
      >
        <header className="panel-header">
          <div>
            <h2 id="verify-error-title" className="panel-title">
              Verifikasi Gagal
            </h2>
            <p className="panel-subtitle">
              Akun: {authState.email ?? 'tidak diketahui'}
            </p>
          </div>
        </header>

        <div className="alert alert-error" role="alert">
          {authState.message}
        </div>

        <div
          className="action-btn-group"
          style={{ marginTop: 16, justifyContent: 'flex-start' }}
        >
          <button
            type="button"
            className="btn btn-primary"
            onClick={() => void handleRetryVerify()}
          >
            Coba Lagi
          </button>
          <button
            type="button"
            className="btn btn-secondary"
            onClick={() => void handleSignOut()}
          >
            Keluar
          </button>
        </div>
      </section>
    )
  }

  if (authState.status === 'unauthenticated') {
    return (
      <section
        className="panel admin-narrow"
        aria-labelledby="admin-login-heading"
      >
        <header className="panel-header">
          <div>
            <h2 id="admin-login-heading" className="panel-title">
              Masuk Admin BarberFlow
            </h2>
            <p className="panel-subtitle">
              Gunakan akun admin Supabase untuk mengelola antrean dan status
              barber.
            </p>
          </div>
        </header>

        {authState.notice && (
          <div
            className="alert alert-warn"
            role="status"
            style={{ marginBottom: 16 }}
          >
            {authState.notice}
          </div>
        )}

        <form className="form-stack" onSubmit={handleLoginSubmit}>
          <div className="field">
            <label htmlFor="admin-email" className="field-label">
              Email Admin
            </label>
            <input
              id="admin-email"
              type="email"
              className="input"
              placeholder="admin@barberflow.id"
              autoComplete="email"
              value={emailInput}
              onChange={(event) => setEmailInput(event.target.value)}
              disabled={signingIn}
              required
            />
          </div>

          <div className="field">
            <label htmlFor="admin-password" className="field-label">
              Kata Sandi
            </label>
            <input
              id="admin-password"
              type="password"
              className="input"
              placeholder="Masukkan kata sandi"
              autoComplete="current-password"
              value={passwordInput}
              onChange={(event) => setPasswordInput(event.target.value)}
              disabled={signingIn}
              required
            />
          </div>

          {loginError && (
            <div className="alert alert-error" role="alert">
              {loginError}
            </div>
          )}

          <button
            type="submit"
            className="btn btn-dark btn-full"
            disabled={signingIn}
          >
            {signingIn ? 'Memverifikasi...' : 'Masuk ke Panel Admin'}
          </button>
        </form>
      </section>
    )
  }

  const barbers = board?.barbers ?? []
  const queueItems = board?.queue ?? []

  return (
    <div className="admin-stack">
      {/* Admin Top Panel */}
      <section className="panel" aria-labelledby="admin-board-heading">
        <div className="admin-header-bar">
          <div>
            <h2 id="admin-board-heading" className="panel-title">
              Papan Antrean Live Admin
            </h2>
            <p className="admin-email">
              Masuk sebagai: {authState.profile.email ?? authState.profile.id}
            </p>
          </div>

          <div className="admin-header-actions">
            <div className="sync-meta">
              <span className="live-dot" aria-hidden="true" />
              <span>
                {lastSyncedAt ? `Sinkron ${lastSyncedAt}` : 'Menyinkronkan...'}
              </span>
            </div>
            <button
              type="button"
              className="btn btn-secondary btn-sm"
              onClick={() => void refreshAdminQueue(true)}
              disabled={boardLoading}
            >
              {boardLoading ? 'Memuat...' : 'Muat Ulang'}
            </button>
            <button
              type="button"
              className="btn btn-danger-outline btn-sm"
              onClick={() => void handleSignOut()}
            >
              Keluar
            </button>
          </div>
        </div>

        <div
          className="stats-grid"
          style={{ marginTop: 18, marginBottom: 0 }}
          aria-label="Statistik antrean hari ini"
        >
          <div className="stat-card">
            <span className="stat-value">{board?.summary.pending ?? 0}</span>
            <span className="stat-label">Menunggu</span>
          </div>
          <div className="stat-card stat-card-serving">
            <span className="stat-value">{board?.summary.confirmed ?? 0}</span>
            <span className="stat-label">Sedang Dilayani</span>
          </div>
          <div className="stat-card">
            <span className="stat-value">
              {board?.summary.served_today ?? 0}
            </span>
            <span className="stat-label">Selesai Hari Ini</span>
          </div>
        </div>
      </section>

      {actionError && (
        <div className="alert alert-error inline-retry" role="alert">
          <span>{actionError}</span>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => setActionError(null)}
          >
            Tutup
          </button>
        </div>
      )}

      {boardError && (
        <div className="alert alert-error inline-retry" role="alert">
          <span>{boardError}</span>
          <button
            type="button"
            className="btn btn-secondary btn-sm"
            onClick={() => void refreshAdminQueue(true)}
          >
            Coba Lagi
          </button>
        </div>
      )}

      {/* Barber Status Controls */}
      <section className="panel" aria-labelledby="barber-status-heading">
        <header className="panel-header">
          <div>
            <h3 id="barber-status-heading" className="panel-title">
              Panel Status Barber
            </h3>
            <p className="panel-subtitle">
              Perbarui ketersediaan setiap barber secara langsung.
            </p>
          </div>
        </header>

        {barbers.length === 0 ? (
          <div className="empty-state">
            <p className="empty-state-title">Belum ada data barber</p>
          </div>
        ) : (
          <div className="barber-control-grid">
            {barbers.map((barber) => {
              const isBusy = Boolean(busyBarbers[barber.id])
              const statusBadge = getBarberStatusBadge(barber.current_status)

              return (
                <article key={barber.id} className="barber-control-card">
                  <div className="barber-control-top">
                    <span className="barber-control-name">{barber.name}</span>
                    <span className={`badge ${statusBadge.className}`}>
                      {isBusy ? 'Menyimpan...' : statusBadge.label}
                    </span>
                  </div>

                  <div
                    className="segmented-group"
                    role="group"
                    aria-label={`Status untuk ${barber.name}`}
                  >
                    {BARBER_STATUS_OPTIONS.map((option) => {
                      const isActive = barber.current_status === option.value
                      return (
                        <button
                          key={option.value}
                          type="button"
                          className={`segmented-btn ${isActive ? option.activeClass : ''}`}
                          aria-pressed={isActive}
                          disabled={isBusy}
                          onClick={() => {
                            if (!isActive) {
                              void handleBarberStatusChange(
                                barber.id,
                                option.value,
                              )
                            }
                          }}
                        >
                          {option.label}
                        </button>
                      )
                    })}
                  </div>
                </article>
              )
            })}
          </div>
        )}
      </section>

      {/* Admin Live Queue List */}
      <section className="panel" aria-labelledby="admin-queue-heading">
        <header className="panel-header">
          <div>
            <h3 id="admin-queue-heading" className="panel-title">
              Daftar Antrean Live
            </h3>
            <p className="panel-subtitle">
              Panggil pelanggan, tugaskan barber untuk walk-in, dan tandai
              layanan selesai.
            </p>
          </div>
        </header>

        {!board && !boardError ? (
          <div className="alert alert-info" role="status" aria-live="polite">
            Memuat daftar antrean...
          </div>
        ) : queueItems.length === 0 ? (
          <div className="empty-state" role="status" aria-live="polite">
            <p className="empty-state-title">Antrean sedang kosong</p>
            <p>
              Booking online dan walk-in baru akan muncul otomatis setiap 5
              detik.
            </p>
          </div>
        ) : (
          <div className="queue-list" aria-live="polite">
            {queueItems.map((item) => {
              const isConfirmed = item.status === 'confirmed'
              const isPending = item.status === 'pending'
              const isRowBusy = Boolean(busyRows[item.id])
              const typeLabel =
                item.type === 'online'
                  ? formatOnlineSlotLabel(item)
                  : `Walk-in #${item.queue_position ?? '-'}`
              const needsBarberSelect = isPending && item.barber === null
              const assignedSelection = selectedBarberByRow[item.id] ?? null

              return (
                <article
                  key={item.id}
                  className={`admin-queue-row ${isConfirmed ? 'admin-queue-row-confirmed' : ''}`}
                >
                  <div className="admin-row-info">
                    <div className="queue-card-top">
                      <span className="queue-ticket">{item.ticket_code}</span>
                      <div className="queue-badges">
                        <span
                          className={`badge ${isConfirmed ? 'badge-emerald' : 'badge-slate'}`}
                        >
                          {isConfirmed ? 'Sedang Dilayani' : 'Menunggu'}
                        </span>
                        <span
                          className={`badge ${item.type === 'online' ? 'badge-amber' : 'badge-slate'}`}
                        >
                          {typeLabel}
                        </span>
                      </div>
                    </div>

                    <div>
                      <p className="queue-customer">{item.customer.name}</p>
                      <p className="admin-phone">
                        WhatsApp: {item.customer.phone_number ?? '-'}
                      </p>
                    </div>

                    <div className="queue-meta">
                      <span>
                        Layanan:{' '}
                        <strong>
                          {item.service?.name ?? item.service_type}
                        </strong>
                      </span>
                      <span>
                        Barber:{' '}
                        <strong>{item.barber?.name ?? 'Belum dipilih'}</strong>
                      </span>
                    </div>
                  </div>

                  <div className="admin-row-controls">
                    {needsBarberSelect && (
                      <div className="field">
                        <label
                          htmlFor={`assign-barber-${item.id}`}
                          className="sr-only"
                        >
                          Pilih barber untuk {item.ticket_code}
                        </label>
                        <select
                          id={`assign-barber-${item.id}`}
                          className="select admin-assign-select"
                          value={
                            assignedSelection === null
                              ? ''
                              : String(assignedSelection)
                          }
                          onChange={(event) => {
                            const value = event.target.value
                            setSelectedBarberByRow((prev) => ({
                              ...prev,
                              [item.id]: value ? Number(value) : null,
                            }))
                          }}
                          disabled={isRowBusy}
                        >
                          <option value="">Pilih barber saat panggil...</option>
                          {barbers.map((barber) => (
                            <option key={barber.id} value={barber.id}>
                              {barber.name} (
                              {getBarberStatusBadge(barber.current_status).label}
                              )
                            </option>
                          ))}
                        </select>
                      </div>
                    )}

                    <div className="action-btn-group">
                      {isPending && (
                        <button
                          type="button"
                          className="btn btn-primary btn-sm"
                          disabled={isRowBusy}
                          onClick={() =>
                            void handleAppointmentAction(item, 'confirmed')
                          }
                        >
                          {isRowBusy ? 'Memproses...' : 'Panggil'}
                        </button>
                      )}

                      {isConfirmed && (
                        <button
                          type="button"
                          className="btn btn-emerald btn-sm"
                          disabled={isRowBusy}
                          onClick={() =>
                            void handleAppointmentAction(item, 'served')
                          }
                        >
                          {isRowBusy ? 'Memproses...' : 'Selesai'}
                        </button>
                      )}

                      {(isPending || isConfirmed) && (
                        <>
                          <button
                            type="button"
                            className="btn btn-danger-outline btn-sm"
                            disabled={isRowBusy}
                            onClick={() =>
                              void handleAppointmentAction(item, 'cancelled')
                            }
                          >
                            Batal
                          </button>
                          <button
                            type="button"
                            className="btn btn-secondary btn-sm"
                            disabled={isRowBusy}
                            onClick={() =>
                              void handleAppointmentAction(item, 'no_show')
                            }
                          >
                            No-Show
                          </button>
                        </>
                      )}
                    </div>
                  </div>
                </article>
              )
            })}
          </div>
        )}
      </section>
    </div>
  )
}
