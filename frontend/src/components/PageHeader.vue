<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  title: string
  description?: string
  backTo?: string
  backLabel?: string
}>()

const { t } = useI18n()
</script>

<template>
  <div class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
    <div class="min-w-0">
      <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
        <h1 class="text-2xl font-bold tracking-tight text-gray-900">
          {{ title }}
        </h1>
        <div v-if="$slots.meta" class="flex items-center">
          <slot name="meta" />
        </div>
      </div>
      <p v-if="description || $slots.description" class="mt-1 text-sm text-gray-500">
        <slot name="description">{{ description }}</slot>
      </p>
    </div>
    <div
      v-if="backTo || $slots.actions"
      class="flex shrink-0 flex-wrap items-center gap-2 sm:pt-0.5"
    >
      <RouterLink
        v-if="backTo"
        :to="backTo"
        class="inline-flex min-h-10 items-center rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-semibold text-primary hover:bg-gray-50"
      >
        {{ backLabel ?? `← ${t('action_back')}` }}
      </RouterLink>
      <slot name="actions" />
    </div>
  </div>
</template>
