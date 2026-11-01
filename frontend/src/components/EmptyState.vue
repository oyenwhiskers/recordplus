<script setup lang="ts">
withDefaults(
  defineProps<{
    title: string
    description?: string
    /** document | activity | chart | users | ai */
    icon?: 'document' | 'activity' | 'chart' | 'users' | 'ai'
  }>(),
  { icon: 'document' },
)
</script>

<template>
  <div class="flex h-full min-h-0 w-full flex-1 flex-col items-center justify-center px-6 py-10 text-center">
    <div
      class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-soft-blue text-primary ring-1 ring-primary/10"
      aria-hidden="true"
    >
      <!-- Open folder — empty category / no documents -->
      <svg
        v-if="icon === 'document'"
        class="h-8 w-8"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M3.75 9.776c.112-.017.227-.026.344-.026h15.812c.117 0 .232.009.344.026m-16.5 0a2.25 2.25 0 00-1.883 2.542l.857 6a2.25 2.25 0 002.227 1.932H19.05a2.25 2.25 0 002.227-1.932l.857-6a2.25 2.25 0 00-1.883-2.542m-16.5 0V6A2.25 2.25 0 016 3.75h3.879a1.5 1.5 0 011.06.44l2.122 2.12a1.5 1.5 0 001.06.44H18A2.25 2.25 0 0120.25 9v.776"
        />
      </svg>
      <svg
        v-else-if="icon === 'activity'"
        class="h-8 w-8"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M12 6v6l4 2m6-2a10 10 0 11-20 0 10 10 0 0120 0z"
        />
      </svg>
      <svg
        v-else-if="icon === 'chart'"
        class="h-8 w-8"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M3 13h2v7H3v-7zm5-5h2v12H8V8zm5 3h2v9h-2v-9zm5-6h2v15h-2V5z"
        />
      </svg>
      <svg
        v-else-if="icon === 'users'"
        class="h-8 w-8"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M15 19.128A9.38 9.38 0 0012 18.75c-1.232 0-2.41.208-3.499.59M15 19.128v-.003c0-1.113.985-2.125 2.244-2.125h1.512c1.26 0 2.245 1.012 2.245 2.125v.003M15 19.128A9.38 9.38 0 0112 19.5c-2.354 0-4.5-.86-6.128-2.275M12 12.75a3.375 3.375 0 100-6.75 3.375 3.375 0 000 6.75zM7.5 10.5a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"
        />
      </svg>
      <svg
        v-else
        class="h-8 w-8"
        fill="none"
        viewBox="0 0 24 24"
        stroke="currentColor"
      >
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="1.5"
          d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456z"
        />
      </svg>
    </div>
    <p class="text-base font-semibold text-gray-900">{{ title }}</p>
    <p v-if="description" class="mt-1.5 max-w-xs text-sm leading-relaxed text-gray-500">
      {{ description }}
    </p>
    <div v-if="$slots.default" class="mt-4">
      <slot />
    </div>
  </div>
</template>
