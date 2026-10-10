import { isAxiosError } from 'axios'
import type { ApiErrorBody } from '@/types/api'

interface ApiErrorParams {
  message: string
  status: number
  code?: string
  errors?: Record<string, string[]>
  isNetworkError?: boolean
}

/**
 * Normalized API error. Every failure from the HTTP layer becomes an ApiError
 * so the UI never sees raw Axios objects, SQL text or stack traces.
 */
export class ApiError extends Error {
  readonly status: number
  readonly code?: string
  readonly errors?: Record<string, string[]>
  readonly isNetworkError: boolean

  constructor(params: ApiErrorParams) {
    super(params.message)
    this.name = 'ApiError'
    this.status = params.status
    this.code = params.code
    this.errors = params.errors
    this.isNetworkError = params.isNetworkError ?? false
  }

  get isUnauthenticated(): boolean {
    return this.status === 401
  }

  get isForbidden(): boolean {
    return this.status === 403
  }

  get isNotFound(): boolean {
    return this.status === 404
  }

  get isConflict(): boolean {
    return this.status === 409
  }

  get isValidation(): boolean {
    return this.status === 422
  }

  get isRateLimited(): boolean {
    return this.status === 429
  }

  get isServerError(): boolean {
    return this.status >= 500
  }

  fieldError(field: string): string | undefined {
    return this.errors?.[field]?.[0]
  }
}

export function toApiError(error: unknown): ApiError {
  if (error instanceof ApiError) {
    return error
  }

  if (isAxiosError(error)) {
    const response = error.response
    if (!response) {
      return new ApiError({
        message: 'Tidak dapat terhubung ke server. Periksa koneksi Anda.',
        status: 0,
        isNetworkError: true,
      })
    }

    const body = (response.data ?? {}) as ApiErrorBody
    return new ApiError({
      message: body.message ?? `Permintaan gagal (${response.status}).`,
      status: response.status,
      code: body.code,
      errors: body.errors,
    })
  }

  if (error instanceof Error) {
    return new ApiError({ message: error.message, status: 0 })
  }

  return new ApiError({ message: 'Terjadi kesalahan yang tidak diketahui.', status: 0 })
}

/** A safe, user-facing Indonesian message for any ApiError. */
export function humanMessage(error: ApiError): string {
  if (error.isNetworkError) {
    return error.message
  }

  switch (error.status) {
    case 401:
      return 'Sesi Anda telah berakhir. Silakan masuk kembali.'
    case 403:
      if (error.code === 'inventory_not_available') {
        return 'Fitur inventory tidak tersedia untuk toko ini.'
      }
      if (error.code === 'store_not_accessible') {
        return 'Anda tidak memiliki akses ke toko tersebut.'
      }
      return 'Anda tidak memiliki izin untuk melakukan tindakan ini.'
    case 404:
      return 'Data yang diminta tidak ditemukan.'
    case 409:
      return error.message || 'Terjadi konflik data. Silakan muat ulang lalu coba lagi.'
    case 422:
      return 'Data yang Anda kirim tidak valid. Periksa kembali isian Anda.'
    case 429:
      return 'Terlalu banyak percobaan. Silakan tunggu sebentar lalu coba lagi.'
    default:
      if (error.isServerError) {
        return 'Terjadi kesalahan pada server. Silakan coba lagi nanti.'
      }
      return error.message || 'Permintaan gagal.'
  }
}
