<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import OfflineBanner from '@/components/OfflineBanner.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const showProfileMenu = ref(false)
const newProfileName = ref('')
const renameValue = ref('')
const creating = ref(false)
const lastFilesPath = ref('/files')

const keepAliveTabs = ['HomeView', 'FilesView', 'CaptureView', 'RecentView', 'SettingsView']

const nav = computed(() => [
  { name: 'home', icon: 'home', labelDesktop: t('nav_home'), labelMobile: t('nav_home'), to: '/home' },
  { name: 'files', icon: 'record', labelDesktop: t('nav_record'), labelMobile: t('nav_record'), to: lastFilesPath.value },
  { name: 'capture', icon: 'capture', labelDesktop: t('nav_capture'), labelMobile: t('nav_capture'), to: '/capture', emphasize: true },
  { name: 'recent', icon: 'recent', labelDesktop: t('nav_recent'), labelMobile: t('nav_recent'), to: '/recent' },
  { name: 'settings', icon: 'settings', labelDesktop: t('nav_settings'), labelMobile: t('nav_settings'), to: '/settings' },
])

const isActive = (name: string) =>
  route.name === name ||
  (name === 'files' &&
    (String(route.name).startsWith('file') || String(route.path).startsWith('/files'))) ||
  (name === 'settings' && String(route.path).startsWith('/settings')) ||
  (name === 'admin' && String(route.name).startsWith('admin'))

watch(
  () => route.fullPath,
  (path) => {
    if (!path.startsWith('/files')) {
      return
    }
    if (route.name === 'file-detail') {
      return
    }
    lastFilesPath.value = path
  },
  { immediate: true },
)

async function switchProfile(id: number) {
  const profile = auth.profiles.find((p) => p.id === id)
  if (!profile) return
  await auth.activateProfile(profile)
  showProfileMenu.value = false
}

async function createProfile() {
  if (!newProfileName.value.trim()) return
  creating.value = true
  try {
    await auth.createProfile(newProfileName.value.trim(), true)
    newProfileName.value = ''
    showProfileMenu.value = false
  } finally {
    creating.value = false
  }
}

async function renameActive() {
  if (!auth.activeProfile || !renameValue.value.trim()) return
  await auth.renameProfile(auth.activeProfile, renameValue.value.trim())
  renameValue.value = ''
}

async function logout() {
  await auth.logout()
  router.push({ name: 'landing' })
}

function onDocClick(e: MouseEvent) {
  const target = e.target as HTMLElement
  if (!target.closest('[data-profile-menu]')) {
    showProfileMenu.value = false
  }
}

onMounted(() => document.addEventListener('click', onDocClick))
onUnmounted(() => document.removeEventListener('click', onDocClick))

const profileLabel = computed(() => auth.activeProfile?.name ?? t('label_no_profile'))
</script>

