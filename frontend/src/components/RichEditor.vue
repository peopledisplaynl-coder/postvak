<script setup>
import { ref, onBeforeUnmount } from 'vue'
import { useEditor, EditorContent } from '@tiptap/vue-3'
import StarterKit from '@tiptap/starter-kit'
import { TextStyle, Color } from '@tiptap/extension-text-style'
import Image from '@tiptap/extension-image'
import { Placeholder } from '@tiptap/extensions'
import AppIcon from './AppIcon.vue'
import { t } from '../i18n/index.js'

/**
 * Rich text editor for the composer (Tiptap / ProseMirror).
 *
 * Pasted content (Word, web pages, other mail) is reduced to what the schema knows,
 * so no hidden Word markup ends up in the mail. Pasted or dropped image files are
 * handed to the composer as attachments instead of being embedded.
 *
 * Emits: update (content changed by the user), files (File[] pasted), submit (Ctrl+Enter).
 */
const props = defineProps({ placeholder: { type: String, default: '' }, initial: { type: String, default: '' } })
const emit = defineEmits(['update', 'files', 'submit'])

const COLORS = ['#1f2328', '#6b7280', '#c2463c', '#c27a0e', '#0f8a5f', '#2c7da0', '#3646d9', '#7a5ac8']
const colorOpen = ref(false)
const headingOpen = ref(false)

const editor = useEditor({
  content: props.initial || '',
  extensions: [
    StarterKit.configure({
      heading: { levels: [2, 3] },
      code: false,
      codeBlock: false,
      link: { openOnClick: false, autolink: true, linkOnPaste: true, defaultProtocol: 'https', HTMLAttributes: { rel: 'noopener noreferrer', target: '_blank' } },
    }),
    TextStyle,
    Color,
    Image.configure({ inline: true, allowBase64: false }),
    Placeholder.configure({ placeholder: () => props.placeholder }),
  ],
  editorProps: {
    attributes: { class: 'compose-editor', role: 'textbox', 'aria-multiline': 'true', spellcheck: 'true' },
    handlePaste(view, event) {
      const files = [...(event.clipboardData?.files ?? [])]
      if (files.length && !event.clipboardData.getData('text/html')) {
        emit('files', files)
        return true
      }
      return false
    },
    handleKeyDown(view, event) {
      if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
        emit('submit')
        return true
      }
      return false
    },
  },
  onUpdate: () => emit('update'),
})

onBeforeUnmount(() => editor.value?.destroy())

// ---- toolbar actions
const chain = () => editor.value.chain().focus()

function setLink() {
  const current = editor.value.getAttributes('link').href
  if (current) {
    chain().extendMarkRange('link').unsetLink().run()
    return
  }
  let url = prompt(t('editor.linkPrompt'), 'https://')
  if (!url || url === 'https://') return
  url = url.trim()
  if (!/^(https?:|mailto:|tel:)/i.test(url)) url = url.includes('@') && !url.includes('/') ? `mailto:${url}` : `https://${url}`
  if (editor.value.state.selection.empty) {
    chain().insertContent({ type: 'text', text: url.replace(/^mailto:/i, ''), marks: [{ type: 'link', attrs: { href: url } }] }).run()
  } else {
    chain().extendMarkRange('link').setLink({ href: url }).run()
  }
}

function setColor(c) {
  colorOpen.value = false
  if (c) chain().setColor(c).run()
  else chain().unsetColor().run()
}

function setBlock(kind) {
  headingOpen.value = false
  if (kind === 'p') chain().setParagraph().run()
  else chain().toggleHeading({ level: kind }).run()
}

const blockLabel = () => {
  if (editor.value?.isActive('heading', { level: 2 })) return t('editor.heading')
  if (editor.value?.isActive('heading', { level: 3 })) return t('editor.subheading')
  return t('editor.normal')
}

const active = (name, attrs) => editor.value?.isActive(name, attrs)

// ---- API for the composer

/** Set content without counting it as a user change. */
function setContent(html) {
  editor.value?.commands.setContent(html || '', { emitUpdate: false })
}

