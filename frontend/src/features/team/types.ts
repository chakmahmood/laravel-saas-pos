import type { MembershipStatus, StoreRole } from '@/types/enums'

/**
 * A store membership as returned by `GET /api/current-store/members`.
 *
 * `id` is the membership id (`store_user.id`), NOT the user id; it is what the
 * role/status endpoints address. `user_id` is the global account id.
 */
export interface StoreMember {
  id: number
  user_id: number
  name: string | null
  email: string | null
  role: StoreRole
  status: MembershipStatus
  is_active: boolean
  must_change_password: boolean
  joined_at: string | null
  updated_at: string | null
}

export interface MemberListParams {
  page?: number
  per_page?: number
  search?: string
  role?: StoreRole
  status?: MembershipStatus
  sort?: 'created_at' | 'role'
  direction?: 'asc' | 'desc'
}

/** Payload for creating an admin or cashier account (role is set by the endpoint). */
export interface MemberCreatePayload {
  name: string
  email: string
  password: string
  password_confirmation: string
}

/** Roles that may be assigned through the team endpoints (never `owner`). */
export type AssignableRole = 'admin' | 'cashier'
