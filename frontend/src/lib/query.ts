/**
 * Remove empty query parameters so the API only receives meaningful filters.
 * Scalars only; nested structures are passed through unchanged.
 */
export function cleanParams<T extends object>(params: T): Partial<T> {
  const result: Record<string, unknown> = {}
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') {
      continue
    }
    result[key] = value
  }
  return result as Partial<T>
}
