export type HealthResponse = {
  status: 'ok' | 'error'
  service: string
  database: 'connected' | 'disconnected'
}

export type ServiceItem = {
  id: number
  name: string
  price: number
}

export type BarberStatus = 'idle' | 'busy' | 'break'

export type BarberItem = {
  id: number
  name: string
  current_status: BarberStatus
}

export type SlotItem = {
  slot: string
  scheduled_time: string
  available: boolean
  reason: 'past' | 'booked' | null
}

export type SlotsResponse = {
  barber: BarberItem
  date: string
  timezone: string
  slots: SlotItem[]
}

export type AppointmentStatus =
  | 'pending'
  | 'confirmed'
  | 'served'
  | 'cancelled'
  | 'no_show'

export type QueueItem = {
  id: number
  ticket_code: string
  type: 'online' | 'walk_in'
  status: AppointmentStatus
  queue_position: number | null
  scheduled_time: string | null
  date: string | null
  slot: string | null
  service_type: string
  service: ServiceItem | null
  barber: BarberItem | null
  customer: {
    id: number
    name: string
    phone_number?: string
  }
  created_at: string
}

export type QueueSummary = {
  pending: number
  confirmed: number
  served_today: number
  active_total: number
}

export type QueueBoardResponse = {
  date: string
  timezone: string
  barbers: BarberItem[]
  queue: QueueItem[]
  summary: QueueSummary
}

export type CreateBookingInput = {
  name: string
  phone_number: string
  barber_id: number
  service_id: number
  date: string
  slot: string
}

export type JoinWalkInInput = {
  name: string
  phone_number: string
  service_id: number
  barber_id?: number | null
}

export type AdminProfile = {
  id: string
  email: string | null
  role: 'admin'
}

export type UpdateAppointmentStatusInput = {
  status: 'confirmed' | 'served' | 'cancelled' | 'no_show'
  barber_id?: number | null
}

export class ApiError extends Error {
  readonly status: number
  readonly errors?: Record<string, string[]>

  constructor(
    message: string,
    status: number,
    errors?: Record<string, string[]>,
  ) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

const DEFAULT_API_BASE_URL = 'http://localhost:8000'

function normalizeBaseUrl(url: string | undefined): string {
  const trimmed = url?.trim()
  return (trimmed && trimmed.length > 0 ? trimmed : DEFAULT_API_BASE_URL).replace(
    /\/+$/,
    '',
  )
}

const envApiUrl = (import.meta as { env?: { VITE_API_URL?: string } }).env
  ?.VITE_API_URL

export const API_BASE_URL: string = normalizeBaseUrl(envApiUrl)

type RequestJsonOptions = {
  method?: 'GET' | 'POST' | 'PATCH'
  body?: unknown
  accessToken?: string
  fetchImpl?: typeof fetch
  baseUrl?: string
}

function extractValidationErrors(
  value: unknown,
): Record<string, string[]> | undefined {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return undefined
  }

  const normalized: Record<string, string[]> = {}
  for (const [field, messages] of Object.entries(
    value as Record<string, unknown>,
  )) {
    if (Array.isArray(messages)) {
      normalized[field] = messages.map((item) => String(item))
    }
  }

  return Object.keys(normalized).length > 0 ? normalized : undefined
}

