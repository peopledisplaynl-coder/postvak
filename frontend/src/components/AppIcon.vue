<script setup>
// Small inline icon set (24px grid, stroke-based). Add new icons to the paths map.
const props = defineProps({
  name: { type: String, required: true },
  size: { type: [Number, String], default: 20 },
})

const paths = {
  archive: 'M4 7h16M5 7l1 12a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1l1-12M3 4h18v3H3zM10 11h4',
  trash: 'M4 7h16M10 11v6M14 11v6M6 7l1 12a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-12M9 7V4h6v3',
  read: 'M3 8l9 6 9-6M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1z',
  flag: 'M12 3.5l2.6 5.3 5.9.9-4.3 4.1 1 5.8L12 16.9l-5.2 2.7 1-5.8L3.5 9.7l5.9-.9z',
  inbox: 'M3 13h5l2 3h4l2-3h5M5 5h14l2 8v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-6z',
  inboxes: 'M3 14h5l2 3h4l2-3h5M5 7h14l2 7v5a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1v-5zM7 3h10',
  sent: 'M21 3L10 14M21 3l-7 18-4-7-7-4z',
  drafts: 'M4 20h4L19 9l-4-4L4 16zM13.5 6.5l4 4',
  junk: 'M12 3l9 16H3zM12 10v4M12 17h.01',
  folder: 'M3 7a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1z',
  menu: 'M4 7h16M4 12h16M4 17h16',
  back: 'M15 5l-7 7 7 7',
  search: 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4',
  close: 'M6 6l12 12M18 6L6 18',
  compose: 'M12 5v14M5 12h14',
  reply: 'M10 8L4 13l6 5M4 13h10a6 6 0 0 1 6 6',
  replyAll: 'M12 8l-5 5 5 5M7 8l-5 5 5 5M7 13h7a6 6 0 0 1 6 6',
  forward: 'M14 8l6 5-6 5M20 13H10a6 6 0 0 0-6 6',
  paperclip: 'M20 11l-8 8a5 5 0 0 1-7-7l8-8a3.5 3.5 0 0 1 5 5l-8 8a2 2 0 0 1-3-3l7-7',
  refresh: 'M20 11a8 8 0 1 0-2.3 5.7M20 4v7h-7',
  settings: 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19.4 15a1.6 1.6 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.6 1.6 0 0 0-2.7 1.1V21a2 2 0 1 1-4 0v-.1a1.6 1.6 0 0 0-2.7-1.1l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1A1.6 1.6 0 0 0 3 14.4H3a2 2 0 1 1 0-4h.1a1.6 1.6 0 0 0 1.1-2.7l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1A1.6 1.6 0 0 0 9.6 4V3a2 2 0 1 1 4 0v.1a1.6 1.6 0 0 0 2.7 1.1l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.6 1.6 0 0 0 1.1 2.7H21a2 2 0 1 1 0 4h-.1a1.6 1.6 0 0 0-1.5 1z',
  logout: 'M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l-4-4 4-4M6 12h10',
  none: 'M6 12h12',
  unread: 'M3 8l9 6 6-4M14 6H4a1 1 0 0 0-1 1v10a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-6M19 3.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5z',
  chevron: 'M9 6l6 6-6 6',
  plus: 'M12 5v14M5 12h14',
  send: 'M5 12l14-7-5 14-3-6zM11 13l8-8',
  sort: 'M7 4v16M3 16l4 4 4-4M17 20V4M13 8l4-4 4 4',
  check: 'M5 12.5l4.5 4.5L19 7.5',
  move: 'M3 7a1 1 0 0 1 1-1h5l2 2h9a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM10 13.5h6M13.5 11l2.5 2.5-2.5 2.5',
  selectAll: 'M4 6h2M4 12h2M4 18h2M9 6h11M9 12h11M9 18h11',
  contacts: 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1M16 3.5a4 4 0 0 1 0 7M18 14a6 6 0 0 1 4 5.7V21',
  userPlus: 'M10 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM3 21v-1a6 6 0 0 1 6-6h2a6 6 0 0 1 6 6v1M19 8v6M16 11h6',
  upload: 'M12 16V4M7 9l5-5 5 5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3',
  download: 'M12 4v12M7 11l5 5 5-5M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3',
  phone: 'M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a1 1 0 0 1-1 1A16 16 0 0 1 4 5a1 1 0 0 1 1-1z',
  more: 'M11 6a1 1 0 1 0 2 0a1 1 0 1 0-2 0M11 12a1 1 0 1 0 2 0a1 1 0 1 0-2 0M11 18a1 1 0 1 0 2 0a1 1 0 1 0-2 0',
  listBullet: 'M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01',
  listNumber: 'M10 6h10M10 12h10M10 18h10M4 5l1.5-1V9M3.5 15.5a1.2 1.2 0 0 1 2.3.3c0 .9-2.3 1.7-2.3 3.2h2.5',
  quote: 'M7 7h4v5c0 2.5-1.5 4-4 5M15 7h4v5c0 2.5-1.5 4-4 5',
  lock: 'M6 11h12v9H6zM8 11V8a4 4 0 0 1 8 0v3',
  globe: 'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18M3 12h18M12 3c2.5 3 2.5 15 0 18M12 3c-2.5 3-2.5 15 0 18',
  share: 'M4 12v7a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-7M12 3v12M8 7l4-4 4 4',
  link: 'M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1',
  copy: 'M9 9h10a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V10a1 1 0 0 1 1-1zM5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1',
  calendar: 'M4 6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1zM4 10h16M8 3v4M16 3v4',
  task: 'M4 5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1zM8 12l3 3 5-6',
  circle: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
  checkCircle: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12.5l2.7 2.7L16 9.5',
  pin: 'M12 21s-7-6.2-7-11.5a7 7 0 0 1 14 0C19 14.8 12 21 12 21zM12 12a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  repeat: 'M17 2l3 3-3 3M4 11V9a4 4 0 0 1 4-4h12M7 22l-3-3 3-3M20 13v2a4 4 0 0 1-4 4H4',
  bell: 'M6 16V11a6 6 0 1 1 12 0v5l2 2H4zM10 21h4',
  mail: 'M3 8l9 6 9-6M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1z',
  chevronLeft: 'M15 5l-7 7 7 7',
  chevronRight: 'M9 5l7 7-7 7',
  clearFormat: 'M6 5h12M12 5l-3 14M7 19h4M15 14l5 5M20 14l-5 5',
  undo: 'M9 14L4 9l5-5M4 9h11a5 5 0 0 1 0 10h-3',
  redo: 'M15 14l5-5-5-5M20 9H9a5 5 0 0 0 0 10h3',
  clock: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
  snooze: 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM12 7v5l3 2',
  scheduled: 'M11 20a8 8 0 1 1 8-8M11 7v5l3 2M16 19l3-3-3-3M13 16h6',
  keyboard: 'M3 7a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM7 10h.01M11 10h.01M15 10h.01M7 14h10',
  print: 'M7 9V4h10v5M7 17H5a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-2M7 14h10v6H7z',
  eye: 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6z',
  shield: 'M12 3l8 3v6c0 4.5-3.4 8.3-8 9-4.6-.7-8-4.5-8-9V6zM9 12l2 2 4-4',
}
</script>

<template>
  <svg
    :width="size" :height="size" viewBox="0 0 24 24" fill="none" stroke="currentColor"
    stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"
  >
    <path :d="paths[name] ?? paths.folder" />
  </svg>
</template>
