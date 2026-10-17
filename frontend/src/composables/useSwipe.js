import { ref, computed, onBeforeUnmount } from 'vue'

/**
 * Horizontal swipe on a list row, like Gmail.
 *
 * - Only reacts to touch and pen (desktop gets hover buttons instead).
 * - Waits until the gesture is clearly horizontal, so vertical scrolling keeps working.
 * - Past the threshold (or with a fast flick) the row flies out and onCommit(direction) runs.
 *
 * @param {object} opts
 * @param {() => boolean} opts.enabledLeft   swipe to the left allowed?
 * @param {() => boolean} opts.enabledRight  swipe to the right allowed?
 * @param {(dir: 'left'|'right') => Promise<boolean|void>|boolean|void} opts.onCommit
 *        return false to cancel (row springs back)
 */
export function useSwipe({ enabledLeft, enabledRight, onCommit }) {
  const offset = ref(0)
  const dragging = ref(false)
  const flying = ref(false)
  const width = ref(1)

  let startX = 0
  let startY = 0
  let lastX = 0
  let lastT = 0
  let velocity = 0
  let axis = null // null | 'x' | 'y'
  let pointerId = null
  let el = null
  let buzzed = false

  const THRESHOLD = 0.32 // fraction of the row width
  const FLICK = 0.6       // px per ms

  const direction = computed(() => (offset.value < 0 ? 'left' : offset.value > 0 ? 'right' : null))
  const progress = computed(() => Math.min(1, Math.abs(offset.value) / (width.value * THRESHOLD)))
  const armed = computed(() => progress.value >= 1)

  function onPointerDown(e) {
    if (e.pointerType === 'mouse' || flying.value) return
    el = e.currentTarget
    width.value = el.offsetWidth || 1
    pointerId = e.pointerId
    startX = lastX = e.clientX
    startY = e.clientY
    lastT = e.timeStamp
    velocity = 0
    axis = null
    buzzed = false
  }

  function onPointerMove(e) {
    if (e.pointerId !== pointerId) return
    const dx = e.clientX - startX
    const dy = e.clientY - startY

    if (axis === null) {
      if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return
      axis = Math.abs(dx) > Math.abs(dy) * 1.2 ? 'x' : 'y'
      if (axis === 'x') {
        dragging.value = true
        try { el.setPointerCapture?.(pointerId) } catch { /* synthetic or already released */ }
      }
    }
    if (axis !== 'x') return

    e.preventDefault()
    let next = dx
    if ((next < 0 && !enabledLeft()) || (next > 0 && !enabledRight())) next = next * 0.15 // resistance
    offset.value = next

    const dt = e.timeStamp - lastT
    if (dt > 0) velocity = (e.clientX - lastX) / dt
    lastX = e.clientX
    lastT = e.timeStamp

    // A short buzz when crossing the threshold, like a physical detent.
    if (armed.value && !buzzed) {
      navigator.vibrate?.(8)
      buzzed = true
    } else if (!armed.value) {
      buzzed = false
    }
  }

  async function onPointerUp(e) {
    if (e.pointerId !== pointerId) return
    pointerId = null
    if (axis !== 'x') {
      axis = null
      return
    }
    axis = null
    dragging.value = false

    const dir = offset.value < 0 ? 'left' : 'right'
    const allowed = dir === 'left' ? enabledLeft() : enabledRight()
    const flick = Math.abs(velocity) > FLICK && Math.sign(velocity) === Math.sign(offset.value)

    if (allowed && (armed.value || flick)) {
      flying.value = true
      offset.value = (dir === 'left' ? -1 : 1) * width.value * 1.05
      await new Promise((r) => setTimeout(r, 180))
      const result = await onCommit(dir)
      if (result === false) {
        offset.value = 0
        setTimeout(() => (flying.value = false), 200)
      }
      // When the action succeeds the row is removed from the list, so no reset needed.
      return
    }
    offset.value = 0
  }

  function onPointerCancel(e) {
    if (e.pointerId !== pointerId) return
    pointerId = null
    axis = null
    dragging.value = false
    offset.value = 0
  }

  /** Swallow the click that follows a drag, so swiping does not also open the message. */
  function onClickCapture(e) {
    if (Math.abs(offset.value) > 4 || flying.value) {
      e.stopPropagation()
      e.preventDefault()
    }
  }

  onBeforeUnmount(() => {
    pointerId = null
  })

  return {
    offset, dragging, flying, direction, progress, armed,
    handlers: {
      onPointerdown: onPointerDown,
      onPointermove: onPointerMove,
      onPointerup: onPointerUp,
      onPointercancel: onPointerCancel,
      onClickCapture,
    },
  }
}
