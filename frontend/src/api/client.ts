import axios from 'axios'

const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

export async function ensureCsrf(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

export default api
