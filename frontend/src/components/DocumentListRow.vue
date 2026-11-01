<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DocumentItem } from '@/types'
import StatusBadge from '@/components/StatusBadge.vue'
import {
  documentDateLabel,
  formatAmount,
  hasAmount,
  primary,
} from '@/lib/documentDisplay'

const { t } = useI18n()

export type DocumentMenuAction = 'share' | 'move' | 'change_category' | 'delete'

const props = withDefaults(
  defineProps<{
    doc: DocumentItem
    showStatus?: boolean
    categoryLabel?: string | null
    variant?: 'default' | 'card'
    selectionMode?: boolean
    isSelected?: boolean
    selectable?: boolean
  }>(),
  {
    variant: 'default',
    selectionMode: false,
    isSelected: false,
    selectable: false,
  },
)

const emit = defineEmits<{
  menu: [action: DocumentMenuAction]
  longpress: []
  toggle: []
}>()

const menuOpen = ref(false)
let longPressTimer: number | null = null
let suppressClicksUntil = 0
let pressStartX = 0
let pressStartY = 0

const title = computed(() => primary(props.doc))
const amountValue = computed(
  () => props.doc.display_amount || (hasAmount(props.doc) ? formatAmount(props.doc) : null),
)

const showMenu = computed(
  () => props.variant === 'card' && !props.showStatus && !props.selectionMode,
)

const referenceNumber = computed(() => props.doc.reference_number?.trim() || null)
const dateLabel = computed(() => documentDateLabel(props.doc))

const metaLine = computed(() => {
  const parts: string[] = []
  if (dateLabel.value) {
    parts.push(dateLabel.value)
  }
  if (props.categoryLabel?.trim()) {
    parts.push(props.categoryLabel.trim())
  }
  return parts.length ? parts.join(' · ') : null
})

const showFooter = computed(
  () => Boolean(metaLine.value || props.showStatus || showMenu.value),
)

function toggleMenu() {
  menuOpen.value = !menuOpen.value
}

function pick(action: DocumentMenuAction) {
  menuOpen.value = false
  emit('menu', action)
}

function clearLongPress() {
  if (longPressTimer !== null) {
    window.clearTimeout(longPressTimer)
    longPressTimer = null
  }
}

function fireLongPress() {
  // Ignore the click / contextmenu that browsers fire right after a hold.
  suppressClicksUntil = Date.now() + 500
  menuOpen.value = false
  emit('longpress')
}

function shouldSuppressActivate(): boolean {
  return Date.now() < suppressClicksUntil
}

function onPointerDown(event: PointerEvent) {
  if (!props.selectable || event.button !== 0) return
  const target = event.target as HTMLElement | null
  if (target?.closest('[data-doc-menu]')) return

  pressStartX = event.clientX
  pressStartY = event.clientY
  clearLongPress()
  longPressTimer = window.setTimeout(() => {
    longPressTimer = null
    fireLongPress()
  }, 450)
}

function onPointerMove(event: PointerEvent) {
  if (longPressTimer === null) return
  if (Math.abs(event.clientX - pressStartX) > 10 || Math.abs(event.clientY - pressStartY) > 10) {
    clearLongPress()
  }
}

function onPointerUp() {
  clearLongPress()
}

function onActivate(event: MouseEvent | KeyboardEvent) {
  if (shouldSuppressActivate()) {
    event.preventDefault()
    event.stopPropagation()
    return
  }
  if (props.selectionMode) {
    event.preventDefault()
    emit('toggle')
  }
}

function onContextMenu(event: MouseEvent) {
  if (!props.selectable) {
    return
  }
  event.preventDefault()
  // Mobile long-press often also fires contextmenu; don't emit twice.
  if (shouldSuppressActivate()) {
    return
  }
  fireLongPress()
}