/**
 * HTML for the outgoing mail. Empty paragraphs get a <br> (otherwise most mail
 * programs collapse them), and paragraphs get a small inline margin.
 */
function getHtml() {
  const html = editor.value?.getHTML() ?? ''
  return html
    .replace(/<p><\/p>/g, '<p style="margin:0"><br></p>')
    .replace(/<p>/g, '<p style="margin:0">')
    .replace(/<blockquote>/g, '<blockquote style="margin:0 0 0 .8ex;border-left:2px solid #ccc;padding-left:1ex">')
}

function getText() {
  return editor.value?.getText() ?? ''
}

/** Insert HTML at the cursor (a template); counts as a change. */
function insertContent(html) {
  editor.value?.chain().focus().insertContent(html || '').run()
}

function focus(where = 'start') {
  editor.value?.commands.focus(where)
}

defineExpose({ setContent, getHtml, getText, focus, insertContent })
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col">
    <!-- Toolbar -->
    <div v-if="editor" class="flex flex-wrap items-center gap-0.5 border-b border-line px-2 py-1 text-muted" role="toolbar" :aria-label="t('editor.toolbar')">
      <div class="relative shrink-0">
        <button type="button" class="flex items-center gap-1 rounded px-2 py-1 text-sm hover:bg-sunken" :aria-expanded="headingOpen" @mousedown.prevent @click="headingOpen = !headingOpen">
          {{ blockLabel() }} <AppIcon name="chevron" :size="14" class="rotate-90" />
        </button>
        <div v-if="headingOpen" class="fixed inset-0 z-20" @click="headingOpen = false" />
        <div v-if="headingOpen" class="absolute left-0 z-30 mt-1 w-40 overflow-hidden rounded-xl bg-surface py-1 text-ink shadow-lg ring-1 ring-line">
          <button type="button" class="block w-full px-3 py-1.5 text-left hover:bg-sunken" @mousedown.prevent @click="setBlock('p')">{{ t('editor.normal') }}</button>
          <button type="button" class="block w-full px-3 py-1.5 text-left text-lg font-semibold hover:bg-sunken" @mousedown.prevent @click="setBlock(2)">{{ t('editor.heading') }}</button>
          <button type="button" class="block w-full px-3 py-1.5 text-left font-semibold hover:bg-sunken" @mousedown.prevent @click="setBlock(3)">{{ t('editor.subheading') }}</button>
        </div>
      </div>
      <span class="mx-1 h-5 w-px shrink-0 bg-line" />
      <button type="button" class="tb font-bold" :class="{ on: active('bold') }" :title="t('editor.bold')" @mousedown.prevent @click="chain().toggleBold().run()">B</button>
      <button type="button" class="tb italic" :class="{ on: active('italic') }" :title="t('editor.italic')" @mousedown.prevent @click="chain().toggleItalic().run()">I</button>
      <button type="button" class="tb underline" :class="{ on: active('underline') }" :title="t('editor.underline')" @mousedown.prevent @click="chain().toggleUnderline().run()">U</button>
      <button type="button" class="tb line-through" :class="{ on: active('strike') }" :title="t('editor.strike')" @mousedown.prevent @click="chain().toggleStrike().run()">S</button>
      <div class="relative shrink-0">
        <button type="button" class="tb" :title="t('editor.color')" :aria-expanded="colorOpen" @mousedown.prevent @click="colorOpen = !colorOpen">
          <span class="font-semibold underline decoration-[3px] underline-offset-2" :style="{ textDecorationColor: editor.getAttributes('textStyle').color || 'currentColor' }">A</span>
        </button>
        <div v-if="colorOpen" class="fixed inset-0 z-20" @click="colorOpen = false" />
        <div v-if="colorOpen" class="absolute left-0 z-30 mt-1 flex w-max gap-1.5 rounded-xl bg-surface p-2 shadow-lg ring-1 ring-line">
          <button
            v-for="c in COLORS"
            :key="c"
            type="button"
            class="size-6 rounded-full ring-1 ring-line"
            :style="{ background: c }"
            :aria-label="c"
            @mousedown.prevent
            @click="setColor(c)"
          />
          <button type="button" class="rounded px-1.5 text-xs text-muted hover:bg-sunken" @mousedown.prevent @click="setColor(null)">{{ t('editor.noColor') }}</button>
        </div>
      </div>
      <span class="mx-1 h-5 w-px shrink-0 bg-line" />
      <button type="button" class="tb" :class="{ on: active('bulletList') }" :title="t('editor.bullets')" @mousedown.prevent @click="chain().toggleBulletList().run()">
        <AppIcon name="listBullet" :size="18" />
      </button>
      <button type="button" class="tb" :class="{ on: active('orderedList') }" :title="t('editor.numbers')" @mousedown.prevent @click="chain().toggleOrderedList().run()">
        <AppIcon name="listNumber" :size="18" />
      </button>
      <button type="button" class="tb" :class="{ on: active('blockquote') }" :title="t('editor.quote')" @mousedown.prevent @click="chain().toggleBlockquote().run()">
        <AppIcon name="quote" :size="18" />
      </button>
      <button type="button" class="tb" :class="{ on: active('link') }" :title="active('link') ? t('editor.unlink') : t('editor.link')" @mousedown.prevent @click="setLink">
        <AppIcon name="link" :size="18" />
      </button>
      <button type="button" class="tb" :title="t('editor.clear')" @mousedown.prevent @click="chain().unsetAllMarks().clearNodes().run()">
        <AppIcon name="clearFormat" :size="18" />
      </button>
      <span class="mx-1 hidden h-5 w-px shrink-0 bg-line sm:block" />
      <button type="button" class="tb hidden sm:flex" :disabled="!editor.can().undo()" :title="t('editor.undo')" @mousedown.prevent @click="chain().undo().run()">
        <AppIcon name="undo" :size="18" />
      </button>
      <button type="button" class="tb hidden sm:flex" :disabled="!editor.can().redo()" :title="t('editor.redo')" @mousedown.prevent @click="chain().redo().run()">
        <AppIcon name="redo" :size="18" />
      </button>
    </div>

    <EditorContent :editor="editor" class="min-h-0 flex-1 overflow-y-auto px-4 py-3" @click.self="focus('end')" />
  </div>
