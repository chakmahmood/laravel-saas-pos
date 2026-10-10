import { afterEach, describe, expect, it, vi } from 'vitest'
import { debounce } from './debounce'

describe('debounce', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('invokes once with the last arguments after the delay', () => {
    vi.useFakeTimers()
    const spy = vi.fn()
    const debounced = debounce(spy, 200)

    debounced('a')
    debounced('b')
    debounced('c')

    expect(spy).not.toHaveBeenCalled()
    vi.advanceTimersByTime(199)
    expect(spy).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1)

    expect(spy).toHaveBeenCalledTimes(1)
    expect(spy).toHaveBeenCalledWith('c')
  })

  it('cancel prevents a pending invocation', () => {
    vi.useFakeTimers()
    const spy = vi.fn()
    const debounced = debounce(spy, 200)

    debounced('a')
    debounced.cancel()
    vi.advanceTimersByTime(500)

    expect(spy).not.toHaveBeenCalled()
  })
})
