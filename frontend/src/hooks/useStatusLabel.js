import { useTranslation } from 'react-i18next'

// Centralizes status-value -> display-label translation so every page uses
// the exact same French wording for the same backend status (e.g. 'approved'
// always reads "Approuvé"), instead of each component doing its own
// ucfirst()-style formatting. The raw backend value is never changed —
// this only maps it to display text.
export function useStatusLabel() {
  const { t } = useTranslation('common')

  return function statusLabel(status) {
    if (!status) return ''
    return t(`status.${status}`, { defaultValue: status })
  }
}

export function useRoleLabel() {
  const { t } = useTranslation('common')

  return function roleLabel(role) {
    if (!role) return ''
    return t(`roles.${role}`, { defaultValue: role })
  }
}
