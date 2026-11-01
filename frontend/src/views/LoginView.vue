<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import AuthShell from '@/components/AuthShell.vue'
import SocialAuthButtons from '@/components/SocialAuthButtons.vue'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()
const { t } = useI18n()

const email = ref('')
const password = ref('')
const showPassword = ref(false)
const error = ref(route.query.error === 'google' ? t('login_google_failed') : '')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.login({ email: email.value, password: password.value })
    router.push((route.query.redirect as string) || { name: 'home' })
  } catch (e: any) {
    error.value = e?.response?.data?.message || e?.response?.data?.errors?.email?.[0] || t('login_failed')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthShell :title="t('login_welcome_title')" :subtitle="t('login_welcome_subtitle')" show-media>
    <form class="space-y-2.5 sm:space-y-3" @submit.prevent="submit">
      <div>
        <label for="login-email" class="sr-only">{{ t('label_email') }}</label>
        <input
          id="login-email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          autofocus
          class="w-full rounded-xl border-2 border-transparent bg-soft-blue px-4 py-2.5 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary focus:bg-white sm:py-3"
          :placeholder="t('login_email_placeholder')"
        />
      </div>

      <div>
        <label for="login-password" class="sr-only">{{ t('label_password') }}</label>
        <div class="relative">
          <input
            id="login-password"
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="current-password"
            class="w-full rounded-xl border-2 border-transparent bg-soft-blue px-4 py-2.5 pr-12 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary focus:bg-white sm:py-3"
            :placeholder="t('login_password_placeholder')"
          />
          <button
            type="button"
            class="absolute inset-y-0 right-0 flex items-center px-3.5 text-gray-400 transition hover:text-gray-600"
            :aria-label="showPassword ? t('login_hide_password') : t('login_show_password')"
            @click="showPassword = !showPassword"
          >
            <svg v-if="!showPassword" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
            </svg>
            <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
            </svg>
          </button>
        </div>
      </div>

      <div
        v-if="error"
        class="rounded-xl border border-red-100 bg-red-50 px-3.5 py-2.5 text-sm text-red-700 sm:py-3"
        role="alert"
      >
        {{ error }}
      </div>

      <button
        type="submit"
        class="mt-0.5 flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-2.5 text-sm font-semibold text-white shadow-[0_10px_24px_rgba(45,107,255,0.28)] transition hover:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-60 sm:py-3"
        :disabled="loading"
      >
        <svg
          v-if="loading"
          class="h-4 w-4 animate-spin"
          fill="none"
          viewBox="0 0 24 24"
          aria-hidden="true"
        >
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z" />
        </svg>
        {{ loading ? t('action_loading') : t('action_sign_in') }}
      </button>
    </form>

    <SocialAuthButtons />

    <p class="mt-2.5 text-center text-sm text-gray-500 sm:mt-4">
      <RouterLink to="/register" class="font-semibold text-primary hover:underline">
        {{ t('login_create_account') }}
      </RouterLink>
    </p>
  </AuthShell>
</template>
