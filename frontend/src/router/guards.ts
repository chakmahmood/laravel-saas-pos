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
  /** Owner or admin of the active store; used for manager-only routes. */
  isManager?: boolean
  /** The account must rotate its initial password before using business pages. */
  mustChangePassword?: boolean
}

export type GuardDecision = { type: 'allow' } | { type: 'redirect'; name: string }

export interface RouteMetaShape {
  requiresAuth?: unknown
  requiresStore?: unknown
  guestOnly?: unknown
  requiresManager?: unknown
  /** Reachable while a forced password change is pending (login/select/change). */
  passwordExempt?: unknown
}

export function resolveNavigation(meta: RouteMetaShape, ctx: GuardContext): GuardDecision {
  const requiresAuth = meta.requiresAuth === true
  const requiresStore = meta.requiresStore === true
  const requiresManager = meta.requiresManager === true
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

  /*
   * A forced password change blocks every authenticated page except the
   * exempt ones (change-password, store selection), preventing redirect loops.
   */
  if (ctx.mustChangePassword === true && meta.passwordExempt !== true) {
    return { type: 'redirect', name: 'change-password' }
  }

  if (requiresManager && ctx.isManager !== true) {
    return { type: 'redirect', name: 'dashboard' }
  }

  return { type: 'allow' }
}
