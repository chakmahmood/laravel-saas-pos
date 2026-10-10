import axios, { type AxiosInstance } from 'axios'
import { env } from './env'
import { tokenStorage } from './token'
import { toApiError } from './errors'

let unauthorizedHandler: (() => void) | null = null

/**
 * Register the callback invoked when a protected request returns 401. The app
 * wires this to clear the session and redirect to login, so the HTTP layer has
 * no dependency on the router or Pinia stores.
 */
export function setUnauthorizedHandler(handler: (() => void) | null): void {
  unauthorizedHandler = handler
}

function isAuthEndpoint(url: string | undefined): boolean {
  if (!url) {
    return false
  }
  return url.includes('/auth/login') || url.includes('/auth/register')
}

function createHttpClient(): AxiosInstance {
  const client = axios.create({
    baseURL: env.apiBaseUrl,
    headers: { Accept: 'application/json' },
    timeout: 20000,
  })

  client.interceptors.request.use((config) => {
    const token = tokenStorage.get()
    if (token) {
      config.headers.set('Authorization', `Bearer ${token}`)
    }
    return config
  })

  client.interceptors.response.use(
    (response) => response,
    (error) => {
      const apiError = toApiError(error)
      const url = (error as { config?: { url?: string } }).config?.url
      if (apiError.status === 401 && !isAuthEndpoint(url)) {
        unauthorizedHandler?.()
      }
      return Promise.reject(apiError)
    },
  )

  return client
}

export const http = createHttpClient()
