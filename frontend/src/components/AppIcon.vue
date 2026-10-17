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