async function requestJson<T>(
  path: string,
  options: RequestJsonOptions = {},
): Promise<T> {
  const {
    method = 'GET',
    body,
    accessToken,
    fetchImpl = fetch,
    baseUrl = API_BASE_URL,
  } = options
  const endpoint = `${normalizeBaseUrl(baseUrl)}${path}`

  const headers: Record<string, string> = {
    Accept: 'application/json',
  }

  if (accessToken !== undefined) {
    headers.Authorization = `Bearer ${accessToken}`
  }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  const init: RequestInit = {
    method,
    headers,
    ...(body !== undefined ? { body: JSON.stringify(body) } : {}),
  }

  let response: Response
  try {
    response = await fetchImpl(endpoint, init)
  } catch (error) {
    const detail = error instanceof Error ? error.message : String(error)
    throw new Error(`Gagal menghubungi backend (${endpoint}): ${detail}`)
  }

  if (!response.ok) {
    const statusText = response.statusText ? ` ${response.statusText}` : ''
    let message = `Permintaan gagal dengan status HTTP ${response.status}${statusText}`
    let errors: Record<string, string[]> | undefined

    try {
      const errorBody = (await response.json()) as {
        message?: unknown
        errors?: unknown
      }
      if (typeof errorBody?.message === 'string' && errorBody.message.trim()) {
        message = errorBody.message
      }
      errors = extractValidationErrors(errorBody?.errors)
    } catch {
      // Non-JSON or empty body; keep HTTP status fallback message.
    }

    throw new ApiError(message, response.status, errors)
  }

  return (await response.json()) as T
}

export async function fetchHealth(
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<HealthResponse> {
  return requestJson<HealthResponse>('/api/health', { fetchImpl, baseUrl })
}

export async function fetchServices(
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<ServiceItem[]> {
  const response = await requestJson<{ data: ServiceItem[] }>('/api/services', {
    fetchImpl,
    baseUrl,
  })
  return response.data
}

export async function fetchBarbers(
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<BarberItem[]> {
  const response = await requestJson<{ data: BarberItem[] }>('/api/barbers', {
    fetchImpl,
    baseUrl,
  })
  return response.data
}

export async function fetchSlots(
  barberId: number,
  date: string,
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<SlotsResponse> {
  const query = new URLSearchParams({
    barber_id: String(barberId),
    date,
  })
  return requestJson<SlotsResponse>(`/api/slots?${query.toString()}`, {
    fetchImpl,
    baseUrl,
  })
}

export async function fetchPublicQueue(
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<QueueBoardResponse> {
  return requestJson<QueueBoardResponse>('/api/queue', { fetchImpl, baseUrl })
}

export async function createBooking(
  payload: CreateBookingInput,
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<QueueItem> {
  const response = await requestJson<{ data: QueueItem }>('/api/bookings', {
    method: 'POST',
    body: payload,
    fetchImpl,
    baseUrl,
  })
  return response.data
}

export async function joinWalkInQueue(
  payload: JoinWalkInInput,
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<QueueItem> {
  const response = await requestJson<{ data: QueueItem }>('/api/queue/join', {
    method: 'POST',
    body: payload,
    fetchImpl,
    baseUrl,
  })
  return response.data
}

export async function fetchAdminMe(
  accessToken: string,
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<AdminProfile> {
  const response = await requestJson<{ data: AdminProfile }>('/api/admin/me', {
    accessToken,
    fetchImpl,
    baseUrl,
  })
  return response.data
}

export async function fetchAdminQueue(
  accessToken: string,
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<QueueBoardResponse> {
  return requestJson<QueueBoardResponse>('/api/admin/queue', {
    accessToken,
    fetchImpl,
    baseUrl,
  })
}

export async function updateAppointmentStatus(
  accessToken: string,
  appointmentId: number,
  payload: UpdateAppointmentStatusInput,
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<QueueItem> {
  const response = await requestJson<{ data: QueueItem }>(
    `/api/admin/appointments/${appointmentId}/status`,
    {
      method: 'PATCH',
      body: payload,
      accessToken,
      fetchImpl,
      baseUrl,
    },
  )
  return response.data
}

export async function updateBarberStatus(
  accessToken: string,
  barberId: number,
  currentStatus: BarberStatus,
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<BarberItem> {
  const response = await requestJson<{ data: BarberItem }>(
    `/api/admin/barbers/${barberId}/status`,
    {
      method: 'PATCH',
      body: { current_status: currentStatus },
      accessToken,
      fetchImpl,
      baseUrl,
    },
  )
  return response.data
}
