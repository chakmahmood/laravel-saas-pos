/**
 * Frontend environment. Only `VITE_*` variables are exposed to the browser.
 * The API base URL is never hardcoded to a production host.
 */
function normalizeBaseUrl(raw: string | undefined): string {
  const value = (raw ?? '').trim()
  if (value === '') {
    // Sensible default for local development / same-origin reverse proxy.
    return '/api'
  }
  return value.replace(/\/+$/, '')
}

export const env = {
  apiBaseUrl: normalizeBaseUrl(import.meta.env.VITE_API_BASE_URL),
  appName: (import.meta.env.VITE_APP_NAME ?? 'SaaS POS').trim() || 'SaaS POS',
  isDev: import.meta.env.DEV,
} as const
