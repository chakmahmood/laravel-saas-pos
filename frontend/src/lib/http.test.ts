import { afterEach, describe, expect, it, vi } from 'vitest'
import { http, setPasswordChangeRequiredHandler, setUnauthorizedHandler } from './http'

function rejectWith(status: number, data: unknown, url = '/current-store/members') {
  return () =>
    Promise.reject({
      isAxiosError: true,
      response: { status, data },
      config: { url },
    })
}

describe('http interceptors', () => {
  afterEach(() => {
    setPasswordChangeRequiredHandler(null)
    setUnauthorizedHandler(null)
    http.defaults.adapter = undefined
  })

  it('invokes the forced-password-change handler on 403 password_change_required', async () => {
    const handler = vi.fn()
    setPasswordChangeRequiredHandler(handler)
    http.defaults.adapter = rejectWith(403, { message: 'x', code: 'password_change_required' })

    await expect(http.get('/current-store/members')).rejects.toBeTruthy()

    expect(handler).toHaveBeenCalledOnce()
  })

  it('does not invoke the forced-password-change handler on a plain 403', async () => {
    const handler = vi.fn()
    setPasswordChangeRequiredHandler(handler)
    http.defaults.adapter = rejectWith(403, { message: 'x', code: 'forbidden' })

    await expect(http.get('/current-store/members')).rejects.toBeTruthy()

    expect(handler).not.toHaveBeenCalled()
  })

  it('invokes the unauthorized handler on 401 for protected endpoints', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    http.defaults.adapter = rejectWith(401, { message: 'x', code: 'unauthenticated' }, '/me')

    await expect(http.get('/me')).rejects.toBeTruthy()

    expect(handler).toHaveBeenCalledOnce()
  })

  it('does not invoke the unauthorized handler for login failures', async () => {
    const handler = vi.fn()
    setUnauthorizedHandler(handler)
    http.defaults.adapter = rejectWith(422, { message: 'x' }, '/auth/login')

    await expect(http.post('/auth/login', {})).rejects.toBeTruthy()

    expect(handler).not.toHaveBeenCalled()
  })
})
