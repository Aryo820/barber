export type HealthResponse = {
  status: 'ok' | 'error'
  service: string
  database: 'connected' | 'disconnected'
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

export async function fetchHealth(
  fetchImpl: typeof fetch = fetch,
  baseUrl: string = API_BASE_URL,
): Promise<HealthResponse> {
  const endpoint = `${normalizeBaseUrl(baseUrl)}/api/health`

  let response: Response
  try {
    response = await fetchImpl(endpoint, {
      headers: {
        Accept: 'application/json',
      },
    })
  } catch (error) {
    const detail = error instanceof Error ? error.message : String(error)
    throw new Error(`Gagal menghubungi backend (${endpoint}): ${detail}`)
  }

  if (!response.ok) {
    const statusText = response.statusText ? ` ${response.statusText}` : ''
    throw new Error(
      `Health check gagal dengan status HTTP ${response.status}${statusText}`,
    )
  }

  return (await response.json()) as HealthResponse
}
