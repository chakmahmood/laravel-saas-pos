/**
 * Pure navigation-guard logic, kept free of router/store side effects so it can
 * be unit tested in isolation.
 *
 * IMPORTANT: this is a UX guard, not a security boundary. The backend still
 * authenticates and authorizes every request.
 */
export interface GuardContext {
  isAuthenticated: boolean
  hasStore: boolean
}

export type GuardDecision = { type: 'allow' } | { type: 'redirect'; name: string }

export interface RouteMetaShape {
  requiresAuth?: unknown
  requiresStore?: unknown
  guestOnly?: unknown
}

export function resolveNavigation(meta: RouteMetaShape, ctx: GuardContext): GuardDecision {
  const requiresAuth = meta.requiresAuth === true
  const requiresStore = meta.requiresStore === true
  const guestOnly = meta.guestOnly === true

  if (guestOnly) {
    if (ctx.isAuthenticated) {
      return { type: 'redirect', name: ctx.hasStore ? 'dashboard' : 'select-store' }
    }
    return { type: 'allow' }
  }

  if (requiresAuth && !ctx.isAuthenticated) {
    return { type: 'redirect', name: 'login' }
  }

  if (requiresStore && !ctx.hasStore) {
    return { type: 'redirect', name: 'select-store' }
  }

  return { type: 'allow' }
}
