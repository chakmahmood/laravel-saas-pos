import { describe, expect, it } from 'vitest'
import { ApiError } from '@/lib/errors'
import { fieldErrorsFrom, memberErrorMessage } from './errors'

function apiError(status: number, code?: string, errors?: Record<string, string[]>): ApiError {
  return new ApiError({ message: 'server message', status, code, errors })
}

describe('memberErrorMessage', () => {
  it('maps the one-active-admin conflict to a clear message', () => {
    expect(memberErrorMessage(apiError(409, 'admin_limit_reached'))).toContain('admin aktif')
  })

  it('maps owner protection to a clear message', () => {
    expect(memberErrorMessage(apiError(409, 'owner_protected'))).toContain('owner')
  })

  it('maps a duplicate email to a clear message', () => {
    expect(memberErrorMessage(apiError(409, 'email_already_registered'))).toContain('sudah terdaftar')
  })

  it('falls back to the shared error mapper for unknown codes', () => {
    expect(memberErrorMessage(apiError(500))).toContain('server')
  })
})

describe('fieldErrorsFrom', () => {
  it('extracts the first message per field', () => {
    const error = apiError(422, 'validation_error', {
      email: ['Email tidak valid.'],
      password: ['Minimal 8 karakter.'],
    })

    expect(fieldErrorsFrom(error)).toEqual({
      email: 'Email tidak valid.',
      password: 'Minimal 8 karakter.',
    })
  })

  it('returns an empty object when there are no field errors', () => {
    expect(fieldErrorsFrom(apiError(409))).toEqual({})
  })
})
