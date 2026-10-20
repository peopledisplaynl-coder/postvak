<script setup>
import { offlineNow } from '../api/offline.js'
import { t } from '../i18n/index.js'

/** Thin bar at the top while the app shows saved copies because there is no connection (offline reading). */
</script>

<template>
  <div v-if="offlineNow" class="pt-safe fixed inset-x-0 top-0 z-[60] flex justify-center" role="status">
    <p class="rounded-b-xl bg-ink px-4 py-1.5 text-xs text-canvas shadow">{{ t('offline.banner') }}</p>
  </div>
</template>
