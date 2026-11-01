<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { languageOptions, setLocale, type SupportedLocale } from '@/i18n'
import AppPage from '@/components/AppPage.vue'

defineOptions({ name: 'SettingsLanguageView' })

const { t, locale } = useI18n()

const current = computed(() => locale.value as SupportedLocale)

function selectLanguage(code: SupportedLocale) {
  if (code === current.value) {
    return
  }
  setLocale(code)
}
</script>

<template>
  <AppPage width="form">
    <div class="relative mb-4 flex min-h-11 items-center justify-center">
      <RouterLink
        to="/settings"
        class="absolute left-0 inline-flex h-10 w-10 items-center justify-center rounded-xl text-primary hover:bg-soft-blue"
        :aria-label="t('aria_back_settings')"
      >
        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </RouterLink>
      <h1 class="text-base font-bold text-primary">{{ t('language_title') }}</h1>
    </div>

    <div class="space-y-2.5">
      <button
        v-for="option in languageOptions"
        :key="option.code"
        type="button"
        class="flex w-full items-center justify-between rounded-[14px] border bg-white px-3.5 py-3.5 text-left text-[14.5px] font-semibold"
        :class="
          option.code === current
            ? 'border-primary border-[1.6px] text-gray-900'
            : 'border-gray-200 text-gray-800 hover:bg-soft-blue/40'
        "
        @click="selectLanguage(option.code)"
      >
        <span>{{ option.label }}</span>
        <span
          class="flex h-5 w-5 items-center justify-center rounded-full border-2"
          :class="option.code === current ? 'border-primary' : 'border-gray-300'"
          aria-hidden="true"
        >
          <span
            v-if="option.code === current"
            class="h-2.5 w-2.5 rounded-full bg-primary"
          />
        </span>
      </button>
    </div>
  </AppPage>
</template>
