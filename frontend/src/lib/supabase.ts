import { createClient, type SupabaseClient } from '@supabase/supabase-js'

let supabaseClient: SupabaseClient | null = null

export function isSupabaseConfigured(
  url: string | undefined = import.meta.env?.VITE_SUPABASE_URL,
  anonKey: string | undefined = import.meta.env?.VITE_SUPABASE_ANON_KEY,
): boolean {
  return Boolean(url?.trim() && anonKey?.trim())
}

export function getSupabaseClient(): SupabaseClient | null {
  const url = import.meta.env?.VITE_SUPABASE_URL?.trim()
  const anonKey = import.meta.env?.VITE_SUPABASE_ANON_KEY?.trim()

  if (!isSupabaseConfigured(url, anonKey) || !url || !anonKey) {
    return null
  }

  if (!supabaseClient) {
    supabaseClient = createClient(url, anonKey)
  }

  return supabaseClient
}