<template>
  <div class="flex h-dvh flex-col overflow-hidden bg-background pt-[env(safe-area-inset-top)]">
    <OfflineBanner class="shrink-0" />
    <header class="z-30 hidden shrink-0 border-b border-gray-100 bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/90 md:block">
      <div class="mx-auto flex h-14 w-full max-w-[1800px] items-center justify-between gap-3 px-4 sm:px-6 lg:px-8">
        <RouterLink to="/home" class="flex shrink-0 items-center gap-2">
          <img src="/recordplus_logo_app.png" alt="RecordPlus" class="h-8 w-8 rounded-xl sm:h-9 sm:w-9" />
          <span class="text-base font-bold text-gray-900 sm:text-lg">
            Record<span class="text-primary">Plus</span>
          </span>
        </RouterLink>

        <nav class="hidden items-center gap-0.5 md:flex">
          <RouterLink
            v-for="item in nav"
            :key="item.name"
            :to="item.to"
            class="rounded-xl px-3.5 py-2 text-sm font-medium transition-colors"
            :class="isActive(item.name) ? 'bg-soft-blue text-primary' : 'text-gray-600 hover:bg-soft-blue hover:text-primary'"
          >
            {{ item.labelDesktop }}
          </RouterLink>
          <RouterLink
            v-if="auth.user?.is_admin"
            to="/admin"
            class="rounded-xl px-3.5 py-2 text-sm font-medium transition-colors"
            :class="isActive('admin') ? 'bg-soft-blue text-primary' : 'text-gray-600 hover:bg-soft-blue hover:text-primary'"
          >
            {{ t('nav_admin') }}
          </RouterLink>
        </nav>

        <div class="relative shrink-0" data-profile-menu>
          <button
            type="button"
            class="flex max-w-[11rem] items-center gap-2 rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-800 hover:border-primary/40 sm:max-w-[14rem]"
            @click.stop="showProfileMenu = !showProfileMenu"
          >
            <span class="truncate">{{ profileLabel }}</span>
            <span class="shrink-0 text-gray-400">▾</span>
          </button>

          <div
            v-if="showProfileMenu"
            class="absolute right-0 z-40 mt-2 w-72 rounded-2xl border border-gray-100 bg-white p-3 shadow-lg"
            @click.stop
          >
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-400">{{ t('label_profiles') }}</p>
            <button
              v-for="profile in auth.profiles"
              :key="profile.id"
              type="button"
              class="mb-1 flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm hover:bg-soft-blue"
              :class="profile.id === auth.activeProfile?.id ? 'bg-soft-blue font-semibold text-primary' : 'text-gray-700'"
              @click="switchProfile(profile.id)"
            >
              <span class="truncate">{{ profile.name }}</span>
              <span v-if="profile.id === auth.activeProfile?.id" class="shrink-0 text-xs">{{ t('label_active') }}</span>
            </button>

            <div class="mt-3 border-t border-gray-100 pt-3">
              <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('label_rename_active') }}</label>
              <div class="mb-3 flex gap-2">
                <input
                  v-model="renameValue"
                  type="text"
                  :placeholder="profileLabel"
                  class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm outline-none focus:border-primary"
                />
                <button type="button" class="rounded-xl border border-gray-200 px-3 py-2 text-sm font-semibold" @click="renameActive">
                  {{ t('action_save') }}
                </button>
              </div>
              <label class="mb-1 block text-xs font-medium text-gray-500">{{ t('label_new_profile') }}</label>
              <div class="flex gap-2">
                <input
                  v-model="newProfileName"
                  type="text"
                  :placeholder="t('hint_profile_name_example')"
                  class="w-full rounded-xl border border-gray-200 px-3 py-2 text-sm outline-none focus:border-primary"
                />
                <button
                  type="button"
                  class="rounded-xl bg-primary px-3 py-2 text-sm font-semibold text-white disabled:opacity-50"
                  :disabled="creating"
                  @click="createProfile"
                >
                  {{ t('action_add') }}
                </button>
              </div>
            </div>

            <RouterLink
              v-if="auth.user?.is_admin"
              to="/admin"
              class="mt-3 block w-full rounded-xl px-3 py-2.5 text-left text-sm font-medium text-primary hover:bg-soft-blue md:hidden"
              @click="showProfileMenu = false"
            >
              {{ t('nav_admin_usage') }}
            </RouterLink>

            <button
              type="button"
              class="mt-3 w-full rounded-xl px-3 py-2.5 text-left text-sm text-gray-500 hover:bg-gray-50"
              @click="logout"
            >
              {{ t('action_sign_out') }}
            </button>
          </div>
        </div>
      </div>
    </header>

    <main class="min-h-0 min-w-0 flex-1 overflow-x-hidden overflow-y-auto">
      <RouterView v-slot="{ Component }">
        <KeepAlive :include="keepAliveTabs">
          <component :is="Component" />
        </KeepAlive>
      </RouterView>
    </main>

    <nav
      class="z-30 shrink-0 border-t border-gray-100 bg-white pb-[max(0.375rem,env(safe-area-inset-bottom))] md:hidden"
      :aria-label="t('nav_primary')"
    >      <div class="mx-auto grid max-w-lg grid-cols-5 gap-0.5 px-1 py-1.5">
        <RouterLink
          v-for="item in nav"
          :key="item.name"
          :to="item.to"
          class="flex min-h-12 flex-col items-center justify-center rounded-xl px-1 py-1.5 text-center text-[10px] font-semibold leading-tight sm:text-xs"
          :class="[
            isActive(item.name) ? 'bg-soft-blue text-primary' : 'text-gray-500',
            item.emphasize && !isActive(item.name) ? 'text-primary' : '',
          ]"
        >
          <span
            class="mb-0.5 flex h-7 w-7 items-center justify-center rounded-full"
            :class="item.emphasize
              ? (isActive(item.name) ? 'bg-primary text-white' : 'bg-primary/10 text-primary')
              : (isActive(item.name) ? 'text-primary' : 'text-current')"
            aria-hidden="true"
          >
            <svg v-if="item.icon === 'home'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 10.5l9-7 9 7V20a1 1 0 01-1 1h-5v-6H9v6H4a1 1 0 01-1-1v-9.5z" />
            </svg>
            <svg v-else-if="item.icon === 'record'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 5.75A1.75 1.75 0 015.75 4h3.5A1.75 1.75 0 0111 5.75v3.5A1.75 1.75 0 019.25 11h-3.5A1.75 1.75 0 014 9.25v-3.5zM13 5.75A1.75 1.75 0 0114.75 4h3.5A1.75 1.75 0 0120 5.75v3.5A1.75 1.75 0 0118.25 11h-3.5A1.75 1.75 0 0113 9.25v-3.5zM4 14.75A1.75 1.75 0 015.75 13h3.5A1.75 1.75 0 0111 14.75v3.5A1.75 1.75 0 019.25 20h-3.5A1.75 1.75 0 014 18.25v-3.5zM13 14.75A1.75 1.75 0 0114.75 13h3.5A1.75 1.75 0 0120 14.75v3.5A1.75 1.75 0 0118.25 20h-3.5A1.75 1.75 0 0113 18.25v-3.5z" />
            </svg>
            <svg v-else-if="item.icon === 'capture'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <svg v-else-if="item.icon === 'recent'" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 8v4l2.5 1.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <svg v-else class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </span>
          {{ item.labelMobile }}
        </RouterLink>
      </div>
    </nav>
  </div>
</template>
