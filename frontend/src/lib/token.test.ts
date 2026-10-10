import { beforeEach, describe, expect, it } from 'vitest'
import { tokenStorage } from './token'

describe('tokenStorage', () => {
  beforeEach(() => {
    window.localStorage.clear()
  })

  it('returns null when no token is stored', () => {
    expect(tokenStorage.get()).toBeNull()
  })

  it('persists and reads back a token', () => {
    tokenStorage.set('abc123')
    expect(tokenStorage.get()).toBe('abc123')
  })

  it('clears the stored token', () => {
    tokenStorage.set('abc123')
    tokenStorage.clear()
    expect(tokenStorage.get()).toBeNull()
  })
})
