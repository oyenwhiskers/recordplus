import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api, { ensureCsrf } from '@/api/client'
import type { Profile, User } from '@/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)
  const bootstrapped = ref(false)

  const activeProfile = computed(() => user.value?.active_profile ?? null)
  const profiles = computed(() => user.value?.profiles ?? [])

  async function fetchMe() {
    loading.value = true
    try {
      const { data } = await api.get('/me')
      user.value = data.user
    } catch {
      user.value = null
    } finally {
      loading.value = false
      bootstrapped.value = true
    }
  }

  async function register(payload: { name: string; email: string; password: string; password_confirmation: string }) {
    await ensureCsrf()
    const { data } = await api.post('/register', payload)
    user.value = data.user
  }

  async function login(payload: { email: string; password: string }) {
    await ensureCsrf()
    const { data } = await api.post('/login', payload)
    user.value = data.user
  }

  async function logout() {
    await ensureCsrf()
    await api.post('/logout')
    user.value = null
  }

  async function updateAccount(payload: {
    name: string
    email: string
    current_password?: string
    password?: string
    password_confirmation?: string
  }) {
    await ensureCsrf()
    const { data } = await api.patch('/me', payload)
    user.value = data.user
    return data.user as User
  }

  async function activateProfile(profile: Profile) {
    await ensureCsrf()
    const { data } = await api.post(`/profiles/${profile.id}/activate`)
    user.value = data.user
  }

  async function createProfile(name: string, business = false) {
    await ensureCsrf()
    const { data } = await api.post('/profiles', { name, business })
    await fetchMe()
    return data.profile as Profile
  }

  async function renameProfile(profile: Profile, name: string) {
    await ensureCsrf()
    await api.patch(`/profiles/${profile.id}`, { name })
    await fetchMe()
  }

  async function updateProfileBaseCurrency(profile: Profile, baseCurrency: string) {
    await ensureCsrf()
    const { data } = await api.patch(`/profiles/${profile.id}`, {
      base_currency: baseCurrency,
    })
    await fetchMe()
    return data.profile as Profile
  }

  async function deleteProfile(profile: Profile) {
    await ensureCsrf()
    const { data } = await api.delete(`/profiles/${profile.id}`)
    user.value = data.user
  }

  return {
    user,
    loading,
    bootstrapped,
    activeProfile,
    profiles,
    fetchMe,
    register,
    login,
    logout,
    updateAccount,
    activateProfile,
    createProfile,
    renameProfile,
    updateProfileBaseCurrency,
    deleteProfile,
  }
})
