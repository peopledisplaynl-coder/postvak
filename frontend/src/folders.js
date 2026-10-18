import { t } from './i18n/index.js'

const ROLE_ICONS = { inbox: 'inbox', drafts: 'drafts', sent: 'sent', archive: 'archive', junk: 'junk', trash: 'trash', scheduled: 'scheduled', snoozed: 'clock' }

/** Display name of a folder: translated for special folders, otherwise the path without "INBOX". */
export function folderLabel(f) {
  if (!f) return ''
  if (f.role) return t(`folders.${f.role}`)
  const parts = (f.full_name ?? f.name).split(f.delimiter || '/')
  if (parts.length > 1 && parts[0].toUpperCase() === 'INBOX') parts.shift()
  return parts.join(' / ')
}

/** Name of the folder itself (last part), for renaming. */
export function folderOwnName(f) {
  const parts = (f.full_name ?? f.name).split(f.delimiter || '/')
  return parts[parts.length - 1]
}

export function folderIcon(f) {
  return ROLE_ICONS[f?.role] ?? 'folder'
}
