<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import AuthShell from '@/components/AuthShell.vue'
import SocialAuthButtons from '@/components/SocialAuthButtons.vue'

const auth = useAuthStore()
const router = useRouter()
const { t } = useI18n()

const name = ref('')
const email = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const showPassword = ref(false)
const error = ref('')
const loading = ref(false)

async function submit() {
  error.value = ''
  loading.value = true
  try {
    await auth.register({
      name: name.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirmation.value,
    })
    router.push({ name: 'home' })
  } catch (e: any) {
    const errors = e?.response?.data?.errors
    error.value = errors ? Object.values(errors).flat().join(' ') : t('register_failed')
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <AuthShell
    :title="t('register_heading')"
    :subtitle="t('register_heading_subtitle')"
    show-media
    media-src="/landing/auth-signup-illustration.png"
  >
    <form class="space-y-3" @submit.prevent="submit">
      <div>
        <label for="register-name" class="sr-only">{{ t('label_name') }}</label>
        <input
          id="register-name"
          v-model="name"
          required
          autocomplete="name"
          autofocus
          class="w-full rounded-xl border-2 border-transparent bg-soft-blue px-4 py-3 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary focus:bg-white"
          :placeholder="t('label_name')"
        />
      </div>
      <div>
        <label for="register-email" class="sr-only">{{ t('label_email') }}</label>
        <input
          id="register-email"
          v-model="email"
          type="email"
          required
          autocomplete="email"
          class="w-full rounded-xl border-2 border-transparent bg-soft-blue px-4 py-3 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary focus:bg-white"
          :placeholder="t('login_email_placeholder')"
        />
      </div>
      <div>
        <label for="register-password" class="sr-only">{{ t('label_password') }}</label>
        <div class="relative">
          <input
            id="register-password"
            v-model="password"
            :type="showPassword ? 'text' : 'password'"
            required
            autocomplete="new-password"
            class="w-full rounded-xl border-2 border-transparent bg-soft-blue px-4 py-3 pr-12 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary focus:bg-white"
            :placeholder="t('label_password')"
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
      <div>
        <label for="register-password-confirm" class="sr-only">{{ t('label_confirm_password') }}</label>
        <input
          id="register-password-confirm"
          v-model="passwordConfirmation"
          type="password"
          required
          autocomplete="new-password"
          class="w-full rounded-xl border-2 border-transparent bg-soft-blue px-4 py-3 text-sm text-gray-900 outline-none transition placeholder:text-gray-400 focus:border-primary focus:bg-white"
          :placeholder="t('label_confirm_password')"
        />
      </div>

      <div
        v-if="error"
        class="rounded-xl border border-red-100 bg-red-50 px-3.5 py-3 text-sm text-red-700"
        role="alert"
      >
        {{ error }}
      </div>

      <button
        type="submit"
        class="mt-1 flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3 text-sm font-semibold text-white shadow-[0_10px_24px_rgba(45,107,255,0.28)] transition hover:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-60"
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
        {{ loading ? t('action_loading') : t('action_create_account') }}
      </button>
    </form>

    <SocialAuthButtons />

    <p class="mt-2.5 text-center text-sm text-gray-500 sm:mt-4">
      {{ t('register_have_account') }}
      <RouterLink to="/login" class="font-semibold text-primary hover:underline">{{ t('action_sign_in') }}</RouterLink>
    </p>
  </AuthShell>
</template>
