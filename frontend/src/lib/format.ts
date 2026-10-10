/** Formatting helpers aligned with the backend conventions (IDR integers, ISO-8601 UTC). */

export function formatCurrency(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return '—'
  }
  return new Intl.NumberFormat('id-ID', {
    style: 'currency',
    currency: 'IDR',
    maximumFractionDigits: 0,
  }).format(value)
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return '—'
  }
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) {
    return '—'
  }
  return new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(date)
}

export function formatNumber(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return '—'
  }
  return new Intl.NumberFormat('id-ID').format(value)
}

export function formatQuantity(value: string | null | undefined): string {
  if (value === null || value === undefined || value === '') {
    return '—'
  }
  const parsed = Number(value)
  if (Number.isNaN(parsed)) {
    return value
  }
  return new Intl.NumberFormat('id-ID', { maximumFractionDigits: 3 }).format(parsed)
}

/**
 * Parse a user-typed currency value into an integer minor unit (IDR rupiah).
 * Only digits are kept, so "Rp 10.000", "10.000", and "10000" all become 10000.
 * An empty / non-numeric input yields null.
 */
export function parseCurrencyInput(raw: string): number | null {
  const digits = raw.replace(/[^\d]/g, '')
  if (digits === '') {
    return null
  }
  const value = Number.parseInt(digits, 10)
  return Number.isFinite(value) ? value : null
}

/** Format an integer minor unit for an editable currency input (no "Rp" prefix). */
export function formatCurrencyInput(value: number | null | undefined): string {
  if (value === null || value === undefined) {
    return ''
  }
  return new Intl.NumberFormat('id-ID').format(value)
}
