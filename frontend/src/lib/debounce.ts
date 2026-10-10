/**
 * Debounce a function so rapid calls (e.g. typing in a search box) only trigger
 * a single trailing invocation. Returns a function with a `cancel` method.
 */
export interface DebouncedFunction<Args extends unknown[]> {
  (...args: Args): void
  cancel(): void
}

export function debounce<Args extends unknown[]>(
  fn: (...args: Args) => void,
  delay = 350,
): DebouncedFunction<Args> {
  let timer: ReturnType<typeof setTimeout> | null = null

  const debounced = (...args: Args): void => {
    if (timer !== null) {
      clearTimeout(timer)
    }
    timer = setTimeout(() => {
      timer = null
      fn(...args)
    }, delay)
  }

  debounced.cancel = (): void => {
    if (timer !== null) {
      clearTimeout(timer)
      timer = null
    }
  }

  return debounced
}
