<script setup>
import ToastHost from './components/ToastHost.vue'
import UpdateBar from './components/UpdateBar.vue'
import OfflineBar from './components/OfflineBar.vue'

// A file dropped outside a drop zone would make the browser open it and leave the app.
const isFileDrag = (e) => [...(e.dataTransfer?.types ?? [])].includes('Files')
window.addEventListener('dragover', (e) => {
  if (!isFileDrag(e) || e.defaultPrevented) return // a drop zone already accepted it
  e.preventDefault()
  e.dataTransfer.dropEffect = 'none'
})
window.addEventListener('drop', (e) => { if (isFileDrag(e) && !e.defaultPrevented) e.preventDefault() })
</script>

<template>
  <RouterView />
  <ToastHost />
  <UpdateBar />
  <OfflineBar />
</template>
