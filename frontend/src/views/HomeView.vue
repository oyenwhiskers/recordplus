<script setup lang="ts">
import { nextTick, onActivated, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import api from '@/api/client'
import { confirmAction, showError, showSuccess } from '@/lib/confirm'
import { useAuthStore } from '@/stores/auth'
import type { HomeData, Profile } from '@/types'
import AppPage from '@/components/AppPage.vue'
import EmptyState from '@/components/EmptyState.vue'
import LoadingModal from '@/components/LoadingModal.vue'
import PwaInstallOffer from '@/components/PwaInstallOffer.vue'

defineOptions({ name: 'HomeView' })

const { t } = useI18n()
const auth = useAuthStore()
const router = useRouter()
const data = ref<HomeData | null>(null)
const error = ref('')
const year = ref<number | null>(null)
const loading = ref(false)
let loadSeq = 0

const showProfileSettings = ref(false)
const newProfileName = ref('')
const savingProfile = ref(false)
const editingProfileId = ref<number | null>(null)
const editingProfileName = ref('')
const editingProfileError = ref('')
const savingProfileRename = ref(false)
const editNameInput = ref<HTMLInputElement | null>(null)

function setEditNameInput(el: Element | null) {
  editNameInput.value = el instanceof HTMLInputElement ? el : null
}

async function load() {
  const seq = ++loadSeq
  loading.value = true
  error.value = ''
  try {
    const res = await api.get('/dashboard', {
      params: year.value ? { year: year.value } : undefined,
    })
    if (seq !== loadSeq) {
      return
    }
    data.value = res.data
    year.value = res.data.year
  } catch (e: any) {
    if (seq !== loadSeq) {
      return
    }
    error.value = e?.response?.data?.message || t('home_load_error')
  } finally {
    if (seq === loadSeq) {
      loading.value = false
    }
  }
}

async function switchProfile(event: Event) {
  const id = Number((event.target as HTMLSelectElement).value)
  const profile = auth.profiles.find((p) => p.id === id)
  if (!profile || profile.id === auth.activeProfile?.id) {
    return
  }
  await auth.activateProfile(profile)
}

async function withProfileSettingsPaused<T>(run: () => Promise<T>): Promise<T> {
  const restore = showProfileSettings.value
  if (restore) {
    showProfileSettings.value = false
    await nextTick()
  }
  try {
    return await run()
  } finally {
    if (restore) {
      showProfileSettings.value = true
    }
  }
}

async function profileSuccess(title: string): Promise<void> {
  await withProfileSettingsPaused(() => showSuccess({ title }))
}

async function profileError(title: string, icon: 'error' | 'warning' = 'error'): Promise<void> {
  await withProfileSettingsPaused(() => showError({ title, icon }))
}

async function switchToProfile(profile: Profile) {
  if (profile.id === auth.activeProfile?.id) {
    return
  }
  await auth.activateProfile(profile)
  await profileSuccess(t('msg_switched_to', { name: profile.name }))
}

function profileNameValidationError(name: string, excludeId?: number): string | null {
  const trimmed = name.trim()
  if (!trimmed) {
    return t('msg_enter_profile_name')
  }
  if (trimmed.length > 100) {
    return t('msg_name_max_100')
  }
  const duplicate = auth.profiles.some(
    (p) => p.id !== excludeId && p.name.trim().toLowerCase() === trimmed.toLowerCase(),
  )
  if (duplicate) {
    return t('msg_profile_name_exists')
  }
  return null
}

function clearProfileRename() {
  editingProfileId.value = null
  editingProfileName.value = ''
  editingProfileError.value = ''
  savingProfileRename.value = false
}

function openProfileSettings() {
  newProfileName.value = ''
  clearProfileRename()
  showProfileSettings.value = true
}

function closeProfileSettings() {
  showProfileSettings.value = false
  newProfileName.value = ''
  clearProfileRename()
}

async function startProfileRename(profile: Profile) {
  if (savingProfileRename.value) {
    return
  }
  editingProfileId.value = profile.id
  editingProfileName.value = profile.name
  editingProfileError.value = ''
  await nextTick()
  editNameInput.value?.focus()
  editNameInput.value?.select()
}

function cancelProfileRename() {
  if (savingProfileRename.value) {
    return
  }
  clearProfileRename()
}

async function createProfile() {
  const name = newProfileName.value.trim()
  if (!name || savingProfile.value) {
    return
  }

  const validationError = profileNameValidationError(name)
  if (validationError) {
    await profileError(validationError)
    return
  }

  savingProfile.value = true
  try {
    await auth.createProfile(name, true)
    newProfileName.value = ''
    await profileSuccess(t('msg_profile_created', { name }))
  } catch (e: any) {
    await profileError(
      e?.response?.data?.message || e?.response?.data?.errors?.name?.[0] || t('msg_could_not_create_profile'),
    )
  } finally {
    savingProfile.value = false
  }
}

async function saveProfileRename() {
  const id = editingProfileId.value
  if (id == null || savingProfileRename.value) {
    return
  }

  const profile = auth.profiles.find((p) => p.id === id)
  if (!profile) {
    clearProfileRename()
    return
  }

  const name = editingProfileName.value.trim()
  const validationError = profileNameValidationError(editingProfileName.value, id)
  if (validationError) {
    editingProfileError.value = validationError
    return
  }

  if (name === profile.name) {
    clearProfileRename()
    return
  }

  savingProfileRename.value = true
  editingProfileError.value = ''
  try {
    await auth.renameProfile(profile, name)
    clearProfileRename()
    await profileSuccess(t('msg_profile_renamed'))
  } catch (e: any) {
    editingProfileError.value =
      e?.response?.data?.message || e?.response?.data?.errors?.name?.[0] || t('msg_could_not_rename_profile')
  } finally {
    savingProfileRename.value = false
  }
}

async function deleteProfile(profile: Profile) {
  if (profile.is_default) {
    await profileError(t('msg_cannot_delete_default_profile'), 'warning')
    return
  }

  const categoryCount = profile.categories?.length ?? 0

  await withProfileSettingsPaused(async () => {
    const confirmed = await confirmAction({
      title: t('title_delete_profile_named', { name: profile.name }),
      text:
        categoryCount > 0
          ? t(categoryCount === 1 ? 'msg_delete_profile_with_category' : 'msg_delete_profile_with_categories', { count: categoryCount })
          : t('msg_delete_profile_no_categories'),
      confirmText: t('action_delete_profile'),
    })

    if (!confirmed) {
      return
    }

    try {
      await auth.deleteProfile(profile)
      await showSuccess({ title: t('msg_profile_deleted') })
    } catch (e: any) {
      await showError({
        title: e?.response?.data?.message || t('msg_could_not_delete_profile'),
      })
    }
  })
}

function openCategory(id: number) {
  const y = year.value
  const count = data.value?.categories.find((c) => c.id === id)?.documents_count ?? 0
  // Home counts are year-scoped; if this category is empty in the selected year,
  // open unscoped browse so Record can land on a year that actually has docs.
  if (y != null && count > 0) {
    router.push({ name: 'files-year', params: { categoryId: String(id), year: String(y) } })
    return
  }
  router.push({ name: 'files-browse', params: { categoryId: String(id) } })
}

// KeepAlive: refetch whenever Home is shown (after Capture confirm, etc.)
onActivated(load)

watch(
  () => auth.activeProfile?.id,
  () => {
    year.value = null
    load()
  },
)

// keep-alive Home keeps old totals after Settings changes base currency
watch(
  () => auth.activeProfile?.base_currency,
  (currency, previous) => {
    if (previous === undefined || currency === previous) {
      return
    }
    load()
  },
)
</script>

<template>
  <AppPage width="fluid" fill>
    <div class="mx-auto flex min-h-0 w-full max-w-5xl flex-1 flex-col gap-4 overflow-y-auto pb-4">
      <PwaInstallOffer variant="banner" />

      <div class="shrink-0 rounded-2xl border border-gray-100 bg-white px-3 py-2.5 sm:px-4">
        <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('label_active_profile') }}</label>
        <div class="flex items-center gap-2">
          <select
            :value="auth.activeProfile?.id ?? ''"
            class="rp-select min-w-0 flex-1 cursor-pointer rounded-xl border border-gray-200 bg-white py-2.5 pl-3 pr-10 text-sm font-semibold text-gray-900 outline-none focus:border-primary"
            @change="switchProfile"
          >
            <option v-if="!auth.profiles.length" value="" disabled>{{ t('msg_no_profiles') }}</option>
            <option v-for="profile in auth.profiles" :key="profile.id" :value="profile.id">
              {{ profile.name }}
            </option>
          </select>
          <button
            type="button"
            class="inline-flex h-[2.625rem] w-[2.625rem] shrink-0 items-center justify-center rounded-xl border border-gray-200 text-gray-500 hover:bg-gray-50 hover:text-primary"
            :title="t('tooltip_profile_settings')"
            :aria-label="t('tooltip_profile_settings')"
            @click="openProfileSettings"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </button>
        </div>
      </div>

      <p v-if="error" class="shrink-0 text-sm text-red-600">{{ error }}</p>

      <LoadingModal :show="loading && !data" />

      <template v-if="data">
        <div class="shrink-0 rounded-2xl border border-gray-100 bg-white px-3 py-2.5 sm:px-4">
          <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('label_year') }}</label>
          <select
            v-model.number="year"
            class="rp-select w-full cursor-pointer rounded-xl border border-gray-200 bg-white px-10 py-2.5 text-center text-sm font-semibold text-gray-900 outline-none focus:border-primary"
            @change="load"
          >
            <option v-for="y in data.years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>

        <EmptyState
          v-if="!data.categories.length"
          class="py-10"
          icon="document"
          :title="t('home_empty_title')"
          :description="t('home_empty_desc')"
        >
          <RouterLink to="/capture" class="text-sm font-semibold text-primary hover:underline">
            {{ t('action_go_to_capture') }}
          </RouterLink>
        </EmptyState>

        <div
          v-else
          class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
        >
          <button
            v-for="cat in data.categories"
            :key="cat.id"
            type="button"
            class="flex flex-col items-start gap-2 rounded-2xl border border-gray-100 bg-white px-3.5 py-3.5 text-left transition-colors hover:border-primary/40 hover:bg-soft-blue focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            @click="openCategory(cat.id)"
          >
            <span class="line-clamp-2 text-sm font-bold text-gray-900">{{ cat.name }}</span>
            <div class="flex min-w-0 flex-col gap-0.5">
              <template v-if="cat.display_amount">
                <span class="truncate text-lg font-bold tabular-nums tracking-tight text-primary">
                  {{ cat.display_amount }}
                </span>
              </template>
              <template v-else>
                <span class="text-2xl font-bold tabular-nums tracking-tight text-primary">
                  {{ cat.documents_count }}
                </span>
              </template>
              <span class="text-xs font-medium tabular-nums text-gray-400">
                {{ t(cat.documents_count === 1 ? 'home_record_in_year' : 'home_records_in_year', { count: cat.documents_count, year: data.year }) }}
              </span>
            </div>
          </button>
        </div>
      </template>
    </div>

    <Teleport to="body">
      <div
        v-if="showProfileSettings"
        class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="t('profile_settings_title')"
      >
        <button type="button" class="absolute inset-0 cursor-default" :aria-label="t('aria_close_settings')" @click="closeProfileSettings" />
        <div class="relative z-10 flex max-h-[calc(100dvh-2rem)] min-h-80 w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
          <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-5 py-4">
            <div>
              <h2 class="text-sm font-semibold text-gray-900">{{ t('profile_settings_title') }}</h2>
              <p class="mt-0.5 text-xs text-gray-500">{{ t('profile_settings_desc') }}</p>
            </div>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"
              :aria-label="t('action_close')"
              @click="closeProfileSettings"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="shrink-0 border-b border-gray-100 px-5 py-4">
            <label class="block text-xs font-medium text-gray-500">{{ t('label_new_profile') }}</label>
            <div class="mt-2 flex gap-2">
              <input
                v-model="newProfileName"
                class="min-h-10 min-w-0 flex-1 rounded-xl border border-gray-200 px-3 text-sm outline-none focus:border-primary"
                :placeholder="t('placeholder_name')"
                maxlength="100"
                @keyup.enter="createProfile"
              />
              <button
                type="button"
                class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl bg-primary px-4 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-60"
                :disabled="savingProfile || !newProfileName.trim()"
                @click="createProfile"
              >
                {{ t('action_add') }}
              </button>
            </div>
          </div>

          <ul class="min-h-0 flex-1 divide-y divide-gray-50 overflow-y-auto px-2 py-1">
            <li
              v-for="profile in auth.profiles"
              :key="profile.id"
              class="flex items-center gap-1 px-3 py-3"
            >
              <div v-if="editingProfileId === profile.id" class="min-w-0 flex-1">
                <input
                  :ref="setEditNameInput"
                  v-model="editingProfileName"
                  class="min-h-10 w-full rounded-xl border px-3 text-sm outline-none focus:border-primary"
                  :class="editingProfileError ? 'border-red-400' : 'border-gray-200'"
                  :placeholder="t('placeholder_name')"
                  maxlength="100"
                  :disabled="savingProfileRename"
                  :aria-invalid="Boolean(editingProfileError)"
                  :aria-label="t('label_profile_name')"
                  @input="editingProfileError = ''"
                  @keyup.enter="saveProfileRename"
                  @keyup.escape="cancelProfileRename"
                />
                <p v-if="editingProfileError" class="mt-1 text-xs text-red-600">{{ editingProfileError }}</p>
              </div>
              <div v-else class="min-w-0 flex-1">
                <div class="flex min-w-0 items-center gap-1.5">
                  <p class="truncate text-sm font-medium text-gray-900">
                    {{ profile.name }}
                    <span v-if="profile.id === auth.activeProfile?.id" class="sr-only">
                      ({{ t('label_current_profile') }})
                    </span>
                  </p>
                  <svg
                    v-if="profile.id === auth.activeProfile?.id"
                    class="h-4 w-4 shrink-0 text-primary"
                    viewBox="0 0 24 24"
                    fill="currentColor"
                    aria-hidden="true"
                  >
                    <path
                      fill-rule="evenodd"
                      d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.005z"
                      clip-rule="evenodd"
                    />
                  </svg>
                </div>
                <p class="text-xs text-gray-400">
                  {{ profile.categories?.length ?? 0 }}
                  {{ t((profile.categories?.length ?? 0) === 1 ? 'label_category_singular' : 'label_category_plural') }}
                </p>
              </div>
              <template v-if="editingProfileId === profile.id">
                <button
                  type="button"
                  class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-primary hover:bg-soft-blue disabled:opacity-60"
                  :title="t('action_save')"
                  :aria-label="t('aria_save_profile_name')"
                  :disabled="savingProfileRename || !editingProfileName.trim()"
                  @click="saveProfileRename"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4.5 12.75l6 6 9-13.5" />
                  </svg>
                </button>
                <button
                  type="button"
                  class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-60"
                  :title="t('action_cancel')"
                  :aria-label="t('aria_cancel_rename')"
                  :disabled="savingProfileRename"
                  @click="cancelProfileRename"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </template>
              <template v-else>
                <button
                  type="button"
                  class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-primary hover:bg-soft-blue disabled:opacity-40"
                  :title="t('tooltip_switch_profile')"
                  :aria-label="t('tooltip_switch_profile')"
                  :disabled="profile.id === auth.activeProfile?.id"
                  @click="switchToProfile(profile)"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M7.5 21L3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5" />
                  </svg>
                </button>
                <button
                  type="button"
                  class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-primary hover:bg-soft-blue"
                  :title="t('tooltip_edit_profile')"
                  :aria-label="t('tooltip_edit_profile')"
                  @click="startProfileRename(profile)"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                  </svg>
                </button>
                <button
                  type="button"
                  class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-red-600 hover:bg-red-50 disabled:opacity-40"
                  :title="profile.is_default ? t('tooltip_default_profile_locked') : t('tooltip_delete_profile')"
                  :aria-label="profile.is_default ? t('tooltip_default_profile_locked') : t('tooltip_delete_profile')"
                  :disabled="profile.is_default"
                  @click="deleteProfile(profile)"
                >
                  <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                  </svg>
                </button>
              </template>
            </li>
            <li v-if="!auth.profiles.length" class="px-3 py-8 text-center text-sm text-gray-500">
              {{ t('msg_no_profiles_yet') }}
            </li>
          </ul>
        </div>
      </div>
    </Teleport>
  </AppPage>
</template>
