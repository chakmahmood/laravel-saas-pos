import { http } from '@/lib/http'
import type { DataEnvelope, MessageEnvelope } from '@/types/api'
import type {
  ChangePasswordPayload,
  CurrentStore,
  LoginResult,
  MeResult,
  RegisterPayload,
  RegisterResult,
} from '@/types/models'

/**
 * Session / tenant API calls. Endpoints and payloads mirror the actual backend
 * contract (`docs/api/openapi.yaml`, `docs/api/frontend-integration-guide.md`).
 */
export const sessionService = {
  async login(email: string, password: string): Promise<LoginResult> {
    const response = await http.post<MessageEnvelope<LoginResult>>('/auth/login', {
      email,
      password,
    })
    return response.data.data
  },

  async register(payload: RegisterPayload): Promise<RegisterResult> {
    const response = await http.post<MessageEnvelope<RegisterResult>>('/auth/register', payload)
    return response.data.data
  },

  async logout(): Promise<void> {
    await http.post('/auth/logout')
  },

  async me(): Promise<MeResult> {
    const response = await http.get<DataEnvelope<MeResult>>('/me')
    return response.data.data
  },

  async getCurrentStore(): Promise<CurrentStore | null> {
    const response = await http.get<DataEnvelope<{ current_store: CurrentStore | null }>>(
      '/current-store',
    )
    return response.data.data.current_store
  },

  async selectStore(storeId: number): Promise<CurrentStore> {
    const response = await http.put<MessageEnvelope<{ current_store: CurrentStore }>>(
      '/current-store',
      { store_id: storeId },
    )
    return response.data.data.current_store
  },

  /**
   * Change the authenticated user's own password. Also clears the backend's
   * `must_change_password` flag. The password is never persisted client-side.
   */
  async changePassword(payload: ChangePasswordPayload): Promise<void> {
    await http.post('/auth/change-password', payload)
  },
}
