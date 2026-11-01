<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

function onGoogle() {
  window.location.assign('/auth/google')
}
</script>

<template>
  <div>
    <div class="relative my-4">
      <div class="absolute inset-0 flex items-center" aria-hidden="true">
        <div class="w-full border-t border-gray-200" />
      </div>
      <div class="relative flex justify-center text-xs">
        <span class="bg-background px-3 font-medium text-gray-400">{{ t('login_or_continue_with') }}</span>
      </div>
    </div>

    <button
      type="button"
      class="flex w-full items-center justify-center gap-2.5 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-800 shadow-sm transition hover:border-primary/40 hover:bg-soft-blue"
      @click="onGoogle"
    >
      <svg class="h-5 w-5 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
        <path fill="#EA4335" d="M12 10.2v3.6h5.1c-.2 1.2-.9 2.3-1.9 3l3.1 2.4c1.8-1.7 2.9-4.1 2.9-7 0-.7-.1-1.3-.2-1.9H12z" />
        <path fill="#34A853" d="M6.6 14.3l-.7.5-2.4 1.9C5.1 19.2 8.3 21 12 21c2.4 0 4.4-.8 5.9-2.2l-3.1-2.4c-.8.6-1.9.9-2.8.9-2.2 0-4-1.5-4.7-3.5z" />
        <path fill="#4A90E2" d="M3.5 7.3C2.9 8.5 2.5 9.9 2.5 11.4c0 1.5.4 2.9 1 4.1l3.1-2.4c-.2-.6-.3-1.2-.3-1.7 0-.6.1-1.1.3-1.6L3.5 7.3z" />
        <path fill="#FBBC05" d="M12 5.7c1.3 0 2.5.5 3.4 1.3l2.6-2.6C16.4 2.9 14.4 2 12 2 8.3 2 5.1 3.8 3.5 7.3l3.1 2.4C7.9 7.2 9.8 5.7 12 5.7z" />
      </svg>
      {{ t('login_continue_google') }}
    </button>
  </div>
</template>
