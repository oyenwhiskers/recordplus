import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401) {
      const { useAuthStore } = await import('@/stores/auth')
      const auth = useAuthStore()
      if (auth.user) {
        auth.user = null
        const { default: router } = await import('@/router')
        if (router.currentRoute.value.matched.some((record) => record.meta.auth)) {
          await router.replace({ name: 'login' })
        }
      }
    }
    return Promise.reject(error)
  },
)

export async function ensureCsrf(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export default api
