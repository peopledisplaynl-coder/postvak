<script setup>
import { computed } from 'vue'
import AppIcon from './AppIcon.vue'
import { appState, applyUpdate, isNewer, APP_VERSION } from '../appUpdate.js'
import { t } from '../i18n/index.js'

/** Bar at the bottom of every screen when a new version of Postvak is available (all devices). */
const version = computed(() => (appState.serverVersion && isNewer(appState.serverVersion, APP_VERSION) ? appState.serverVersion : null))
</script>

<template>
  <div
    v-if="appState.updateReady"
    class="pb-safe pointer-events-none fixed inset-x-0 bottom-20 z-[60] flex justify-center p-3 lg:bottom-0"
    role="status"
  >
    <div class="pointer-events-auto flex max-w-md items-center gap-3 rounded-2xl bg-ink py-2 pr-2 pl-4 text-sm text-canvas shadow-lg">
      <AppIcon name="refresh" :size="18" class="shrink-0" />
      <span class="min-w-0">{{ version ? t('update.readyVersion', { version }) : t('update.ready') }}</span>
      <button class="shrink-0 rounded-xl bg-accent px-3.5 py-1.5 font-medium text-white hover:brightness-110" @click="applyUpdate">
        {{ t('update.refresh') }}
      </button>
    </div>
  </div>
</template>
