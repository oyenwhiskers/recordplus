<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { notify } from '@/lib/confirm'
import AppPage from '@/components/AppPage.vue'

defineOptions({ name: 'SettingsAccountView' })

const auth = useAuthStore()
const { t } = useI18n()

const name = ref(auth.user?.name ?? '')
const email = ref(auth.user?.email ?? '')
const currentPassword = ref('')
const password = ref('')
const passwordConfirmation = ref('')
const error = ref('')
const saving = ref(false)

watch(
  () => auth.user,
  (user) => {
    if (!user || saving.value) return
    name.value = user.name
    email.value = user.email
  },
)

async function save() {
  error.value = ''
  saving.value = true
  try {
    const payload: {
      name: string
      email: string
      current_password?: string
      password?: string
      password_confirmation?: string
    } = {
      name: name.value.trim(),
      email: email.value.trim(),
    }

    if (password.value || passwordConfirmation.value || currentPassword.value) {
      payload.current_password = currentPassword.value
      payload.password = password.value
      payload.password_confirmation = passwordConfirmation.value
    }

    await auth.updateAccount(payload)
    currentPassword.value = ''
    password.value = ''
    passwordConfirmation.value = ''
    notify({ title: t('msg_account_updated') })
  } catch (e: any) {
    const errors = e?.response?.data?.errors
    error.value = errors
      ? Object.values(errors).flat().join(' ')
      : e?.response?.data?.message || t('msg_could_not_update_account')
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
      <h1 class="text-base font-bold text-primary">{{ t('settings_account') }}</h1>
    </div>

    <form class="space-y-4" @submit.prevent="save">
      <section class="rounded-[14px] border border-gray-100 bg-white px-3.5 py-3">
        <div class="flex items-center gap-2.5">
          <span
            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-[10px] bg-soft-blue text-primary"
            aria-hidden="true"
          >
            <svg class="h-[18px] w-[18px]" fill="currentColor" viewBox="0 0 24 24">
              <path
                d="M12 5.9c1.16 0 2.1.94 2.1 2.1s-.94 2.1-2.1 2.1S9.9 9.16 9.9 8s.94-2.1 2.1-2.1m0 9c2.97 0 6.1 1.46 6.1 2.1v1.1H5.9V17c0-.64 3.13-2.1 6.1-2.1M12 4C9.79 4 8 5.79 8 8s1.79 4 4 4 4-1.79 4-4-1.79-4-4-4zm0 9c-2.67 0-8 1.34-8 4v3h16v-3c0-2.66-5.33-4-8-4z"
              />
            </svg>
          </span>
          <div class="min-w-0">
            <h2 class="text-sm font-bold text-gray-900">{{ t('settings_account_details') }}</h2>
            <p class="mt-1 text-[12.5px] leading-snug text-gray-500">
              {{ t('settings_account_edit_desc') }}
            </p>
          </div>
        </div>
      </section>

      <section class="space-y-3 rounded-[14px] border border-gray-100 bg-white p-3.5">
        <div>
          <label class="mb-1 block text-xs font-medium text-gray-500" for="account-name">{{ t('label_name') }}</label>
          <input
            id="account-name"
            v-model="name"
            type="text"
            required
            autocomplete="name"
            class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm outline-none focus:border-primary"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-gray-500" for="account-email">{{ t('label_email') }}</label>
          <input
            id="account-email"
            v-model="email"
            type="email"
            required
            autocomplete="email"
            class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm outline-none focus:border-primary"
          />
        </div>
      </section>

      <section class="space-y-3 rounded-[14px] border border-gray-100 bg-white p-3.5">
        <div>
          <h2 class="text-sm font-bold text-gray-900">{{ t('settings_password_section') }}</h2>
          <p class="mt-1 text-[12.5px] leading-snug text-gray-500">
            {{ t('settings_password_hint') }}
          </p>
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-gray-500" for="account-current-password">
            {{ t('label_current_password') }}
          </label>
          <input
            id="account-current-password"
            v-model="currentPassword"
            type="password"
            autocomplete="current-password"
            class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm outline-none focus:border-primary"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-gray-500" for="account-password">{{ t('label_new_password') }}</label>
          <input
            id="account-password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm outline-none focus:border-primary"
          />
        </div>
        <div>
          <label class="mb-1 block text-xs font-medium text-gray-500" for="account-password-confirmation">
            {{ t('label_confirm_new_password') }}
          </label>
          <input
            id="account-password-confirmation"
            v-model="passwordConfirmation"
            type="password"
            autocomplete="new-password"
            class="w-full rounded-xl border border-gray-200 px-3 py-2.5 text-sm outline-none focus:border-primary"
          />
        </div>
      </section>

      <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

      <button
        type="submit"
        class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-primary px-4 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-60"
        :disabled="saving"
      >
        {{ saving ? t('action_saving') : t('action_save_changes') }}
      </button>
    </form>
  </AppPage>
</template>
