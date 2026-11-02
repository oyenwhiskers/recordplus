<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const props = defineProps<{
  show: boolean
  label?: string
}>()

const { t } = useI18n()

const displayLabel = computed(() => props.label ?? t('action_loading'))
</script>

<template>
  <Teleport to="body">
    <div
      v-if="show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
      role="status"
      aria-live="polite"
      aria-busy="true"
      :aria-label="displayLabel"
    >
      <div class="relative z-10 flex flex-col items-center gap-3 rounded-2xl bg-white px-8 py-6 shadow-xl">
        <span
          class="h-8 w-8 animate-spin rounded-full border-2 border-primary/25 border-t-primary"
          aria-hidden="true"
        />
        <p class="text-sm text-gray-600">{{ displayLabel }}</p>
      </div>
    </div>
  </Teleport>
</template>
