import { describe, expect, it } from 'vitest'
import { ApiError, humanMessage, toApiError } from './errors'

function axiosError(status: number, data: unknown) {
  return { isAxiosError: true, response: { status, data }, config: { url: '/items' } }
}

describe('toApiError', () => {
  it('maps an API error body onto ApiError', () => {
    const error = toApiError(
      axiosError(422, {
        message: 'The name field is required.',
        code: 'validation_error',
        errors: { name: ['The name field is required.'] },
      }),
    )

    expect(error).toBeInstanceOf(ApiError)
    expect(error.status).toBe(422)
    expect(error.code).toBe('validation_error')
    expect(error.fieldError('name')).toBe('The name field is required.')
    expect(error.isValidation).toBe(true)
  })

  it('flags network errors (no response) without a status', () => {
    const error = toApiError({ isAxiosError: true, response: undefined, config: {} })

    expect(error.isNetworkError).toBe(true)
    expect(error.status).toBe(0)
  })

  it('returns the same ApiError instance when already normalized', () => {
    const original = new ApiError({ message: 'x', status: 403 })
    expect(toApiError(original)).toBe(original)
  })
})

describe('humanMessage', () => {
  it('maps common statuses to friendly Indonesian messages', () => {
    expect(humanMessage(new ApiError({ message: '', status: 401 }))).toContain('Sesi Anda')
    expect(humanMessage(new ApiError({ message: '', status: 403 }))).toContain('izin')
    expect(humanMessage(new ApiError({ message: '', status: 404 }))).toContain('tidak ditemukan')
    expect(humanMessage(new ApiError({ message: '', status: 422 }))).toContain('tidak valid')
    expect(humanMessage(new ApiError({ message: '', status: 429 }))).toContain('Terlalu banyak')
    expect(humanMessage(new ApiError({ message: '', status: 500 }))).toContain('server')
  })

  it('special-cases known capability codes', () => {
    const inventory = new ApiError({
      message: 'x',
      status: 403,
      code: 'inventory_not_available',
    })
    expect(humanMessage(inventory)).toContain('inventory')

    const store = new ApiError({ message: 'x', status: 403, code: 'store_not_accessible' })
    expect(humanMessage(store)).toContain('akses ke toko')
  })

  it('prefers the server message for business conflicts', () => {
    const conflict = new ApiError({
      message: 'Stok tidak mencukupi untuk salah satu item.',
      status: 409,
      code: 'insufficient_stock',
    })
    expect(humanMessage(conflict)).toBe('Stok tidak mencukupi untuk salah satu item.')
  })
})
