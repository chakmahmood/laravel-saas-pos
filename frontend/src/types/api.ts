/**
 * Shared API envelope and error types, mirroring the Laravel backend contract
 * documented in `docs/api/openapi.yaml`.
 */

export interface ApiErrorBody {
  message?: string
  code?: string
  errors?: Record<string, string[]>
}

export interface PaginationLink {
  url: string | null
  label: string
  active: boolean
}

export interface PaginationMeta {
  current_page: number
  from: number | null
  last_page: number
  links: PaginationLink[]
  path: string
  per_page: number
  to: number | null
  total: number
}

export interface PaginationLinks {
  first: string | null
  last: string | null
  prev: string | null
  next: string | null
}

export interface Paginated<T> {
  data: T[]
  links: PaginationLinks
  meta: PaginationMeta
}

/** `{ data: T }` envelope. */
export interface DataEnvelope<T> {
  data: T
}

/** `{ message: string, data: T }` envelope used by mutating endpoints. */
export interface MessageEnvelope<T> {
  message: string
  data: T
}
