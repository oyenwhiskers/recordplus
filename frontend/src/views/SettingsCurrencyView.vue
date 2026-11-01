<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { currencyOptions } from '@/lib/currencyOptions'
import AppPage from '@/components/AppPage.vue'

defineOptions({ name: 'SettingsCurrencyView' })

const auth = useAuthStore()
const { t } = useI18n()
const saving = ref(false)

const current = computed(() => {
  const code = (auth.activeProfile?.base_currency || 'MYR').toUpperCase()
  return currencyOptions.some((c) => c.code === code) ? code : 'MYR'
})

function countryLabel(code: string): string {
  const key = `country_${code.slice(0, 2)}` as
    | 'country_MY'
    | 'country_SG'
    | 'country_US'
    | 'country_EU'
    | 'country_GB'
    | 'country_JP'
    | 'country_AU'
    | 'country_CN'
    | 'country_TH'
    | 'country_ID'
  const map: Record<string, typeof key> = {
    MYR: 'country_MY',
    SGD: 'country_SG',
    USD: 'country_US',
    EUR: 'country_EU',
    GBP: 'country_GB',
    JPY: 'country_JP',
    AUD: 'country_AU',
    CNY: 'country_CN',
    THB: 'country_TH',
    IDR: 'country_ID',
  }
  return t(map[code] ?? 'country_MY')
}

async function selectCurrency(code: string) {
  if (saving.value || !auth.activeProfile || code === current.value) return
  saving.value = true
  try {
    await auth.updateProfileBaseCurrency(auth.activeProfile, code)
  } finally {
    saving.value = false
  }
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
      <h1 class="text-base font-bold text-primary">{{ t('label_base_currency') }}</h1>
    </div>

    <div class="relative space-y-4">
      <section class="rounded-[14px] border border-gray-100 bg-white px-3.5 py-3">
        <div class="flex items-center gap-2.5">
          <span
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-soft-blue text-primary"
            aria-hidden="true"
          >
            <svg class="h-[18px] w-[18px]" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M19 14V6c0-1.1-.9-2-2-2H3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zm-9-1c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm13-6v11c0 1.1-.9 2-2 2H4v-2h17V7h2z"
              />
            </svg>
          </span>
          <div class="min-w-0">
            <h2 class="text-sm font-bold text-gray-900">{{ t('label_base_currency') }}</h2>
            <p class="mt-1 text-[12.5px] leading-snug text-gray-500">
              {{ t('hint_base_currency') }}
            </p>
          </div>
        </div>
      </section>

      <div class="space-y-2.5">
        <button
          v-for="option in currencyOptions"
          :key="option.code"
          type="button"
          class="flex w-full items-center justify-between rounded-[14px] border bg-white px-3.5 py-3.5 text-left text-[14.5px] font-semibold disabled:opacity-60"
          :class="
            option.code === current
              ? 'border-primary border-[1.6px] text-gray-900'
              : 'border-gray-200 text-gray-800 hover:bg-soft-blue/40'
          "
          :disabled="saving"
          @click="selectCurrency(option.code)"
        >
          <span>{{ t('currency_option_label', { code: option.code, country: countryLabel(option.code) }) }}</span>
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

      <div
        v-if="saving"
        class="absolute inset-0 flex items-center justify-center rounded-2xl bg-black/5"
        aria-live="polite"
      >
        <span class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-primary shadow-sm">
          {{ t('action_updating') }}
        </span>
      </div>
    </div>
  </AppPage>
</template>
