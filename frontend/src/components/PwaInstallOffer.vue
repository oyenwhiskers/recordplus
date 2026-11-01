<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePwaInstall } from '@/lib/pwaInstall'

defineOptions({ name: 'PwaInstallOffer' })

const props = defineProps<{
  variant: 'banner' | 'settings'
}>()

const { t } = useI18n()
const { canPromptInstall, showIosTip, shouldOffer, showHomeBanner, promptInstall, dismissHomeBanner } =
  usePwaInstall()

const iosExpanded = ref(false)
const prompting = ref(false)

const visible = computed(() =>
  props.variant === 'banner' ? showHomeBanner.value : shouldOffer.value,
)

async function onInstall(): Promise<void> {
  if (canPromptInstall.value) {
    prompting.value = true
    try {
      await promptInstall()
    } finally {
      prompting.value = false
    }
    return
  }
  if (showIosTip.value) {
    iosExpanded.value = !iosExpanded.value
  }
}

function onDismiss(): void {
  dismissHomeBanner()
}
</script>

<template>
  <div v-if="visible" class="shrink-0">
    <!-- Home banner -->
    <div
      v-if="variant === 'banner'"
      class="rounded-2xl border border-gray-100 bg-white px-3 py-3 sm:px-4"
    >
      <div class="flex items-start gap-3">
        <span
          class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-[10px] bg-soft-blue text-primary"
          aria-hidden="true"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="1.75"
              d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"
            />
          </svg>
        </span>
        <div class="min-w-0 flex-1">
          <p class="text-sm font-bold text-gray-900">{{ t('pwa_install_title') }}</p>
          <p class="mt-0.5 text-[12.5px] leading-snug text-gray-500">{{ t('pwa_install_desc') }}</p>
          <div class="mt-2.5 flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="inline-flex min-h-9 items-center justify-center rounded-xl bg-primary px-3.5 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-60"
              :disabled="prompting"
              @click="onInstall"
            >
              {{ canPromptInstall ? t('pwa_install_action') : t('pwa_ios_tip_title') }}
            </button>
            <button
              type="button"
              class="inline-flex min-h-9 items-center justify-center rounded-xl px-3 text-sm font-semibold text-gray-600 hover:bg-gray-50"
              @click="onDismiss"
            >
              {{ t('pwa_install_dismiss') }}
            </button>
          </div>
          <p v-if="showIosTip && iosExpanded" class="mt-2 text-[12.5px] leading-snug text-gray-600">
            {{ t('pwa_ios_tip_body') }}
          </p>
        </div>
        <button
          type="button"
          class="inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-50 hover:text-gray-600"
          :aria-label="t('pwa_install_dismiss')"
          @click="onDismiss"
        >
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Settings row -->
    <div v-else class="overflow-hidden rounded-[14px] border border-gray-100 bg-white">
      <button
        type="button"
        class="flex w-full items-center gap-3.5 px-3.5 py-3 text-left hover:bg-soft-blue/40 disabled:opacity-60"
        :disabled="prompting"
        @click="onInstall"
      >
        <span
          class="flex h-[34px] w-[34px] shrink-0 items-center justify-center rounded-[10px] bg-soft-blue text-primary"
          aria-hidden="true"
        >
          <svg class="h-[18px] w-[18px]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="1.75"
              d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"
            />
          </svg>
        </span>
        <span class="min-w-0 flex-1">
          <span class="block text-sm font-bold text-gray-900">{{ t('settings_install') }}</span>
          <span class="mt-1 block text-[12.5px] leading-snug text-gray-500">
            {{ t('settings_install_desc') }}
          </span>
        </span>
        <svg class="h-5 w-5 shrink-0 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
        </svg>
      </button>
      <p
        v-if="showIosTip && iosExpanded"
        class="border-t border-gray-100 px-3.5 py-3 text-[12.5px] leading-snug text-gray-600"
      >
        {{ t('pwa_ios_tip_body') }}
      </p>
    </div>
  </div>
</template>
