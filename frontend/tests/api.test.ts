import test from 'node:test'
import assert from 'node:assert/strict'
import {
  ApiError,
  createBooking,
  fetchAdminMe,
  fetchAdminQueue,
  fetchBarbers,
  fetchHealth,
  fetchPublicQueue,
  fetchServices,
  fetchSlots,
  joinWalkInQueue,
  updateAppointmentStatus,
  updateBarberStatus,
  type BarberItem,
  type QueueBoardResponse,
  type QueueItem,
  type ServiceItem,
  type SlotsResponse,
} from '../src/lib/api.ts'
import {
  getSupabaseClient,
  isSupabaseConfigured,
} from '../src/lib/supabase.ts'

const sampleService: ServiceItem = {
  id: 1,
  name: 'Haircut Reguler',
  price: 45000,
}

const sampleBarber: BarberItem = {
  id: 2,
  name: 'Budi',
  current_status: 'idle',
}

const sampleQueueItem: QueueItem = {
  id: 10,
  ticket_code: 'BF-001',
  type: 'online',
  status: 'pending',
  queue_position: 1,
  scheduled_time: '2026-11-28T10:00:00+07:00',
  date: '2026-11-28',
  slot: '10:00',
  service_type: 'Haircut Reguler',
  service: sampleService,
  barber: sampleBarber,
  customer: {
    id: 5,
    name: 'Andi',
    phone_number: '081234567890',
  },
  created_at: '2026-11-28T08:00:00+07:00',
}

const sampleQueueBoard: QueueBoardResponse = {
  date: '2026-11-28',
  timezone: 'Asia/Jakarta',
  barbers: [sampleBarber],
  queue: [sampleQueueItem],
  summary: {
    pending: 1,
    confirmed: 0,
    served_today: 2,
    active_total: 1,
  },
}

