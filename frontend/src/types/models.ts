import type { BusinessType, StoreRole } from './enums'

/** Authenticated user (as returned by login/register/me). */
export interface AuthUser {
  id: number
  name: string
  email: string
}

/** A store the user can access (from `GET /api/me`). */
export interface StoreSummary {
  id: number
  name: string
  slug: string
  role: StoreRole
  business_type: BusinessType
  is_active: boolean
}

/** The active store attached to the current token. */
export interface CurrentStore {
  id: number
  name: string
  slug: string
  role: StoreRole
  business_type: BusinessType
  is_active: boolean
}

export interface MeResult {
  user: AuthUser
  stores: StoreSummary[]
}

export interface LoginResult {
  user: AuthUser
  token: string
  token_type: string
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  store_name: string
  store_slug: string
  business_type?: BusinessType
}

export interface RegisterResult extends LoginResult {
  store: {
    id: number
    name: string
    slug: string
    business_type: BusinessType
  }
}

/**
 * Payload for `POST /api/auth/change-password`. The backend validates the
 * current password and clears `must_change_password` on success.
 */
export interface ChangePasswordPayload {
  current_password: string
  password: string
  password_confirmation: string
}
