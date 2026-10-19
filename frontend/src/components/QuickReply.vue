<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import AppIcon from './AppIcon.vue'
import { useSession } from '../stores/session.js'
import { displayName } from '../format.js'
import { t } from '../i18n/index.js'

/**
 * Short answer under the open message. "Send" goes through the normal composer (signature, quote, undo send),
 * which stays out of sight; "Full editor" opens the composer with the text so far.
 */
const props = defineProps({ message: { type: Object, required: true } })
const emit = defineEmits(['reply'])
const session = useSession()

const text = ref('')
const all = ref(false)
const box = ref(null)
const focused = ref(false)

// Someone besides the sender and me got the message: offer "reply all".
const mine = computed(() => new Set(session.accounts.map((a) => a.email.toLowerCase())))
const others = computed(() => {
  const m = props.message
  const seen = new Set([m.from?.email?.toLowerCase()])
  return [...(m.to ?? []), ...(m.cc ?? [])].filter((r) => {
    const e = r.email?.toLowerCase()
    if (!e || mine.value.has(e) || seen.has(e)) return false
    seen.add(e)
    return true
  })
})
const own = computed(() => mine.value.has(props.message.from?.email?.toLowerCase()))
const recipient = computed(() => (own.value ? props.message.to?.[0] : props.message.reply_to?.[0] ?? props.message.from))
const placeholder = computed(() => t(all.value && others.value.length ? 'quickReply.placeholderAll' : 'quickReply.placeholder', { name: displayName(recipient.value) }))

watch(() => props.message, () => { text.value = ''; all.value = false })

function grow() {
  const el = box.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = Math.min(el.scrollHeight, 240) + 'px'
}
watch(text, () => nextTick(grow))

function go(send) {
  if (send && !text.value.trim()) return
  emit('reply', { mode: all.value && others.value.length ? 'replyAll' : 'reply', message: props.message, text: text.value, send })
  text.value = ''
}

function onKeydown(e) {
  if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
    e.preventDefault()
    go(true)
  }
}
</script>

<template>
  <div class="mt-6 rounded-2xl bg-surface ring-1 transition" :class="focused ? 'ring-accent' : 'ring-line'">
    <textarea
      ref="box" v-model="text" rows="2" :placeholder="placeholder" :aria-label="t('quickReply.label')"
      class="block w-full resize-none rounded-t-2xl bg-transparent px-4 pt-3 pb-1 outline-none"
      @focus="focused = true" @blur="focused = false" @keydown="onKeydown"
    />
    <div class="flex items-center gap-2 px-2 pb-2">
      <button
        v-if="others.length"
        type="button"
        class="rounded-full px-3 py-1 text-sm ring-1 transition"
        :class="all ? 'bg-accent-soft text-accent ring-accent/40' : 'text-muted ring-line hover:bg-sunken'"
        :aria-pressed="all"
        @click="all = !all"
      >{{ t('actions.replyAll') }}</button>
      <button type="button" class="ml-auto flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-muted hover:bg-sunken hover:text-ink" :title="t('quickReply.full')" @click="go(false)">
        <AppIcon name="expand" :size="16" /><span class="hidden sm:inline">{{ t('quickReply.full') }}</span>
      </button>
      <button
        type="button" :disabled="!text.trim()"
        class="flex items-center gap-1.5 rounded-xl bg-accent px-4 py-1.5 text-sm font-medium text-white disabled:opacity-50"
        :title="t('quickReply.sendHint')"
        @click="go(true)"
      ><AppIcon name="send" :size="16" />{{ t('compose.send') }}</button>
    </div>
  </div>
</template>
