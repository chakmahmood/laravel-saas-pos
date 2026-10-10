import { describe, expect, it } from 'vitest'
import { cleanParams } from './query'

describe('cleanParams', () => {
  it('drops undefined, null and empty string values', () => {
    expect(cleanParams({ page: 1, search: '', type: undefined, is_active: false, q: null })).toEqual({
      page: 1,
      is_active: false,
    })
  })

  it('keeps zero and boolean false', () => {
    expect(cleanParams({ page: 0, is_active: false })).toEqual({ page: 0, is_active: false })
  })
})
