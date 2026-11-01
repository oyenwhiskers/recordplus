import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

/** Nested browse segments share FilesView; outlet stays empty. */
const FilesPassThrough = { name: 'FilesPassThrough', render: () => null }

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      name: 'landing',
      component: () => import('@/views/LandingView.vue'),
      meta: { guest: true },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guest: true },
    },
    {
      path: '/register',
      name: 'register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { guest: true },
    },
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      meta: { auth: true },
      children: [
        { path: 'home', name: 'home', component: () => import('@/views/HomeView.vue') },
        { path: 'capture', name: 'capture', component: () => import('@/views/CaptureView.vue') },
        {
          path: 'files',
          component: () => import('@/views/FilesView.vue'),
          children: [
            { path: '', name: 'files', component: FilesPassThrough },
            {
              path: 'c/:categoryId',
              name: 'files-browse',
              component: FilesPassThrough,
            },
            {
              path: 'c/:categoryId/undated',
              name: 'files-undated',
              component: FilesPassThrough,
            },
            {
              path: 'c/:categoryId/:year(\\d+)',
              name: 'files-year',
              component: FilesPassThrough,
            },
            {
              path: 'c/:categoryId/:year(\\d+)/:month(\\d+)',
              name: 'files-month',
              component: FilesPassThrough,
            },
            {
              path: ':id(\\d+)',
              name: 'file-detail',
              component: () => import('@/views/FileDetailView.vue'),
            },
          ],
        },
        { path: 'recent', name: 'recent', component: () => import('@/views/RecentView.vue') },
        { path: 'settings', name: 'settings', component: () => import('@/views/SettingsView.vue') },
        {
          path: 'settings/language',
          name: 'settings-language',
          component: () => import('@/views/SettingsLanguageView.vue'),
        },
        {
          path: 'settings/account',
          name: 'settings-account',
          component: () => import('@/views/SettingsAccountView.vue'),
        },
        {
          path: 'settings/currency',
          name: 'settings-currency',
          component: () => import('@/views/SettingsCurrencyView.vue'),
        },
        {
          path: 'admin',
          name: 'admin',
          component: () => import('@/views/admin/AdminUsersView.vue'),
          meta: { admin: true },
        },
        {
          path: 'admin/users/:id',
          name: 'admin-user',
          component: () => import('@/views/admin/AdminUserDetailView.vue'),
          meta: { admin: true },
        },
      ],
    },
  ],
})

router.beforeEach(async (to) => {
  // Laravel owns /auth/*; if the SW ever serves the SPA shell here, kick to login.
  if (to.path.startsWith('/auth/')) {
    return { name: 'login', query: { error: 'google' } }
  }

  const auth = useAuthStore()
  if (!auth.bootstrapped) {
    await auth.fetchMe()
  }

  const needsAuth = to.matched.some((record) => record.meta.auth)
  if (needsAuth && !auth.user) {
    return {
      name: 'login',
      query: to.name === 'home' ? {} : { redirect: to.fullPath },
    }
  }

  if (to.meta.guest && auth.user) {
    return { name: 'home' }
  }

  if (to.meta.admin && !auth.user?.is_admin) {
    return { name: 'home' }
  }

  return true
})

export default router
