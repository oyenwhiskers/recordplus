<script setup lang="ts">
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import AppPage from '@/components/AppPage.vue'
import PwaInstallOffer from '@/components/PwaInstallOffer.vue'

defineOptions({ name: 'SettingsView' })

const auth = useAuthStore()
const router = useRouter()
const { t } = useI18n()

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <AppPage width="form">
    <section class="rounded-[20px] border border-gray-100 bg-white p-5">
      <div class="flex items-center justify-center gap-2">
        <span class="flex h-[54px] w-[54px] shrink-0 items-center justify-center overflow-hidden rounded-[14px] bg-soft-blue">
          <img
            src="/recordplus_logo_app.png"
            alt=""
            class="h-full w-full rounded-xl object-cover"
          />
        </span>
        <h1 class="text-[28px] font-extrabold leading-none tracking-[-0.8px] text-[#0B1F45]">
          Record<span class="text-[#2D6BFF]">Plus</span>
        </h1>
      </div>
      <p class="mt-2 text-center text-sm leading-snug text-gray-600">
        {{ t('app_tagline') }}
      </p>
    </section>

    <div class="mt-3.5 space-y-2.5">
      <RouterLink
        :to="{ name: 'settings-language' }"
        class="flex items-center gap-3.5 rounded-[14px] border border-gray-100 bg-white px-3.5 py-3 hover:bg-soft-blue/40"
      >
        <span
          class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[10px] bg-soft-blue text-primary"
          aria-hidden="true"
        >
          <svg class="h-[18px] w-[18px]" fill="currentColor" viewBox="0 0 24 24">
            <path
              d="M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zm6.93 6h-2.95a15.65 15.65 0 00-1.38-3.56A8.03 8.03 0 0118.92 8zM12 4.04c.83 1.2 1.48 2.53 1.91 3.96h-3.82c.43-1.43 1.08-2.76 1.91-3.96zM4.26 14C4.1 13.36 4 12.69 4 12s.1-1.36.26-2h3.38c-.08.66-.14 1.32-.14 2s.06 1.34.14 2H4.26zm.82 2h2.95c.32 1.25.78 2.45 1.38 3.56A7.987 7.987 0 015.08 16zm2.95-8H5.08a7.987 7.987 0 014.33-3.56A15.65 15.65 0 008.03 8zM12 19.96c-.83-1.2-1.48-2.53-1.91-3.96h3.82c-.43 1.43-1.08 2.76-1.91 3.96zM14.34 14H9.66c-.09-.66-.16-1.32-.16-2s.07-1.35.16-2h4.68c.09.65.16 1.32.16 2s-.07 1.34-.16 2zm.25 5.56c.6-1.11 1.06-2.31 1.38-3.56h2.95a8.03 8.03 0 01-4.33 3.56zM16.36 14c.08-.66.14-1.32.14-2s-.06-1.34-.14-2h3.38c.16.64.26 1.31.26 2s-.1 1.36-.26 2h-3.38z"
            />
          </svg>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-sm font-bold text-gray-900">{{ t('settings_language') }}</span>
          <span class="mt-1 block text-[12.5px] leading-snug text-gray-500">
            {{ t('settings_language_desc') }}
          </span>
        </span>
        <svg class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </RouterLink>

      <RouterLink
        :to="{ name: 'settings-currency' }"
        class="flex items-center gap-3.5 rounded-[14px] border border-gray-100 bg-white px-3.5 py-3 hover:bg-soft-blue/40"
      >
        <span
          class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[10px] bg-soft-blue text-primary"
          aria-hidden="true"
        >
          <svg class="h-[18px] w-[18px]" fill="currentColor" viewBox="0 0 24 24">
            <path
              d="M19 14V6c0-1.1-.9-2-2-2H3c-1.1 0-2 .9-2 2v8c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zm-9-1c-1.66 0-3-1.34-3-3s1.34-3 3-3 3 1.34 3 3-1.34 3-3 3zm13-6v11c0 1.1-.9 2-2 2H4v-2h17V7h2z"
            />
          </svg>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-sm font-bold text-gray-900">{{ t('settings_base_currency') }}</span>
          <span class="mt-1 block text-[12.5px] leading-snug text-gray-500">
            {{ t('settings_base_currency_desc') }}
          </span>
        </span>
        <svg class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </RouterLink>

      <RouterLink
        :to="{ name: 'settings-account' }"
        class="flex items-center gap-3.5 rounded-[14px] border border-gray-100 bg-white px-3.5 py-3 hover:bg-soft-blue/40"
      >
        <span
          class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[10px] bg-soft-blue text-primary"
          aria-hidden="true"
        >
          <svg class="h-[18px] w-[18px]" fill="currentColor" viewBox="0 0 24 24">
            <path
              d="M12 5.9c1.16 0 2.1.94 2.1 2.1s-.94 2.1-2.1 2.1S9.9 9.16 9.9 8s.94-2.1 2.1-2.1m0 9c2.97 0 6.1 1.46 6.1 2.1v1.1H5.9V17c0-.64 3.13-2.1 6.1-2.1M12 4C9.79 4 8 5.79 8 8s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0 9c-2.67 0-8 1.34-8 4v3h16v-3c0-2.66-5.33-4-8-4z"
            />
          </svg>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-sm font-bold text-gray-900">{{ t('settings_account') }}</span>
          <span class="mt-1 block text-[12.5px] leading-snug text-gray-500">
            {{ t('settings_account_tile_desc') }}
          </span>
        </span>
        <svg class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </RouterLink>

      <PwaInstallOffer variant="settings" />

      <button
        type="button"
        class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-700 hover:bg-gray-50"
        @click="logout"
      >
        {{ t('action_sign_out') }}
      </button>

      <div class="flex justify-center pt-2">
        <span class="rounded-xl bg-soft-blue px-3.5 py-2 text-xs font-semibold text-primary">
          {{ t('settings_web_badge') }}
        </span>
      </div>
    </div>
  </AppPage>
</template>
