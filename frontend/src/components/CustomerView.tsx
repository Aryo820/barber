import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react'
import {
  ApiError,
  createBooking,
  fetchBarbers,
  fetchPublicQueue,
  fetchServices,
  fetchSlots,
  joinWalkInQueue,
  type AppointmentStatus,
  type BarberItem,
  type BarberStatus,
  type QueueBoardResponse,
  type QueueItem,
  type ServiceItem,
  type SlotItem,
} from '../lib/api.ts'

const POLL_INTERVAL_MS = 5000

const idrFormatter = new Intl.NumberFormat('id-ID')

function formatIdr(amount: number): string {
  return `Rp ${idrFormatter.format(amount)}`
}

function getTodayWib(): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Asia/Jakarta',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date())
}

function addDaysToIsoDate(isoDate: string, days: number): string {
  const [year, month, day] = isoDate.split('-').map(Number)
  const date = new Date(Date.UTC(year, month - 1, day + days))
  const yyyy = date.getUTCFullYear()
  const mm = String(date.getUTCMonth() + 1).padStart(2, '0')
  const dd = String(date.getUTCDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
}

function formatDateIndonesian(isoDate: string | null): string {
  if (!isoDate) return '-'
  const parsed = new Date(`${isoDate}T00:00:00Z`)
  if (Number.isNaN(parsed.getTime())) return isoDate
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: 'UTC',
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(parsed)
}

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

function getBarberStatusMeta(status: BarberStatus): {
  label: string
  badgeClass: string
} {
  switch (status) {
    case 'idle':
      return { label: 'Tersedia', badgeClass: 'badge-emerald' }
    case 'busy':
      return { label: 'Sibuk', badgeClass: 'badge-amber' }
    case 'break':
      return { label: 'Istirahat', badgeClass: 'badge-slate' }
  }
}

function getAppointmentStatusLabel(status: AppointmentStatus): string {
  switch (status) {
    case 'pending':
      return 'Menunggu'
    case 'confirmed':
      return 'Sedang Dilayani'
    case 'served':
      return 'Selesai'
    case 'cancelled':
      return 'Dibatalkan'
    case 'no_show':
      return 'Tidak Hadir'
  }
}

type FormErrorState = {
  message: string
  details: string[]
}

function parseErrorState(error: unknown, fallback: string): FormErrorState {
  if (error instanceof ApiError) {
    const details = error.errors
      ? Object.values(error.errors).flat().filter(Boolean)
      : []
    return {
      message: error.message || fallback,
      details,
    }
  }
  if (error instanceof Error && error.message.trim()) {
    return { message: error.message, details: [] }
  }
  return { message: fallback, details: [] }
}

type FormMode = 'online' | 'walk_in'

export function CustomerView() {
  const todayWib = getTodayWib()
  const maxDateWib = addDaysToIsoDate(todayWib, 7)

  const [mode, setMode] = useState<FormMode>('online')

  // Catalog state
  const [services, setServices] = useState<ServiceItem[]>([])
  const [barbers, setBarbers] = useState<BarberItem[]>([])
  const [catalogLoading, setCatalogLoading] = useState(true)
  const [catalogError, setCatalogError] = useState<string | null>(null)
  const [catalogAttempt, setCatalogAttempt] = useState(0)

  // Booking Online form state
  const [bookingServiceId, setBookingServiceId] = useState<number | null>(null)
  const [bookingBarberId, setBookingBarberId] = useState<number | null>(null)
  const [bookingDate, setBookingDate] = useState<string>(todayWib)
  const [slotsRefreshKey, setSlotsRefreshKey] = useState(0)
  const [slotsResult, setSlotsResult] = useState<{
    key: string
    slots: SlotItem[]
    error: string | null
  }>({ key: '', slots: [], error: null })
  const [selectedSlot, setSelectedSlot] = useState<string>('')
  const [bookingName, setBookingName] = useState('')
  const [bookingPhone, setBookingPhone] = useState('')
  const [bookingSubmitting, setBookingSubmitting] = useState(false)
  const [bookingError, setBookingError] = useState<FormErrorState | null>(null)

  // Walk-in form state
  const [walkInServiceId, setWalkInServiceId] = useState<number | null>(null)
  const [walkInBarberId, setWalkInBarberId] = useState<number | null>(null)
  const [walkInName, setWalkInName] = useState('')
  const [walkInPhone, setWalkInPhone] = useState('')
  const [walkInSubmitting, setWalkInSubmitting] = useState(false)
  const [walkInError, setWalkInError] = useState<FormErrorState | null>(null)

  // Ticket confirmation state
  const [confirmedTicket, setConfirmedTicket] = useState<QueueItem | null>(null)

  // Live queue panel state
  const [queueBoard, setQueueBoard] = useState<QueueBoardResponse | null>(null)
  const [queueLoading, setQueueLoading] = useState(true)
  const [queueError, setQueueError] = useState<string | null>(null)
  const [lastSyncedAt, setLastSyncedAt] = useState<string | null>(null)

  const isMountedRef = useRef(true)
  const queueInFlightRef = useRef(false)
  const pendingQueueRefreshRef = useRef(false)
  const slotsRequestIdRef = useRef(0)

  const currentSlotKey =
    bookingBarberId !== null && bookingDate
      ? `${bookingBarberId}:${bookingDate}:${slotsRefreshKey}`
      : ''
  const slotsLoading = Boolean(
    currentSlotKey && slotsResult.key !== currentSlotKey,
  )
  const slots = currentSlotKey === slotsResult.key ? slotsResult.slots : []
  const slotsError = currentSlotKey === slotsResult.key ? slotsResult.error : null

  useEffect(() => {
    isMountedRef.current = true
    return () => {
      isMountedRef.current = false
    }
  }, [])

  // Fetch services and barbers on mount / retry
  useEffect(() => {
    let active = true

    async function loadCatalog() {
      try {
        const [serviceList, barberList] = await Promise.all([
          fetchServices(),
          fetchBarbers(),
        ])
        if (!active) return

        setServices(serviceList)
        setBarbers(barberList)
        setCatalogError(null)

        if (serviceList.length > 0) {
          setBookingServiceId((prev) => prev ?? serviceList[0].id)
          setWalkInServiceId((prev) => prev ?? serviceList[0].id)
        }
        if (barberList.length > 0) {
          setBookingBarberId((prev) => prev ?? barberList[0].id)
        }
      } catch (error) {
        if (!active) return
        setCatalogError(
          parseErrorState(error, 'Gagal memuat daftar layanan dan barber.')
            .message,
        )
      } finally {
        if (active) {
          setCatalogLoading(false)
        }
      }
    }

    void loadCatalog()

    return () => {
      active = false
    }
  }, [catalogAttempt])

  // Fetch available slots when barber or date changes
  useEffect(() => {
    if (!currentSlotKey || bookingBarberId === null || !bookingDate) {
      return
    }

    const requestId = ++slotsRequestIdRef.current
    const targetBarberId = bookingBarberId
    const targetDate = bookingDate
    const targetKey = currentSlotKey

    async function loadSlots() {
      try {
        const response = await fetchSlots(targetBarberId, targetDate)
        if (!isMountedRef.current || requestId !== slotsRequestIdRef.current) {
          return
        }
        setSlotsResult({ key: targetKey, slots: response.slots, error: null })
        setSelectedSlot((current) =>
          current &&
          response.slots.some(
            (slot) => slot.slot === current && slot.available,
          )
            ? current
            : '',
        )
      } catch (error) {
        if (!isMountedRef.current || requestId !== slotsRequestIdRef.current) {
          return
        }
        setSlotsResult({
          key: targetKey,
          slots: [],
          error: parseErrorState(
            error,
            'Gagal memuat jadwal slot tersedia.',
          ).message,
        })
      }
    }

    void loadSlots()
  }, [bookingBarberId, bookingDate, currentSlotKey])

  // Refresh public queue helper
  const refreshQueue = useCallback(async () => {
    if (queueInFlightRef.current) {
      pendingQueueRefreshRef.current = true
      return
    }
    queueInFlightRef.current = true
    try {
      do {
        pendingQueueRefreshRef.current = false
        try {
          const board = await fetchPublicQueue()
          if (!isMountedRef.current) return
          setQueueBoard(board)
          setQueueError(null)
          setLastSyncedAt(formatSyncTime(new Date()))
        } catch (error) {
          if (!isMountedRef.current) return
          setQueueError(
            parseErrorState(error, 'Gagal memperbarui data antrean live.')
              .message,
          )
        } finally {
          if (isMountedRef.current) {
            setQueueLoading(false)
          }
        }
      } while (pendingQueueRefreshRef.current && isMountedRef.current)
    } finally {
      queueInFlightRef.current = false
    }
  }, [])

  // Visibility-aware 5s polling for live public queue
  useEffect(() => {
    let active = true
    let timerId: number | null = null

    async function pollQueue() {
      if (!active || queueInFlightRef.current) return
      queueInFlightRef.current = true
      try {
        const board = await fetchPublicQueue()
        if (!active || !isMountedRef.current) return
        setQueueBoard(board)
        setQueueError(null)
        setLastSyncedAt(formatSyncTime(new Date()))
      } catch (error) {
        if (!active || !isMountedRef.current) return
        setQueueError(
          parseErrorState(error, 'Gagal memperbarui data antrean live.')
            .message,
        )
      } finally {
        queueInFlightRef.current = false
        if (active && isMountedRef.current) {
          setQueueLoading(false)
          if (pendingQueueRefreshRef.current) {
            pendingQueueRefreshRef.current = false
            void pollQueue()
          }
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
          void pollQueue()
        }
      }, POLL_INTERVAL_MS)
    }

    function handleVisibilityChange() {
      if (document.visibilityState === 'visible') {
        void pollQueue()
        startPolling()
      } else {
        clearTimer()
      }
    }

    if (document.visibilityState === 'visible') {
      void pollQueue()
      startPolling()
    }

    document.addEventListener('visibilitychange', handleVisibilityChange)

    return () => {
      active = false
      clearTimer()
      document.removeEventListener('visibilitychange', handleVisibilityChange)
    }
  }, [])

  async function handleBookingSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBookingError(null)

    const trimmedName = bookingName.trim()
    const trimmedPhone = bookingPhone.trim()

    if (bookingServiceId === null) {
      setBookingError({
        message: 'Silakan pilih layanan terlebih dahulu.',
        details: [],
      })
      return
    }
    if (bookingBarberId === null) {
      setBookingError({
        message: 'Silakan pilih barber terlebih dahulu.',
        details: [],
      })
      return
    }
    if (!selectedSlot) {
      setBookingError({
        message: 'Silakan pilih jam booking yang tersedia.',
        details: [],
      })
      return
    }
    if (!trimmedName || !trimmedPhone) {
      setBookingError({
        message: 'Nama dan nomor WhatsApp wajib diisi.',
        details: [],
      })
      return
    }

    setBookingSubmitting(true)
    try {
      const ticket = await createBooking({
        name: trimmedName,
        phone_number: trimmedPhone,
        barber_id: bookingBarberId,
        service_id: bookingServiceId,
        date: bookingDate,
        slot: selectedSlot,
      })

      setConfirmedTicket(ticket)
      setBookingName('')
      setBookingPhone('')
      setSelectedSlot('')
      setSlotsRefreshKey((k) => k + 1)
      void refreshQueue()
    } catch (error) {
      const parsed = parseErrorState(
        error,
        'Gagal membuat booking. Silakan coba lagi.',
      )
      setBookingError(parsed)
      if (error instanceof ApiError && error.status === 409) {
        setSelectedSlot('')
        setSlotsRefreshKey((k) => k + 1)
      }
    } finally {
      setBookingSubmitting(false)
    }
  }

  async function handleWalkInSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setWalkInError(null)

    const trimmedName = walkInName.trim()
    const trimmedPhone = walkInPhone.trim()

    if (walkInServiceId === null) {
      setWalkInError({
        message: 'Silakan pilih layanan terlebih dahulu.',
        details: [],
      })
      return
    }
    if (!trimmedName || !trimmedPhone) {
      setWalkInError({
        message: 'Nama dan nomor WhatsApp wajib diisi.',
        details: [],
      })
      return
    }

    setWalkInSubmitting(true)
    try {
      const ticket = await joinWalkInQueue({
        name: trimmedName,
        phone_number: trimmedPhone,
        service_id: walkInServiceId,
        barber_id: walkInBarberId,
      })

      setConfirmedTicket(ticket)
      setWalkInName('')
      setWalkInPhone('')
      void refreshQueue()
    } catch (error) {
      setWalkInError(
        parseErrorState(
          error,
          'Gagal mendaftar antrean walk-in. Silakan coba lagi.',
        ),
      )
    } finally {
      setWalkInSubmitting(false)
    }
  }

  function handleResetTicket() {
    setConfirmedTicket(null)
    setBookingError(null)
    setWalkInError(null)
  }

  const displayedBarbers = queueBoard?.barbers ?? barbers
  const availableSlotsCount = slots.filter((slot) => slot.available).length

  return (
    <div className="customer-grid">
      {/* Left Column: Registration Forms / Ticket Confirmation */}
      <section className="panel" aria-labelledby="registration-heading">
        <header className="panel-header">
          <div>
            <h2 id="registration-heading" className="panel-title">
              Pendaftaran Pelanggan
            </h2>
            <p className="panel-subtitle">
              Pilih jadwal booking online atau ambil nomor antrean walk-in.
            </p>
          </div>
        </header>

        {confirmedTicket ? (
          <div className="ticket-card" role="status" aria-live="polite">
            <div className="ticket-header">
              <div>
                <p className="ticket-eyebrow">Kartu Tiket Konfirmasi</p>
                <p className="ticket-code">{confirmedTicket.ticket_code}</p>
              </div>
              <span className="badge badge-emerald">
                {getAppointmentStatusLabel(confirmedTicket.status)}
              </span>
            </div>

            <dl className="ticket-details">
              <div className="ticket-row">
                <dt>Jenis Pendaftaran</dt>
                <dd>
                  {confirmedTicket.type === 'online'
                    ? 'Booking Online'
                    : 'Antrean Walk-in'}
                </dd>
              </div>
              {confirmedTicket.type === 'walk_in' &&
                confirmedTicket.queue_position !== null && (
                  <div className="ticket-row">
                    <dt>Nomor Antrean</dt>
                    <dd>#{confirmedTicket.queue_position}</dd>
                  </div>
                )}
              <div className="ticket-row">
                <dt>Nama Pelanggan</dt>
                <dd>{confirmedTicket.customer.name}</dd>
              </div>
              <div className="ticket-row">
                <dt>Layanan</dt>
                <dd>
                  {confirmedTicket.service?.name ??
                    confirmedTicket.service_type}
                  {confirmedTicket.service
                    ? ` (${formatIdr(confirmedTicket.service.price)})`
                    : ''}
                </dd>
              </div>
              <div className="ticket-row">
                <dt>Barber</dt>
                <dd>{confirmedTicket.barber?.name ?? 'Siapa saja'}</dd>
              </div>
              {confirmedTicket.type === 'online' && (
                <div className="ticket-row">
                  <dt>Tanggal &amp; Jam</dt>
                  <dd>
                    {formatDateIndonesian(confirmedTicket.date)}
                    {confirmedTicket.slot
                      ? `, ${confirmedTicket.slot} WIB`
                      : ''}
                  </dd>
                </div>
              )}
              <div className="ticket-row">
                <dt>Status</dt>
                <dd>{getAppointmentStatusLabel(confirmedTicket.status)}</dd>
              </div>
            </dl>

            <button
              type="button"
              className="btn btn-dark btn-full"
              onClick={handleResetTicket}
            >
              Buat Pendaftaran Baru
            </button>
          </div>
        ) : (
          <>
            <div
              className="mode-tabs"
              role="tablist"
              aria-label="Jenis pendaftaran"
            >
              <button
                type="button"
                role="tab"
                id="tab-online"
                aria-selected={mode === 'online'}
                aria-controls="panel-online"
                className={`mode-tab ${mode === 'online' ? 'mode-tab-active' : ''}`}
                onClick={() => {
                  setMode('online')
                  setWalkInError(null)
                }}
              >
                Booking Online
              </button>
              <button
                type="button"
                role="tab"
                id="tab-walkin"
                aria-selected={mode === 'walk_in'}
                aria-controls="panel-walkin"
                className={`mode-tab ${mode === 'walk_in' ? 'mode-tab-active' : ''}`}
                onClick={() => {
                  setMode('walk_in')
                  setBookingError(null)
                }}
              >
                Antrean Walk-in
              </button>
            </div>

            {catalogLoading ? (
              <div className="alert alert-info" role="status" aria-live="polite">
                Memuat daftar layanan dan barber...
              </div>
            ) : catalogError ? (
              <div className="alert alert-error inline-retry" role="alert">
                <span>{catalogError}</span>
                <button
                  type="button"
                  className="btn btn-secondary btn-sm"
                  onClick={() => {
                    setCatalogLoading(true)
                    setCatalogError(null)
                    setCatalogAttempt((n) => n + 1)
                  }}
                >
                  Coba Lagi
                </button>
              </div>
            ) : mode === 'online' ? (
              <form
                id="panel-online"
                role="tabpanel"
                aria-labelledby="tab-online"
                className="form-stack"
                onSubmit={handleBookingSubmit}
              >
                <fieldset className="fieldset-clean">
                  <legend className="field-label">Pilih Layanan</legend>
                  <div className="service-list">
                    {services.map((service) => {
                      const isSelected = bookingServiceId === service.id
                      return (
                        <label
                          key={service.id}
                          className={`service-option ${isSelected ? 'service-option-selected' : ''}`}
                        >
                          <input
                            type="radio"
                            name="booking-service"
                            className="sr-only"
                            value={service.id}
                            checked={isSelected}
                            onChange={() => setBookingServiceId(service.id)}
                            disabled={bookingSubmitting}
                          />
                          <span className="service-option-left">
                            <span
                              className="service-radio-dot"
                              aria-hidden="true"
                            />
                            <span className="service-name">{service.name}</span>
                          </span>
                          <span className="service-price">
                            {formatIdr(service.price)}
                          </span>
                        </label>
                      )
                    })}
                  </div>
                </fieldset>

                <div className="field-row">
                  <div className="field">
                    <label htmlFor="booking-barber" className="field-label">
                      Pilih Barber
                    </label>
                    <select
                      id="booking-barber"
                      className="select"
                      value={bookingBarberId ?? ''}
                      onChange={(event) => {
                        const value = event.target.value
                        setBookingBarberId(value ? Number(value) : null)
                        setSelectedSlot('')
                      }}
                      disabled={bookingSubmitting || barbers.length === 0}
                      required
                    >
                      {barbers.map((barber) => (
                        <option key={barber.id} value={barber.id}>
                          {barber.name}
                        </option>
                      ))}
                    </select>
                  </div>

                  <div className="field">
                    <label htmlFor="booking-date" className="field-label">
                      Tanggal (WIB)
                    </label>
                    <input
                      id="booking-date"
                      type="date"
                      className="input"
                      value={bookingDate}
                      min={todayWib}
                      max={maxDateWib}
                      onChange={(event) => {
                        setBookingDate(event.target.value)
                        setSelectedSlot('')
                      }}
                      disabled={bookingSubmitting}
                      required
                    />
                  </div>
                </div>

                <div className="field">
                  <span id="slot-picker-label" className="field-label">
                    Pilih Jam (WIB)
                  </span>
                  {slotsLoading ? (
                    <div
                      className="alert alert-info"
                      role="status"
                      aria-live="polite"
                    >
                      Memuat slot tersedia...
                    </div>
                  ) : slotsError ? (
                    <div
                      className="alert alert-error inline-retry"
                      role="alert"
                    >
                      <span>{slotsError}</span>
                      <button
                        type="button"
                        className="btn btn-secondary btn-sm"
                        onClick={() => setSlotsRefreshKey((k) => k + 1)}
                      >
                        Muat Ulang Slot
                      </button>
                    </div>
                  ) : slots.length === 0 ? (
                    <div className="alert alert-info">
                      Belum ada jadwal slot pada tanggal ini.
                    </div>
                  ) : (
                    <>
                      <div
                        className="slot-grid"
                        role="group"
                        aria-labelledby="slot-picker-label"
                      >
                        {slots.map((slot) => {
                          const isSelected = selectedSlot === slot.slot
                          const stateLabel = slot.available
                            ? isSelected
                              ? 'Dipilih'
                              : 'Tersedia'
                            : slot.reason === 'booked'
                              ? 'Terisi'
                              : 'Lewat'
                          const stateClass = slot.available
                            ? ''
                            : slot.reason === 'booked'
                              ? 'slot-state-booked'
                              : 'slot-state-past'

                          return (
                            <button
                              key={slot.slot}
                              type="button"
                              className={`slot-button ${isSelected ? 'slot-button-selected' : ''}`}
                              disabled={!slot.available || bookingSubmitting}
                              aria-pressed={isSelected}
                              onClick={() => setSelectedSlot(slot.slot)}
                            >
                              <span className="slot-time">{slot.slot}</span>
                              <span className={`slot-state ${stateClass}`}>
                                {stateLabel}
                              </span>
                            </button>
                          )
                        })}
                      </div>
                      {availableSlotsCount === 0 && (
                        <p className="field-hint">
                          Semua slot pada tanggal ini sudah terisi atau lewat.
                          Silakan pilih tanggal atau barber lain.
                        </p>
                      )}
                    </>
                  )}
                </div>

                <div className="field-row">
                  <div className="field">
                    <label htmlFor="booking-name" className="field-label">
                      Nama
                    </label>
                    <input
                      id="booking-name"
                      type="text"
                      className="input"
                      placeholder="Masukkan nama Anda"
                      autoComplete="name"
                      value={bookingName}
                      onChange={(event) => setBookingName(event.target.value)}
                      disabled={bookingSubmitting}
                      required
                    />
                  </div>

                  <div className="field">
                    <label htmlFor="booking-phone" className="field-label">
                      Nomor WhatsApp
                    </label>
                    <input
                      id="booking-phone"
                      type="tel"
                      className="input"
                      placeholder="0812xxxxxxxx"
                      autoComplete="tel"
                      value={bookingPhone}
                      onChange={(event) => setBookingPhone(event.target.value)}
                      disabled={bookingSubmitting}
                      required
                    />
                  </div>
                </div>

                {bookingError && (
                  <div className="alert alert-error" role="alert">
                    <p>{bookingError.message}</p>
                    {bookingError.details.length > 0 && (
                      <ul className="alert-list">
                        {bookingError.details.map((detail, idx) => (
                          <li key={idx}>{detail}</li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}

                <button
                  type="submit"
                  className="btn btn-primary btn-full"
                  disabled={
                    bookingSubmitting ||
                    services.length === 0 ||
                    barbers.length === 0
                  }
                >
                  {bookingSubmitting
                    ? 'Memproses Booking...'
                    : 'Konfirmasi Booking Online'}
                </button>
              </form>
            ) : (
              <form
                id="panel-walkin"
                role="tabpanel"
                aria-labelledby="tab-walkin"
                className="form-stack"
                onSubmit={handleWalkInSubmit}
              >
                <fieldset className="fieldset-clean">
                  <legend className="field-label">Pilih Layanan</legend>
                  <div className="service-list">
                    {services.map((service) => {
                      const isSelected = walkInServiceId === service.id
                      return (
                        <label
                          key={service.id}
                          className={`service-option ${isSelected ? 'service-option-selected' : ''}`}
                        >
                          <input
                            type="radio"
                            name="walkin-service"
                            className="sr-only"
                            value={service.id}
                            checked={isSelected}
                            onChange={() => setWalkInServiceId(service.id)}
                            disabled={walkInSubmitting}
                          />
                          <span className="service-option-left">
                            <span
                              className="service-radio-dot"
                              aria-hidden="true"
                            />
                            <span className="service-name">{service.name}</span>
                          </span>
                          <span className="service-price">
                            {formatIdr(service.price)}
                          </span>
                        </label>
                      )
                    })}
                  </div>
                </fieldset>

                <div className="field">
                  <label htmlFor="walkin-barber" className="field-label">
                    Pilih Barber (Opsional)
                  </label>
                  <select
                    id="walkin-barber"
                    className="select"
                    value={walkInBarberId === null ? '' : String(walkInBarberId)}
                    onChange={(event) => {
                      const value = event.target.value
                      setWalkInBarberId(value ? Number(value) : null)
                    }}
                    disabled={walkInSubmitting}
                  >
                    <option value="">Siapa saja</option>
                    {barbers.map((barber) => (
                      <option key={barber.id} value={barber.id}>
                        {barber.name}
                      </option>
                    ))}
                  </select>
                </div>

                <div className="field-row">
                  <div className="field">
                    <label htmlFor="walkin-name" className="field-label">
                      Nama
                    </label>
                    <input
                      id="walkin-name"
                      type="text"
                      className="input"
                      placeholder="Masukkan nama Anda"
                      autoComplete="name"
                      value={walkInName}
                      onChange={(event) => setWalkInName(event.target.value)}
                      disabled={walkInSubmitting}
                      required
                    />
                  </div>

                  <div className="field">
                    <label htmlFor="walkin-phone" className="field-label">
                      Nomor WhatsApp
                    </label>
                    <input
                      id="walkin-phone"
                      type="tel"
                      className="input"
                      placeholder="0812xxxxxxxx"
                      autoComplete="tel"
                      value={walkInPhone}
                      onChange={(event) => setWalkInPhone(event.target.value)}
                      disabled={walkInSubmitting}
                      required
                    />
                  </div>
                </div>

                {walkInError && (
                  <div className="alert alert-error" role="alert">
                    <p>{walkInError.message}</p>
                    {walkInError.details.length > 0 && (
                      <ul className="alert-list">
                        {walkInError.details.map((detail, idx) => (
                          <li key={idx}>{detail}</li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}

                <button
                  type="submit"
                  className="btn btn-primary btn-full"
                  disabled={walkInSubmitting || services.length === 0}
                >
                  {walkInSubmitting
                    ? 'Mendaftarkan Antrean...'
                    : 'Ambil Nomor Antrean Walk-in'}
                </button>
              </form>
            )}
          </>
        )}
      </section>

      {/* Right Column: Live Public Queue Panel */}
      <section className="panel" aria-labelledby="public-queue-heading">
        <header className="panel-header">
          <div>
            <h2 id="public-queue-heading" className="panel-title">
              Antrean Live Hari Ini
            </h2>
            <p className="panel-subtitle">
              Diperbarui otomatis setiap 5 detik.
            </p>
          </div>
          <div className="sync-meta">
            <span className="live-dot" aria-hidden="true" />
            <span>
              {lastSyncedAt
                ? `Sinkron ${lastSyncedAt}`
                : 'Memuat antrean...'}
            </span>
          </div>
        </header>

        {displayedBarbers.length > 0 && (
          <div
            className="barber-pills"
            aria-label="Ketersediaan barber saat ini"
          >
            {displayedBarbers.map((barber) => {
              const statusMeta = getBarberStatusMeta(barber.current_status)
              return (
                <div key={barber.id} className="barber-pill">
                  <span>{barber.name}</span>
                  <span className={`badge ${statusMeta.badgeClass}`}>
                    <span className="badge-dot" aria-hidden="true" />
                    {statusMeta.label}
                  </span>
                </div>
              )
            })}
          </div>
        )}

        <div className="stats-grid" aria-label="Ringkasan antrean hari ini">
          <div className="stat-card">
            <span className="stat-value">
              {queueBoard?.summary.pending ?? 0}
            </span>
            <span className="stat-label">Menunggu</span>
          </div>
          <div className="stat-card stat-card-serving">
            <span className="stat-value">
              {queueBoard?.summary.confirmed ?? 0}
            </span>
            <span className="stat-label">Sedang Dilayani</span>
          </div>
          <div className="stat-card">
            <span className="stat-value">
              {queueBoard?.summary.served_today ?? 0}
            </span>
            <span className="stat-label">Selesai Hari Ini</span>
          </div>
        </div>

        {queueError && (
          <div className="alert alert-error inline-retry" role="alert">
            <span>{queueError}</span>
            <button
              type="button"
              className="btn btn-secondary btn-sm"
              onClick={() => void refreshQueue()}
            >
              Muat Ulang
            </button>
          </div>
        )}

        {queueLoading && !queueBoard ? (
          <div className="alert alert-info" role="status" aria-live="polite">
            Memuat daftar antrean...
          </div>
        ) : !queueBoard || queueBoard.queue.length === 0 ? (
          <div className="empty-state" role="status" aria-live="polite">
            <p className="empty-state-title">Belum ada antrean aktif</p>
            <p>
              Silakan buat booking online atau ambil nomor antrean walk-in di
              panel sebelah.
            </p>
          </div>
        ) : (
          <div className="queue-list" aria-live="polite">
            {queueBoard.queue.map((item) => {
              const isConfirmed = item.status === 'confirmed'
              const typeBadgeLabel =
                item.type === 'online'
                  ? formatOnlineSlotLabel(item)
                  : `Walk-in #${item.queue_position ?? '-'}`

              return (
                <article
                  key={item.id}
                  className={`queue-card ${isConfirmed ? 'queue-card-confirmed' : ''}`}
                >
                  <div className="queue-card-top">
                    <span className="queue-ticket">{item.ticket_code}</span>
                    <div className="queue-badges">
                      {isConfirmed && (
                        <span className="badge badge-emerald">
                          Sedang Dilayani
                        </span>
                      )}
                      <span
                        className={`badge ${item.type === 'online' ? 'badge-amber' : 'badge-slate'}`}
                      >
                        {typeBadgeLabel}
                      </span>
                    </div>
                  </div>
                  <p className="queue-customer">{item.customer.name}</p>
                  <div className="queue-meta">
                    <span>
                      Layanan:{' '}
                      <strong>{item.service?.name ?? item.service_type}</strong>
                    </span>
                    <span>
                      Barber:{' '}
                      <strong>{item.barber?.name ?? 'Siapa saja'}</strong>
                    </span>
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
