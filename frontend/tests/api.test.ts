import test from 'node:test'
import assert from 'node:assert/strict'
import { fetchHealth } from '../src/lib/api.ts'

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
