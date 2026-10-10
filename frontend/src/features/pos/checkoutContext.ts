/**
 * Minimal, per-(store,user) record of an in-flight POS checkout attempt.
 *
 * It stores ONLY the client idempotency key, so a checkout whose response was
 * lost (timeout / dropped connection / page reload) can be reconciled against
 * the server afterwards. It is never a source of truth: order and payment
 * status are always re-verified via the API.
 *
 * Security/privacy:
 * - No auth token or any other sensitive data is stored.
 * - Contexts are separated by store id AND user id so keys never mix across
 *   tenants or accounts.
 * - Storage failures (disabled/quota) degrade to a no-op, never an error.
 */

export interface CheckoutAttempt {
  storeId: number
  userId: number
  key: string
  createdAt: string
}

const STORAGE_KEY = 'saas_pos.pos.checkout_attempt'

function storage(): Storage | null {
  try {
    const probe = '__saas_pos_probe__'
    window.sessionStorage.setItem(probe, '1')
    window.sessionStorage.removeItem(probe)
    return window.sessionStorage
  } catch {
    return null
  }
}

function slot(storeId: number, userId: number): string {
  return `${storeId}:${userId}`
}

function readAll(): Record<string, CheckoutAttempt> {
  const raw = storage()?.getItem(STORAGE_KEY)
  if (!raw) {
    return {}
  }
  try {
    const parsed = JSON.parse(raw) as unknown
    if (parsed && typeof parsed === 'object') {
      return parsed as Record<string, CheckoutAttempt>
    }
  } catch {
    // Corrupt payload: treat as empty rather than throwing.
  }
  return {}
}

function writeAll(map: Record<string, CheckoutAttempt>): void {
  const store = storage()
  if (!store) {
    return
  }
  try {
    store.setItem(STORAGE_KEY, JSON.stringify(map))
  } catch {
    // ignore quota / disabled storage
  }
}

export const checkoutContext = {
  save(attempt: CheckoutAttempt): void {
    const map = readAll()
    map[slot(attempt.storeId, attempt.userId)] = attempt
    writeAll(map)
  },

  get(storeId: number, userId: number): CheckoutAttempt | null {
    return readAll()[slot(storeId, userId)] ?? null
  },

  clear(storeId: number, userId: number): void {
    const map = readAll()
    delete map[slot(storeId, userId)]
    writeAll(map)
  },
}
