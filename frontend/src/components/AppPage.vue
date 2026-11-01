<script setup lang="ts">
withDefaults(
  defineProps<{
    /** dashboard | capture | fluid | form */
    width?: 'dashboard' | 'capture' | 'fluid' | 'form'
    /** Fill the layout main area (no page scroll; child manages overflow). */
    fill?: boolean
  }>(),
  { width: 'dashboard', fill: false },
)

const widthClass: Record<string, string> = {
  dashboard: 'mx-auto w-full max-w-[1800px]',
  capture: 'mx-auto w-full max-w-[56rem]',
  fluid: 'mx-auto w-full max-w-[1800px]',
  form: 'mx-auto w-full max-w-3xl',
}
</script>

<template>
  <div
    :class="[
      widthClass[width],
      'px-4 py-6 sm:px-6 lg:px-8',
      fill ? 'box-border flex h-full min-h-0 min-w-0 flex-col overflow-x-hidden overflow-y-hidden' : '',
    ]"
  >
    <slot />
  </div>
</template>
