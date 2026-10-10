import { ApiError, humanMessage } from '@/lib/errors'

/**
 * Map team-management error codes to safe, user-facing Indonesian messages.
 * Falls back to the shared error mapper for anything unmapped. Backend messages
 * for 409 are safe to surface, but the explicit mapping keeps the copy stable.
 */
export function memberErrorMessage(error: ApiError): string {
  switch (error.code) {
    case 'admin_limit_reached':
      return 'Toko ini sudah memiliki admin aktif. Nonaktifkan admin tersebut terlebih dahulu.'
    case 'owner_protected':
      return 'Akun pemilik (owner) tidak dapat diubah melalui halaman ini.'
    case 'email_already_registered':
      return 'Email sudah terdaftar. Gunakan email lain.'
    case 'member_revoked':
      return 'Anggota ini tidak lagi aktif.'
    default:
      return humanMessage(error)
  }
}

/** Extract per-field validation messages from a 422 response. */
export function fieldErrorsFrom(error: ApiError): Record<string, string> {
  const mapped: Record<string, string> = {}
  for (const [field, messages] of Object.entries(error.errors ?? {})) {
    if (messages[0]) {
      mapped[field] = messages[0]
    }
  }
  return mapped
}
