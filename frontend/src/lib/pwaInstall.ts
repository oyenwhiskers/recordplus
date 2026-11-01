import { computed, ref, type Ref } from 'vue'

const BANNER_DISMISSED_KEY = 'rp.pwaInstallBannerDismissed'

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

const deferredPrompt: Ref<BeforeInstallPromptEvent | null> = ref(null)
const installedFlag = ref(false)
const bannerDismissed = ref(readBannerDismissed())
let listenersBound = false

export function isStandalone(): boolean {
  if (typeof window === 'undefined') {
    return false
  }
  const media = window.matchMedia('(display-mode: standalone)').matches
  const iosStandalone = 'standalone' in navigator && Boolean((navigator as Navigator & { standalone?: boolean }).standalone)
  return media || iosStandalone
}

export function isIosSafari(): boolean {
  if (typeof navigator === 'undefined') {
    return false
  }
  const ua = navigator.userAgent
  const isIos = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
  const isWebkit = /WebKit/.test(ua)
  const isOtherBrowser = /CriOS|FxiOS|EdgiOS|OPiOS|Chrome|Android/.test(ua)
  return isIos && isWebkit && !isOtherBrowser
}

/** Pure eligibility for UI — used by views and DEV self-check. */
export function shouldOfferInstall(opts: {
  standalone: boolean
  canPrompt: boolean
  iosSafari: boolean
}): boolean {
  if (opts.standalone) {
    return false
  }
  return opts.canPrompt || opts.iosSafari
}

export function shouldShowHomeBanner(opts: {
  offer: boolean
  dismissed: boolean
}): boolean {
  return opts.offer && !opts.dismissed
}

const isInstalled = computed(() => isStandalone() || installedFlag.value)
const canPromptInstall = computed(() => deferredPrompt.value !== null)
const showIosTip = computed(() => !isInstalled.value && !canPromptInstall.value && isIosSafari())
const shouldOffer = computed(() =>
  shouldOfferInstall({
    standalone: isInstalled.value,
    canPrompt: canPromptInstall.value,
    iosSafari: isIosSafari(),
  }),
)
const showHomeBanner = computed(() =>
  shouldShowHomeBanner({
    offer: shouldOffer.value,
    dismissed: bannerDismissed.value,
  }),
)

function readBannerDismissed(): boolean {
  try {
    return localStorage.getItem(BANNER_DISMISSED_KEY) === '1'
  } catch {
    return false
  }
}

function onBeforeInstallPrompt(event: Event): void {
  event.preventDefault()
  deferredPrompt.value = event as BeforeInstallPromptEvent
}

function onAppInstalled(): void {
  deferredPrompt.value = null
  installedFlag.value = true
}

function markStandaloneDocument(): void {
  if (typeof document === 'undefined') {
    return
  }
  document.documentElement.classList.add('rp-pwa')
}

export function setupPwaInstallListeners(): void {
  if (typeof window === 'undefined' || listenersBound) {
    return
  }
  listenersBound = true

  if (isStandalone()) {
    installedFlag.value = true
    markStandaloneDocument()
    return
  }

  window.addEventListener('beforeinstallprompt', onBeforeInstallPrompt)
  window.addEventListener('appinstalled', onAppInstalled)
}

export async function promptInstall(): Promise<boolean> {
  const promptEvent = deferredPrompt.value
  if (!promptEvent) {
    return false
  }

  await promptEvent.prompt()
  const { outcome } = await promptEvent.userChoice
  deferredPrompt.value = null
  if (outcome === 'accepted') {
    installedFlag.value = true
  }
  return outcome === 'accepted'
}

export function dismissHomeBanner(): void {
  bannerDismissed.value = true
  try {
    localStorage.setItem(BANNER_DISMISSED_KEY, '1')
  } catch {
    // ignore quota / private mode
  }
}

export function usePwaInstall() {
  return {
    isInstalled,
    canPromptInstall,
    showIosTip,
    shouldOffer,
    showHomeBanner,
    promptInstall,
    dismissHomeBanner,
    isStandalone,
  }
}

if (import.meta.env.DEV) {
  console.assert(
    shouldOfferInstall({ standalone: true, canPrompt: true, iosSafari: true }) === false,
    'pwaInstall: standalone must hide offer',
  )
  console.assert(
    shouldOfferInstall({ standalone: false, canPrompt: true, iosSafari: false }) === true,
    'pwaInstall: Chromium prompt must show offer',
  )
  console.assert(
    shouldOfferInstall({ standalone: false, canPrompt: false, iosSafari: true }) === true,
    'pwaInstall: iOS tip must show offer',
  )
  console.assert(
    shouldOfferInstall({ standalone: false, canPrompt: false, iosSafari: false }) === false,
    'pwaInstall: unsupported browsers must hide offer',
  )
  console.assert(
    shouldShowHomeBanner({ offer: true, dismissed: true }) === false,
    'pwaInstall: dismissed banner must stay hidden',
  )
}
