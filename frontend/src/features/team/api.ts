import { http } from '@/lib/http'
import { cleanParams } from '@/lib/query'
import type { MessageEnvelope, Paginated } from '@/types/api'
import type {
  AssignableRole,
  MemberCreatePayload,
  MemberListParams,
  StoreMember,
} from './types'

/**
 * Team-management API calls, verified against `App\Http\Controllers\Api\StoreMemberController`
 * and `docs/api/openapi.yaml`. The active store is resolved by the backend; no
 * tenant id is ever sent from the client.
 */
async function list(params: MemberListParams = {}): Promise<Paginated<StoreMember>> {
  const response = await http.get<Paginated<StoreMember>>('/current-store/members', {
    params: cleanParams(params),
  })
  return response.data
}

async function createAdmin(payload: MemberCreatePayload): Promise<StoreMember> {
  const response = await http.post<MessageEnvelope<StoreMember>>(
    '/current-store/members/admin',
    payload,
  )
  return response.data.data
}

async function createCashier(payload: MemberCreatePayload): Promise<StoreMember> {
  const response = await http.post<MessageEnvelope<StoreMember>>(
    '/current-store/members/cashiers',
    payload,
  )
  return response.data.data
}

async function updateRole(memberId: number, role: AssignableRole): Promise<StoreMember> {
  const response = await http.patch<MessageEnvelope<StoreMember>>(
    `/current-store/members/${memberId}/role`,
    { role },
  )
  return response.data.data
}

async function updateStatus(memberId: number, isActive: boolean): Promise<StoreMember> {
  const response = await http.patch<MessageEnvelope<StoreMember>>(
    `/current-store/members/${memberId}/status`,
    { is_active: isActive },
  )
  return response.data.data
}

export const teamService = { list, createAdmin, createCashier, updateRole, updateStatus }
