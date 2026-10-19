<script setup>
import AppIcon from './AppIcon.vue'
import { ABOUT, CREDITS } from '../about.js'
import { t, language } from '../i18n/index.js'

/** Settings → About: what Postvak is, who makes it, licence, links and credits. */
const version = typeof __APP_VERSION__ === 'string' ? __APP_VERSION__ : ''
const links = [
  { label: 'about.manual', href: ABOUT.manual[language()] ?? ABOUT.manual.en },
  { label: 'about.website', href: ABOUT.website },
  { label: 'about.whatsNew', href: `${ABOUT.source}/releases` },
  { label: 'about.source', href: ABOUT.source },
  { label: 'about.report', href: `${ABOUT.source}/issues` },
]
const host = (url) => new URL(url).host
</script>

<template>
  <div class="space-y-6">
    <section class="flex flex-col items-center rounded-2xl bg-surface px-4 py-8 text-center ring-1 ring-line">
      <img src="/icon.svg" alt="" class="mb-4 size-16" />
      <h2 class="text-2xl font-semibold">Postvak</h2>
      <p class="mt-1 text-muted">{{ t('about.version', { version }) }}</p>
      <p class="mt-4 max-w-md">{{ t('about.tagline') }}</p>
    </section>

    <section class="divide-y divide-line rounded-2xl bg-surface ring-1 ring-line">
      <div class="flex items-center justify-between gap-4 px-4 py-3">
        <span class="text-muted">{{ t('about.madeBy') }}</span>
        <a :href="ABOUT.website" target="_blank" rel="noopener" class="font-medium text-accent hover:underline">{{ ABOUT.maker }}</a>
      </div>
      <div class="flex items-center justify-between gap-4 px-4 py-3">
        <span class="text-muted">{{ t('about.license') }}</span>
        <span>{{ t('about.licenseValue', { license: ABOUT.license }) }}</span>
      </div>
      <a
        v-for="l in links" :key="l.label" :href="l.href" target="_blank" rel="noopener"
        class="flex items-center justify-between gap-4 px-4 py-3 hover:bg-sunken"
      >
        <span>{{ t(l.label) }}</span>
        <span class="flex min-w-0 items-center gap-1 truncate text-sm text-muted">{{ host(l.href) }} <AppIcon name="external" :size="14" class="shrink-0" /></span>
      </a>
    </section>

    <section class="rounded-2xl bg-surface px-4 py-4 ring-1 ring-line">
      <h3 class="mb-1 font-medium">{{ t('about.privacyTitle') }}</h3>
      <p class="text-sm text-muted">{{ t('about.privacy', { maker: ABOUT.maker }) }}</p>
    </section>

    <section class="px-1">
      <h3 class="mb-2 text-sm font-medium text-muted">{{ t('about.credits') }}</h3>
      <p class="text-sm leading-7">
        <template v-for="([name, href], i) in CREDITS" :key="name">
          <a :href="href" target="_blank" rel="noopener" class="text-accent hover:underline">{{ name }}</a><span v-if="i < CREDITS.length - 1" class="text-muted"> · </span>
        </template>
      </p>
      <p class="mt-4 text-xs text-muted">© {{ ABOUT.year }} {{ ABOUT.maker }} · {{ t('about.contributors') }}</p>
    </section>
  </div>
</template>