function onDocumentClick(event: MouseEvent) {
  const target = event.target as HTMLElement | null
  if (!target?.closest('[data-doc-menu]')) {
    menuOpen.value = false
  }
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onUnmounted(() => {
  document.removeEventListener('click', onDocumentClick)
  clearLongPress()
})
</script>

<template>
  <div
    class="relative"
    :class="[
      variant === 'card'
        ? 'rounded-2xl border bg-white px-3 py-3 shadow-sm'
        : 'rounded-xl px-3 py-3 hover:bg-gray-50',
      isSelected ? 'border-primary bg-soft-blue/40' : variant === 'card' ? 'border-gray-100' : '',
    ]"
    @pointerdown="onPointerDown"
    @pointermove="onPointerMove"
    @pointerup="onPointerUp"
    @pointercancel="onPointerUp"
    @pointerleave="onPointerUp"
    @contextmenu="onContextMenu"
  >
    <button
      v-if="selectionMode"
      type="button"
      class="flex w-full items-center gap-3 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      :aria-pressed="isSelected"
      :aria-label="isSelected ? t('aria_deselect_document') : t('aria_select_document')"
      @click="onActivate"
    >
      <span
        class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full border"
        :class="isSelected ? 'border-primary bg-primary text-white' : 'border-gray-300 bg-white text-transparent'"
        aria-hidden="true"
      >
        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7" />
        </svg>
      </span>
      <span class="min-w-0 flex-1">
        <span class="block truncate text-[15px] font-semibold text-gray-900">{{ title }}</span>
        <span
          v-if="referenceNumber"
          class="mt-0.5 block truncate text-xs text-gray-500"
        >
          {{ t('label_ref_prefix') }} {{ referenceNumber }}
        </span>
      </span>
      <span
        v-if="amountValue"
        class="shrink-0 text-sm font-semibold tabular-nums text-primary"
      >
        {{ amountValue }}
      </span>
    </button>

    <RouterLink
      v-else
      :to="`/files/${doc.id}`"
      class="block focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      :class="variant === 'card' ? 'hover:opacity-95' : ''"
      @click="onActivate"
    >
      <span class="flex items-center gap-3">
        <span class="min-w-0 flex-1">
          <span class="block truncate text-[15px] font-semibold text-gray-900">{{ title }}</span>
          <span
            v-if="referenceNumber"
            class="mt-0.5 block truncate text-xs text-gray-500"
          >
            {{ t('label_ref_prefix') }} {{ referenceNumber }}
          </span>
        </span>
        <span
          v-if="amountValue"
          class="shrink-0 text-sm font-semibold tabular-nums text-primary"
        >
          {{ amountValue }}
        </span>
      </span>
    </RouterLink>

    <div
      v-if="showFooter"
      class="mt-2.5 flex items-center gap-2 border-t border-gray-100 pt-2"
      :class="selectionMode ? 'pl-9' : ''"
    >
      <button
        v-if="selectionMode"
        type="button"
        class="min-w-0 flex-1 truncate text-left text-xs text-gray-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        :aria-pressed="isSelected"
        :aria-label="isSelected ? t('aria_deselect_document') : t('aria_select_document')"
        @click="onActivate"
      >
        {{ metaLine }}
      </button>
      <RouterLink
        v-else
        :to="`/files/${doc.id}`"
        class="min-w-0 flex-1 truncate text-xs text-gray-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        :class="variant === 'card' ? 'hover:opacity-95' : ''"
        @click="onActivate"
      >
        {{ metaLine }}
      </RouterLink>

      <StatusBadge
        v-if="showStatus"
        class="shrink-0"
        :status="doc.status"
      />

      <div v-else-if="showMenu" class="relative shrink-0" data-doc-menu>
        <button
          type="button"
          class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-600"
          :aria-label="t('aria_document_actions')"
          :aria-expanded="menuOpen"
          @click.stop="toggleMenu"
        >
          <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 7a1.5 1.5 0 110-3 1.5 1.5 0 010 3zm0 6.5a1.5 1.5 0 110-3 1.5 1.5 0 010 3zM12 20a1.5 1.5 0 110-3 1.5 1.5 0 010 3z" />
          </svg>
        </button>
        <div
          v-if="menuOpen"
          class="absolute right-0 z-30 mt-1 min-w-[11.5rem] overflow-hidden rounded-xl border border-gray-100 bg-gray-50 py-1 shadow-lg"
          role="menu"
        >
          <button
            type="button"
            class="block w-full px-4 py-2.5 text-left text-sm text-gray-900 hover:bg-white"
            role="menuitem"
            @click.stop="pick('share')"
          >
            {{ t('menu_share_document') }}
          </button>
          <button
            type="button"
            class="block w-full px-4 py-2.5 text-left text-sm text-gray-900 hover:bg-white"
            role="menuitem"
            @click.stop="pick('move')"
          >
            {{ t('menu_move_profile') }}
          </button>
          <button
            type="button"
            class="block w-full px-4 py-2.5 text-left text-sm text-gray-900 hover:bg-white"
            role="menuitem"
            @click.stop="pick('change_category')"
          >
            {{ t('menu_change_category') }}
          </button>
          <button
            type="button"
            class="block w-full px-4 py-2.5 text-left text-sm text-gray-900 hover:bg-white"
            role="menuitem"
            @click.stop="pick('delete')"
          >
            {{ t('menu_delete') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
