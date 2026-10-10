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
