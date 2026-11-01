<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DocumentStatus } from '@/types'

const props = defineProps<{ status: DocumentStatus }>()
const { t } = useI18n()

const label = computed(() => {
  const keys: Record<DocumentStatus, string> = {
    processing: 'status_processing',
    filed: 'status_filed',
    needs_review: 'status_needs_review',
    uncategorised: 'status_uncategorised',
    processing_failed: 'status_processing_failed',
  }
  return t(keys[props.status])
})

const classes: Record<DocumentStatus, string> = {
  processing: 'bg-soft-blue text-primary',
  filed: 'bg-emerald-50 text-emerald-700',
  needs_review: 'bg-amber-50 text-amber-700',
  uncategorised: 'bg-gray-100 text-gray-600',
  processing_failed: 'bg-red-50 text-red-700',
}
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
    :class="classes[status]"
  >
    <span
      v-if="status === 'processing'"
      class="h-3 w-3 shrink-0 animate-spin rounded-full border-2 border-current/25 border-t-current"
      aria-hidden="true"
    />
    {{ label }}
  </span>
</template>
