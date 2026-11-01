const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]'])
const UPDATE_CHECK_MS = 15 * 60 * 1000

function isLocalDevHost(): boolean {
  return LOCAL_HOSTS.has(window.location.hostname)
}

/** Local artisan shares 127.0.0.1:port across projects; never keep a SW on that origin. */
async function destroyLocalServiceWorker(): Promise<void> {
  if (!('serviceWorker' in navigator)) {
    return
  }

  const registrations = await navigator.serviceWorker.getRegistrations()
  const cacheKeys = 'caches' in window ? await caches.keys() : []

  if (registrations.length === 0 && cacheKeys.length === 0) {
    return
  }

  await Promise.all(registrations.map((registration) => registration.unregister()))
  await Promise.all(cacheKeys.map((key) => caches.delete(key)))
  window.location.reload()
}

function activateWaitingWorker(registration: ServiceWorkerRegistration): void {
  registration.waiting?.postMessage({ type: 'SKIP_WAITING' })
}

export async function setupPwa(): Promise<void> {
  const { isStandalone, setupPwaInstallListeners } = await import('@/lib/pwaInstall')
  if (isStandalone()) {
    document.documentElement.classList.add('rp-pwa')
  }

  if (isLocalDevHost()) {
    await destroyLocalServiceWorker()
    return
  }

  setupPwaInstallListeners()

  const { registerSW } = await import('virtual:pwa-register')
  registerSW({
    immediate: true,
    onRegisteredSW(_swUrl, registration) {
      if (!registration) {
        return
      }

      // Stuck waiting SW from a prior visit — activate so autoUpdate can reload.
      activateWaitingWorker(registration)

      const check = () => {
        void registration.update().then(() => {
          activateWaitingWorker(registration)
        })
      }

      check()
      window.setInterval(check, UPDATE_CHECK_MS)

      // Phones (esp. installed PWA) often skip update checks until resume.
      document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
          check()
        }
      })
      window.addEventListener('focus', check)
    },
  })
}
