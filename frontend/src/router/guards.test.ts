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
})
