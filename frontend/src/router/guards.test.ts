import { describe, expect, it } from 'vitest'
import { resolveNavigation } from './guards'

const authenticated = { isAuthenticated: true, hasStore: true }
const authedNoStore = { isAuthenticated: true, hasStore: false }
const guest = { isAuthenticated: false, hasStore: false }

describe('resolveNavigation', () => {
  it('allows public routes for unauthenticated users', () => {
    expect(resolveNavigation({}, guest)).toEqual({ type: 'allow' })
  })

  it('redirects protected routes to login when unauthenticated', () => {
    expect(resolveNavigation({ requiresAuth: true }, guest)).toEqual({
      type: 'redirect',
      name: 'login',
    })
  })

  it('redirects store-required routes to store selection without a store', () => {
    expect(resolveNavigation({ requiresAuth: true, requiresStore: true }, authedNoStore)).toEqual({
      type: 'redirect',
      name: 'select-store',
    })
  })

  it('allows store-required routes with an active store', () => {
    expect(resolveNavigation({ requiresAuth: true, requiresStore: true }, authenticated)).toEqual({
      type: 'allow',
    })
  })

  it('redirects authenticated users away from guest-only routes', () => {
    expect(resolveNavigation({ guestOnly: true }, authenticated)).toEqual({
      type: 'redirect',
      name: 'dashboard',
    })
    expect(resolveNavigation({ guestOnly: true }, authedNoStore)).toEqual({
      type: 'redirect',
      name: 'select-store',
    })
  })

  it('allows guest-only routes for guests', () => {
    expect(resolveNavigation({ guestOnly: true }, guest)).toEqual({ type: 'allow' })
  })

  it('redirects a forced password change away from business pages', () => {
    expect(
      resolveNavigation(
        { requiresAuth: true, requiresStore: true },
        { ...authenticated, mustChangePassword: true },
      ),
    ).toEqual({ type: 'redirect', name: 'change-password' })
  })

  it('allows password-exempt routes while a change is pending (no redirect loop)', () => {
    expect(
      resolveNavigation(
        { requiresAuth: true, passwordExempt: true },
        { ...authenticated, mustChangePassword: true },
      ),
    ).toEqual({ type: 'allow' })
  })

  it('redirects non-managers away from manager-only routes', () => {
    expect(
      resolveNavigation(
        { requiresAuth: true, requiresStore: true, requiresManager: true },
        { ...authenticated, isManager: false },
      ),
    ).toEqual({ type: 'redirect', name: 'dashboard' })
  })

  it('allows managers into manager-only routes', () => {
    expect(
      resolveNavigation(
        { requiresAuth: true, requiresStore: true, requiresManager: true },
        { ...authenticated, isManager: true },
      ),
    ).toEqual({ type: 'allow' })
  })

  it('prioritizes the forced password change over the manager check', () => {
    expect(
      resolveNavigation(
        { requiresAuth: true, requiresStore: true, requiresManager: true },
        { ...authenticated, isManager: false, mustChangePassword: true },
      ),
    ).toEqual({ type: 'redirect', name: 'change-password' })
  })
})
