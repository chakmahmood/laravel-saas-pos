/**
 * Sanctum bearer-token persistence.
 *
 * SECURITY NOTE (evaluated for this checkpoint):
 * The backend authenticates with opaque Sanctum **Bearer tokens** (not the
 * stateful SPA cookie mode). Since the token must be attached by JavaScript,
 * every persistence option readable by JS is exposed to XSS equally. We choose
 * `localStorage` so a page reload keeps the session, and mitigate the XSS risk
 * by: (1) never rendering untrusted HTML, (2) a strict CSP at deployment, and
 * (3) sending only this opaque token (revocable server-side via `/auth/logout`).
 *
 * The more secure long-term option is Sanctum SPA cookie mode (httpOnly,
 * `SANCTUM_STATEFUL_DOMAINS` + `EnsureFrontendRequestsAreStateful`), which
 * requires a backend change and is documented as a pending decision — NOT
 * implemented here.
 */
const STORAGE_KEY = 'saas_pos.admin.token'

function storage(): Storage | null {
  try {
    const probe = '__saas_pos_probe__'
    window.localStorage.setItem(probe, '1')
    window.localStorage.removeItem(probe)
    return window.localStorage
  } catch {
    return null
  }
}

export const tokenStorage = {
  get(): string | null {
    return storage()?.getItem(STORAGE_KEY) ?? null
  },
  set(token: string): void {
    storage()?.setItem(STORAGE_KEY, token)
  },
  clear(): void {
    try {
      storage()?.removeItem(STORAGE_KEY)
    } catch {
      // ignore
    }
  },
}