test('fetchHealth returns parsed payload on 200 OK', async () => {
  const expectedPayload = {
    status: 'ok' as const,
    service: 'barberflow-api',
    database: 'connected' as const,
  }

  let requestedUrl = ''
  let requestedInit: RequestInit | undefined

  const mockFetch: typeof fetch = async (input, init) => {
    requestedUrl = typeof input === 'string' ? input : input.toString()
    requestedInit = init
    return new Response(JSON.stringify(expectedPayload), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  const result = await fetchHealth(mockFetch, 'http://localhost:8000/')

  assert.equal(requestedUrl, 'http://localhost:8000/api/health')
  assert.equal(
    new Headers(requestedInit?.headers).get('Accept'),
    'application/json',
  )
  assert.deepEqual(result, expectedPayload)
})

test('fetchHealth throws descriptive error on non-2xx response', async () => {
  const mockFetch: typeof fetch = async () =>
    new Response(JSON.stringify({ status: 'error' }), {
      status: 503,
      statusText: 'Service Unavailable',
      headers: { 'Content-Type': 'application/json' },
    })

  await assert.rejects(
    () => fetchHealth(mockFetch, 'http://localhost:8000'),
    /503/,
  )
})

test('fetchHealth throws descriptive error on network failure', async () => {
  const mockFetch: typeof fetch = async () => {
    throw new TypeError('fetch failed')
  }

  await assert.rejects(
    () => fetchHealth(mockFetch, 'http://localhost:8000'),
    (error: unknown) => {
      assert.ok(error instanceof Error)
      assert.match(error.message, /fetch failed|network|gagal|reach|connect/i)
      return true
    },
  )
})

test('isSupabaseConfigured validates non-empty trimmed URL and anon key', () => {
  assert.equal(isSupabaseConfigured('', ''), false)
  assert.equal(isSupabaseConfigured('   ', '   '), false)
  assert.equal(isSupabaseConfigured('https://demo.supabase.co', '   '), false)
  assert.equal(isSupabaseConfigured('   ', 'anon-key-123'), false)
  assert.equal(isSupabaseConfigured(undefined, undefined), false)
  assert.equal(
    isSupabaseConfigured('https://demo.supabase.co', 'anon-key-123'),
    true,
  )
})

test('getSupabaseClient returns null without throwing when env vars are unset', () => {
  assert.equal(getSupabaseClient(), null)
})

test('fetchServices and fetchBarbers unwrap { data } arrays', async () => {
  const requestedUrls: string[] = []

  const mockFetch: typeof fetch = async (input) => {
    const url = typeof input === 'string' ? input : input.toString()
    requestedUrls.push(url)

    if (url.endsWith('/api/services')) {
      return new Response(JSON.stringify({ data: [sampleService] }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }

    return new Response(JSON.stringify({ data: [sampleBarber] }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  const services = await fetchServices(mockFetch, 'http://localhost:8000/')
  const barbers = await fetchBarbers(mockFetch, 'http://localhost:8000/')

  assert.deepEqual(requestedUrls, [
    'http://localhost:8000/api/services',
    'http://localhost:8000/api/barbers',
  ])
  assert.deepEqual(services, [sampleService])
  assert.deepEqual(barbers, [sampleBarber])
})

test('fetchSlots requests GET /api/slots with barber_id and date query params', async () => {
  const expectedSlots: SlotsResponse = {
    barber: sampleBarber,
    date: '2026-11-28',
    timezone: 'Asia/Jakarta',
    slots: [
      {
        slot: '10:00',
        scheduled_time: '2026-11-28T10:00:00+07:00',
        available: true,
        reason: null,
      },
      {
        slot: '10:30',
        scheduled_time: '2026-11-28T10:30:00+07:00',
        available: false,
        reason: 'booked',
      },
    ],
  }

  let requestedUrl = ''
  let requestedMethod: string | undefined

  const mockFetch: typeof fetch = async (input, init) => {
    requestedUrl = typeof input === 'string' ? input : input.toString()
    requestedMethod = init?.method
    return new Response(JSON.stringify(expectedSlots), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  const result = await fetchSlots(2, '2026-11-28', mockFetch, 'http://localhost:8000')

  assert.equal(
    requestedUrl,
    'http://localhost:8000/api/slots?barber_id=2&date=2026-11-28',
  )
  assert.equal(requestedMethod ?? 'GET', 'GET')
  assert.deepEqual(result, expectedSlots)
})

test('fetchPublicQueue requests GET /api/queue and returns QueueBoardResponse', async () => {
  let requestedUrl = ''

  const mockFetch: typeof fetch = async (input) => {
    requestedUrl = typeof input === 'string' ? input : input.toString()
    return new Response(JSON.stringify(sampleQueueBoard), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  const result = await fetchPublicQueue(mockFetch, 'http://localhost:8000')

  assert.equal(requestedUrl, 'http://localhost:8000/api/queue')
  assert.deepEqual(result, sampleQueueBoard)
})

test('createBooking sends POST /api/bookings JSON and unwraps data', async () => {
  let requestedUrl = ''
  let requestedInit: RequestInit | undefined

  const mockFetch: typeof fetch = async (input, init) => {
    requestedUrl = typeof input === 'string' ? input : input.toString()
    requestedInit = init
    return new Response(JSON.stringify({ data: sampleQueueItem }), {
      status: 201,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  const payload = {
    name: 'Andi',
    phone_number: '081234567890',
    barber_id: 2,
    service_id: 1,
    date: '2026-11-28',
    slot: '10:00',
  }

  const result = await createBooking(payload, mockFetch, 'http://localhost:8000')

  assert.equal(requestedUrl, 'http://localhost:8000/api/bookings')
  assert.equal(requestedInit?.method, 'POST')
  const headers = new Headers(requestedInit?.headers)
  assert.equal(headers.get('Content-Type'), 'application/json')
  assert.equal(headers.get('Accept'), 'application/json')
  assert.deepEqual(JSON.parse(String(requestedInit?.body)), payload)
  assert.deepEqual(result, sampleQueueItem)
})

test('createBooking throws ApiError 409 on slot conflict', async () => {
  const mockFetch: typeof fetch = async () =>
    new Response(JSON.stringify({ message: 'Slot sudah dipesan.' }), {
      status: 409,
      headers: { 'Content-Type': 'application/json' },
    })

  await assert.rejects(
    () =>
      createBooking(
        {
          name: 'Andi',
          phone_number: '081234567890',
          barber_id: 2,
          service_id: 1,
          date: '2026-11-28',
          slot: '10:00',
        },
        mockFetch,
        'http://localhost:8000',
      ),
    (error: unknown) => {
      assert.ok(error instanceof ApiError)
      assert.equal(error.status, 409)
      assert.equal(error.message, 'Slot sudah dipesan.')
      return true
    },
  )
})

test('createBooking throws ApiError 422 with field errors on validation failure', async () => {
  const validationErrors = {
    phone_number: ['Nomor WhatsApp wajib diisi.'],
    slot: ['Format jam tidak valid.'],
  }

  const mockFetch: typeof fetch = async () =>
    new Response(
      JSON.stringify({
        message: 'Data tidak valid.',
        errors: validationErrors,
      }),
      {
        status: 422,
        headers: { 'Content-Type': 'application/json' },
      },
    )

  await assert.rejects(
    () =>
      createBooking(
        {
          name: 'Andi',
          phone_number: '',
          barber_id: 2,
          service_id: 1,
          date: '2026-11-28',
          slot: 'invalid',
        },
        mockFetch,
        'http://localhost:8000',
      ),
    (error: unknown) => {
      assert.ok(error instanceof ApiError)
      assert.equal(error.status, 422)
      assert.equal(error.message, 'Data tidak valid.')
      assert.deepEqual(error.errors, validationErrors)
      return true
    },
  )
})

test('joinWalkInQueue sends POST /api/queue/join supporting barber_id: null', async () => {
  let requestedUrl = ''
  let requestedInit: RequestInit | undefined

  const walkInItem: QueueItem = {
    ...sampleQueueItem,
    id: 11,
    ticket_code: 'WI-002',
    type: 'walk_in',
    scheduled_time: null,
    date: null,
    slot: null,
    barber: null,
  }

  const mockFetch: typeof fetch = async (input, init) => {
    requestedUrl = typeof input === 'string' ? input : input.toString()
    requestedInit = init
    return new Response(JSON.stringify({ data: walkInItem }), {
      status: 201,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  const payload = {
    name: 'Rudi',
    phone_number: '081299998888',
    service_id: 1,
    barber_id: null,
  }

  const result = await joinWalkInQueue(payload, mockFetch, 'http://localhost:8000')

  assert.equal(requestedUrl, 'http://localhost:8000/api/queue/join')
  assert.equal(requestedInit?.method, 'POST')
  assert.equal(
    new Headers(requestedInit?.headers).get('Content-Type'),
    'application/json',
  )
  assert.deepEqual(JSON.parse(String(requestedInit?.body)), payload)
  assert.deepEqual(result, walkInItem)
})

test('admin API calls include Bearer token, exact HTTP methods, and unwrap responses', async () => {
  const requests: Array<{
    url: string
    method: string
    auth: string | null
    contentType: string | null
    body: string | null
  }> = []

  const updatedQueueItem: QueueItem = {
    ...sampleQueueItem,
    status: 'confirmed',
  }

  const updatedBarber: BarberItem = {
    ...sampleBarber,
    current_status: 'busy',
  }

  const mockFetch: typeof fetch = async (input, init) => {
    const url = typeof input === 'string' ? input : input.toString()
    const headers = new Headers(init?.headers)
    requests.push({
      url,
      method: init?.method ?? 'GET',
      auth: headers.get('Authorization'),
      contentType: headers.get('Content-Type'),
      body: typeof init?.body === 'string' ? init.body : null,
    })

    if (url.endsWith('/api/admin/me')) {
      return new Response(
        JSON.stringify({
          data: { id: 'usr-1', email: 'admin@barberflow.test', role: 'admin' },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      )
    }

    if (url.endsWith('/api/admin/queue')) {
      return new Response(JSON.stringify(sampleQueueBoard), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }

    if (url.endsWith('/api/admin/appointments/10/status')) {
      return new Response(JSON.stringify({ data: updatedQueueItem }), {
        status: 200,
        headers: { 'Content-Type': 'application/json' },
      })
    }

    return new Response(JSON.stringify({ data: updatedBarber }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  }

  const profile = await fetchAdminMe('jwt-token-abc', mockFetch, 'http://localhost:8000')
  const board = await fetchAdminQueue('jwt-token-abc', mockFetch, 'http://localhost:8000')
  const appointment = await updateAppointmentStatus(
    'jwt-token-abc',
    10,
    { status: 'confirmed', barber_id: 2 },
    mockFetch,
    'http://localhost:8000',
  )
  const barber = await updateBarberStatus(
    'jwt-token-abc',
    2,
    'busy',
    mockFetch,
    'http://localhost:8000',
  )

  assert.deepEqual(profile, {
    id: 'usr-1',
    email: 'admin@barberflow.test',
    role: 'admin',
  })
  assert.deepEqual(board, sampleQueueBoard)
  assert.deepEqual(appointment, updatedQueueItem)
  assert.deepEqual(barber, updatedBarber)

  assert.deepEqual(requests, [
    {
      url: 'http://localhost:8000/api/admin/me',
      method: 'GET',
      auth: 'Bearer jwt-token-abc',
      contentType: null,
      body: null,
    },
    {
      url: 'http://localhost:8000/api/admin/queue',
      method: 'GET',
      auth: 'Bearer jwt-token-abc',
      contentType: null,
      body: null,
    },
    {
      url: 'http://localhost:8000/api/admin/appointments/10/status',
      method: 'PATCH',
      auth: 'Bearer jwt-token-abc',
      contentType: 'application/json',
      body: JSON.stringify({ status: 'confirmed', barber_id: 2 }),
    },
    {
      url: 'http://localhost:8000/api/admin/barbers/2/status',
      method: 'PATCH',
      auth: 'Bearer jwt-token-abc',
      contentType: 'application/json',
      body: JSON.stringify({ current_status: 'busy' }),
    },
  ])
})

test('admin API calls throw ApiError with 401 or 403 when rejected', async () => {
  const unauthorizedFetch: typeof fetch = async () =>
    new Response(JSON.stringify({ message: 'Unauthenticated.' }), {
      status: 401,
      headers: { 'Content-Type': 'application/json' },
    })

  const forbiddenFetch: typeof fetch = async () =>
    new Response(JSON.stringify({ message: 'Forbidden.' }), {
      status: 403,
      headers: { 'Content-Type': 'application/json' },
    })

  await assert.rejects(
    () => fetchAdminQueue('expired-token', unauthorizedFetch, 'http://localhost:8000'),
    (error: unknown) => {
      assert.ok(error instanceof ApiError)
      assert.equal(error.status, 401)
      assert.equal(error.message, 'Unauthenticated.')
      return true
    },
  )

  await assert.rejects(
    () =>
      updateAppointmentStatus(
        'non-admin-token',
        10,
        { status: 'served' },
        forbiddenFetch,
        'http://localhost:8000',
      ),
    (error: unknown) => {
      assert.ok(error instanceof ApiError)
      assert.equal(error.status, 403)
      assert.equal(error.message, 'Forbidden.')
      return true
    },
  )

  await assert.rejects(
    () => updateBarberStatus('non-admin-token', 2, 'break', forbiddenFetch, 'http://localhost:8000'),
    (error: unknown) => {
      assert.ok(error instanceof ApiError)
      assert.equal(error.status, 403)
      assert.equal(error.message, 'Forbidden.')
      return true
    },
  )
})
