import { Bell, FolderKanban, Gift, ReceiptText, UsersRound, WalletCards } from 'lucide-react'
import i18n from '../i18n'

// Mirrors the backend's NotificationResource::TYPE_CATEGORIES grouping —
// keep in sync. "approvals" isn't a category here for the same reason it
// isn't one on the backend: expense_pending/donation_pending/
// phase_request_pending are still each entity's own category, not a 4th
// bucket that would just be a filtered view of the other three.
// A function (not a static array) so labels resolve in whichever language
// is active at call time, rather than being frozen in English at
// module-load time.
export function getCategoryOptions() {
  return [
    { value: '', label: i18n.t('categories.all', { ns: 'notifications' }) },
    { value: 'membership', label: i18n.t('categories.membership', { ns: 'notifications' }) },
    { value: 'projects', label: i18n.t('categories.projects', { ns: 'notifications' }) },
    { value: 'expenses', label: i18n.t('categories.expenses', { ns: 'notifications' }) },
    { value: 'donations', label: i18n.t('categories.donations', { ns: 'notifications' }) },
    { value: 'committee', label: i18n.t('categories.committee', { ns: 'notifications' }) },
  ]
}

const CATEGORY_ICONS = {
  membership: WalletCards,
  projects: FolderKanban,
  expenses: ReceiptText,
  donations: Gift,
  committee: UsersRound,
}

export function iconForCategory(category) {
  return CATEGORY_ICONS[category] ?? Bell
}

export function categoryLabel(category) {
  return getCategoryOptions().find((option) => option.value === category)?.label ?? i18n.t('categories.other', { ns: 'notifications' })
}