</template>

<style scoped>
.tb {
  display: flex;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  min-width: 2rem;
  height: 2rem;
  border-radius: 0.375rem;
  padding: 0 0.4rem;
}
.tb:hover:not(:disabled) {
  background: var(--color-sunken);
  color: var(--color-ink);
}
.tb.on {
  background: var(--color-accent-soft);
  color: var(--color-accent);
}
.tb:disabled {
  opacity: 0.35;
}
:deep(.compose-editor) {
  min-height: 100%;
  outline: none;
  overflow-wrap: anywhere;
}
:deep(.compose-editor p) {
  margin: 0;
  min-height: 1.5em;
}
:deep(.compose-editor h2) {
  font-size: 1.35em;
  font-weight: 600;
  margin: 0.4em 0 0.2em;
}
:deep(.compose-editor h3) {
  font-size: 1.12em;
  font-weight: 600;
  margin: 0.4em 0 0.2em;
}
:deep(.compose-editor ul) {
  list-style: disc;
  padding-left: 1.5em;
}
:deep(.compose-editor ol) {
  list-style: decimal;
  padding-left: 1.5em;
}
:deep(.compose-editor blockquote) {
  margin: 0.25em 0 0.25em 0.25em;
  padding-left: 0.75em;
  border-left: 3px solid var(--color-line);
  color: var(--color-muted);
}
:deep(.compose-editor a) {
  color: var(--color-accent);
  text-decoration: underline;
}
:deep(.compose-editor img) {
  display: inline;
  max-width: 100%;
}
:deep(.compose-editor p.is-editor-empty:first-child::before) {
  content: attr(data-placeholder);
  color: var(--color-muted);
  float: left;
  height: 0;
  pointer-events: none;
}
</style>
