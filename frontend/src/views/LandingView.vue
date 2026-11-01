<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { usePwaInstall } from '@/lib/pwaInstall'

defineOptions({ name: 'LandingView' })

const route = useRoute()
const { t } = useI18n()
const menuOpen = ref(false)
const { isInstalled, canPromptInstall, showIosTip, promptInstall } = usePwaInstall()
const iosTipOpen = ref(false)
const installPrompting = ref(false)

const loginTo = computed(() =>
  route.query.redirect
    ? { name: 'login' as const, query: { redirect: String(route.query.redirect) } }
    : { name: 'login' as const },
)

function closeMenu(): void {
  menuOpen.value = false
}

function onEscape(event: KeyboardEvent): void {
  if (event.key === 'Escape') {
    closeMenu()
  }
}

watch(menuOpen, (open) => {
  document.body.style.overflow = open ? 'hidden' : ''
  if (open) {
    window.addEventListener('keydown', onEscape)
  } else {
    window.removeEventListener('keydown', onEscape)
  }
})

onUnmounted(() => {
  document.body.style.overflow = ''
  window.removeEventListener('keydown', onEscape)
})

async function onLandingInstall(): Promise<void> {
  if (canPromptInstall.value) {
    installPrompting.value = true
    try {
      await promptInstall()
    } finally {
      installPrompting.value = false
    }
    return
  }
  if (showIosTip.value) {
    iosTipOpen.value = !iosTipOpen.value
  }
}
</script>

<template>
  <div class="min-h-dvh bg-background font-sans text-gray-900 antialiased">
    <header class="sticky top-0 z-50 border-b border-gray-100 bg-white/90 pt-[env(safe-area-inset-top)] backdrop-blur">
      <div class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
        <RouterLink to="/" class="flex items-center gap-3" @click="menuOpen = false">
          <img src="/recordplus_logo_app.png" alt="" class="h-10 w-10 rounded-xl" width="40" height="40" aria-hidden="true" />
          <span class="text-xl font-bold text-gray-900">Record<span class="text-primary">Plus</span></span>
        </RouterLink>

        <nav class="hidden items-center gap-6 md:flex" aria-label="Main navigation">
          <a href="#features" class="text-sm font-medium text-gray-600 hover:text-primary">{{ t('landing_nav_features') }}</a>
          <a href="#how-it-works" class="text-sm font-medium text-gray-600 hover:text-primary">{{ t('landing_nav_how') }}</a>
          <RouterLink :to="loginTo" class="text-sm font-medium text-gray-600 hover:text-primary">{{ t('action_sign_in') }}</RouterLink>
          <RouterLink
            to="/register"
            class="rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-600"
          >
            {{ t('action_create_account') }}
          </RouterLink>
        </nav>

        <button
          type="button"
          class="inline-flex items-center justify-center rounded-lg border border-gray-200 p-2 text-gray-700 md:hidden"
          :aria-expanded="menuOpen"
          aria-controls="landing-mobile-menu"
          :aria-label="menuOpen ? t('landing_menu_close') : t('landing_menu_open')"
          @click="menuOpen = !menuOpen"
        >
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path
              v-if="!menuOpen"
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 6h16M4 12h16M4 18h16"
            />
            <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </header>

    <Teleport to="body">
      <div
        class="md:hidden"
        :class="menuOpen ? 'pointer-events-auto' : 'pointer-events-none'"
        :aria-hidden="!menuOpen"
      >
        <div
          class="fixed inset-0 z-[60] bg-gray-950/40 transition-opacity duration-200"
          :class="menuOpen ? 'opacity-100' : 'opacity-0'"
          @click="closeMenu"
        />
        <aside
          id="landing-mobile-menu"
          class="fixed inset-y-0 right-0 z-[70] flex w-[min(20rem,88vw)] flex-col bg-white shadow-xl transition-transform duration-200 ease-out"
          :class="menuOpen ? 'translate-x-0' : 'translate-x-full'"
          role="dialog"
          aria-modal="true"
          aria-labelledby="landing-drawer-title"
        >
          <div class="flex items-center justify-between border-b border-gray-100 px-4 py-4 pt-[max(1rem,env(safe-area-inset-top))]">
            <RouterLink to="/" class="flex items-center gap-2.5" @click="closeMenu">
              <img
                src="/recordplus_logo_app.png"
                alt=""
                class="h-9 w-9 rounded-xl"
                width="36"
                height="36"
                aria-hidden="true"
              />
              <span id="landing-drawer-title" class="text-lg font-bold text-gray-900">
                Record<span class="text-primary">Plus</span>
              </span>
            </RouterLink>
            <button
              type="button"
              class="inline-flex items-center justify-center rounded-lg border border-gray-200 p-2 text-gray-700"
              :aria-label="t('landing_menu_close')"
              @click="closeMenu"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <nav class="flex flex-1 flex-col gap-1 overflow-y-auto px-3 py-4" aria-label="Mobile navigation">
            <a
              href="#features"
              class="rounded-lg px-3 py-3 text-sm font-medium text-gray-700 hover:bg-soft-blue"
              @click="closeMenu"
            >{{ t('landing_nav_features') }}</a>
            <a
              href="#how-it-works"
              class="rounded-lg px-3 py-3 text-sm font-medium text-gray-700 hover:bg-soft-blue"
              @click="closeMenu"
            >{{ t('landing_nav_how') }}</a>
          </nav>

          <div class="border-t border-gray-100 px-4 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]">
            <div class="flex flex-col gap-2">
              <RouterLink
                :to="loginTo"
                class="inline-flex min-h-11 items-center justify-center rounded-xl border border-gray-200 bg-white px-4 text-sm font-semibold text-gray-800 hover:bg-gray-50"
                @click="closeMenu"
              >{{ t('action_sign_in') }}</RouterLink>
              <RouterLink
                to="/register"
                class="inline-flex min-h-11 items-center justify-center rounded-xl bg-primary px-4 text-sm font-semibold text-white hover:bg-blue-600"
                @click="closeMenu"
              >{{ t('action_create_account') }}</RouterLink>
            </div>
          </div>
        </aside>
      </div>
    </Teleport>

    <main>
      <section
        class="hero-section relative overflow-hidden px-4 md:py-16 lg:py-20"
        :aria-label="t('landing_hero_aria')"
      >
        <div class="hero-section-overlay" aria-hidden="true" />
        <div class="hero-content relative z-10 mx-auto w-full max-w-6xl">
          <div class="hero-copy mx-auto max-w-xl text-center md:mx-0 md:text-left">
            <h1 class="text-4xl font-bold leading-tight tracking-tight text-gray-900 md:text-5xl lg:text-[3.25rem] lg:leading-[1.1]">
              {{ t('landing_hero_title_before') }}
              <span class="text-primary underline decoration-primary decoration-2 underline-offset-4 md:no-underline">{{ t('landing_hero_title_accent') }}</span>
            </h1>
            <p class="text-base leading-relaxed text-gray-600 md:text-lg">
              {{ t('landing_hero_subtitle') }}
            </p>

            <div class="hero-feature-tags">
              <span class="hero-feature-tag">
                <svg class="h-4 w-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                {{ t('landing_tag_capture') }}
              </span>
              <span class="hero-feature-tag">
                <svg class="h-4 w-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" />
                </svg>
                {{ t('landing_tag_organize') }}
              </span>
              <span class="hero-feature-tag">
                <svg class="h-4 w-4 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1M16 8l-4-4-4 4M12 4v12" />
                </svg>
                {{ t('landing_tag_export') }}
              </span>
            </div>

            <div class="hero-copy-actions">
              <RouterLink
                to="/register"
                class="inline-flex w-full max-w-sm items-center justify-center gap-2 rounded-xl bg-primary px-6 py-4 text-sm font-semibold text-white shadow-sm hover:bg-blue-600 md:w-auto md:min-w-[12.5rem] md:py-3.5"
              >
                {{ t('action_create_account') }}
                <svg class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 8l4 4m0 0l-4 4m4-4H3" />
                </svg>
              </RouterLink>
              <RouterLink
                :to="loginTo"
                class="inline-flex w-full max-w-sm items-center justify-center rounded-xl border border-gray-200 bg-white px-6 py-4 text-sm font-semibold text-gray-800 shadow-sm hover:bg-gray-50 md:w-auto md:min-w-[12.5rem] md:py-3.5"
              >
                {{ t('action_sign_in') }}
              </RouterLink>
            </div>

            <ul class="mt-6 hidden space-y-3 text-sm text-gray-600 md:block">
              <li class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                </svg>
                {{ t('landing_bullet_secure') }}
              </li>
              <li class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
                {{ t('landing_bullet_private') }}
              </li>
              <li class="flex items-start gap-3">
                <svg class="mt-0.5 h-5 w-5 shrink-0 text-primary" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                {{ t('landing_bullet_fast') }}
              </li>
            </ul>
          </div>
        </div>
      </section>

      <section class="border-y border-gray-100 bg-white px-4 py-16 md:py-20">
        <div class="mx-auto max-w-6xl">
          <div class="grid gap-10 md:grid-cols-2 md:items-stretch">
            <div class="flex flex-col overflow-hidden rounded-2xl border border-gray-100 bg-background">
              <img
                src="/landing/the_problem_image.png"
                :alt="t('landing_problem_alt')"
                class="aspect-[4/3] w-full shrink-0 object-cover"
                width="640"
                height="480"
                loading="lazy"
              />
              <div class="flex flex-1 flex-col p-8">
                <h2 class="text-2xl font-bold text-gray-900">{{ t('landing_problem_title') }}</h2>
                <p class="mt-4 leading-relaxed text-gray-600">{{ t('landing_problem_body') }}</p>
              </div>
            </div>
            <div class="flex flex-col overflow-hidden rounded-2xl border border-primary/20 bg-soft-blue">
              <img
                src="/landing/the_solution_image.png"
                :alt="t('landing_solution_alt')"
                class="aspect-[4/3] w-full shrink-0 object-cover"
                width="640"
                height="480"
                loading="lazy"
              />
              <div class="flex flex-1 flex-col p-8">
                <h2 class="text-2xl font-bold text-gray-900">{{ t('landing_solution_title') }}</h2>
                <p class="mt-4 leading-relaxed text-gray-600">{{ t('landing_solution_body') }}</p>
              </div>
            </div>
          </div>
        </div>
      </section>

      <section id="features" class="px-4 py-16 md:py-24">
        <div class="mx-auto max-w-6xl">
          <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold text-gray-900 md:text-4xl">{{ t('landing_features_title') }}</h2>
            <p class="mt-4 text-lg text-gray-600">{{ t('landing_features_subtitle') }}</p>
          </div>
          <div class="mt-12 grid gap-6 md:grid-cols-3">
            <article class="rounded-2xl border border-gray-100 bg-white p-8 shadow-sm">
              <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-soft-blue text-primary">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </div>
              <h3 class="text-xl font-semibold text-gray-900">{{ t('landing_tag_capture') }}</h3>
              <p class="mt-3 leading-relaxed text-gray-600">{{ t('landing_feature_capture') }}</p>
            </article>
            <article class="rounded-2xl border border-gray-100 bg-white p-8 shadow-sm">
              <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-soft-blue text-primary">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
                </svg>
              </div>
              <h3 class="text-xl font-semibold text-gray-900">{{ t('landing_tag_organize') }}</h3>
              <p class="mt-3 leading-relaxed text-gray-600">{{ t('landing_feature_organize') }}</p>
            </article>
            <article class="rounded-2xl border border-gray-100 bg-white p-8 shadow-sm">
              <div class="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-soft-blue text-primary">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3M4 16v1a2 2 0 002 2h12a2 2 0 002-2v-1M16 8l-4-4-4 4" />
                </svg>
              </div>
              <h3 class="text-xl font-semibold text-gray-900">{{ t('landing_tag_export') }}</h3>
              <p class="mt-3 leading-relaxed text-gray-600">{{ t('landing_feature_export') }}</p>
            </article>
          </div>
        </div>
      </section>

      <section id="how-it-works" class="bg-white px-4 py-16 md:py-24">
        <div class="mx-auto max-w-6xl">
          <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold text-gray-900 md:text-4xl">{{ t('landing_how_title') }}</h2>
            <p class="mt-4 text-lg text-gray-600">{{ t('landing_how_subtitle') }}</p>
          </div>
          <ol class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            <li class="rounded-2xl border border-gray-100 bg-background p-6 shadow-sm">
              <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-primary text-sm font-bold text-white">1</span>
              <h3 class="mt-4 font-semibold text-gray-900">{{ t('landing_step1_title') }}</h3>
              <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ t('landing_step1_body') }}</p>
            </li>
            <li class="rounded-2xl border border-gray-100 bg-background p-6 shadow-sm">
              <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-primary text-sm font-bold text-white">2</span>
              <h3 class="mt-4 font-semibold text-gray-900">{{ t('landing_step2_title') }}</h3>
              <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ t('landing_step2_body') }}</p>
            </li>
            <li class="rounded-2xl border border-gray-100 bg-background p-6 shadow-sm">
              <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-primary text-sm font-bold text-white">3</span>
              <h3 class="mt-4 font-semibold text-gray-900">{{ t('landing_step3_title') }}</h3>
              <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ t('landing_step3_body') }}</p>
            </li>
            <li class="rounded-2xl border border-gray-100 bg-background p-6 shadow-sm">
              <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-primary text-sm font-bold text-white">4</span>
              <h3 class="mt-4 font-semibold text-gray-900">{{ t('landing_step4_title') }}</h3>
              <p class="mt-2 text-sm leading-relaxed text-gray-600">{{ t('landing_step4_body') }}</p>
            </li>
          </ol>
        </div>
      </section>

      <section
        v-if="!isInstalled"
        id="install"
        class="border-y border-gray-100 bg-soft-blue/40 px-4 py-16 md:py-20"
      >
        <div class="mx-auto max-w-3xl text-center">
          <div
            class="mx-auto mb-5 flex h-12 w-12 items-center justify-center rounded-xl bg-white text-primary shadow-sm"
            aria-hidden="true"
          >
            <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="1.75"
                d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"
              />
            </svg>
          </div>
          <h2 class="text-3xl font-bold text-gray-900 md:text-4xl">{{ t('landing_install_title') }}</h2>
          <p class="mt-4 text-lg text-gray-600">{{ t('landing_install_subtitle') }}</p>

          <div class="mt-8 flex flex-col items-center gap-3">
            <button
              v-if="canPromptInstall || showIosTip"
              type="button"
              class="inline-flex min-h-11 min-w-[200px] items-center justify-center rounded-xl bg-primary px-6 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-60"
              :disabled="installPrompting"
              @click="onLandingInstall"
            >
              {{ canPromptInstall ? t('pwa_install_action') : t('pwa_ios_tip_title') }}
            </button>
            <p
              v-if="showIosTip && iosTipOpen"
              class="max-w-md text-sm leading-relaxed text-gray-600"
            >
              {{ t('pwa_ios_tip_body') }}
            </p>
            <p
              v-else-if="!canPromptInstall && !showIosTip"
              class="max-w-md text-sm leading-relaxed text-gray-600"
            >
              {{ t('landing_install_fallback') }}
            </p>
          </div>
        </div>
      </section>

      <section id="get-started" class="bg-gray-900 px-4 py-16 md:py-20">
        <div class="mx-auto max-w-3xl text-center">
          <h2 class="text-3xl font-bold text-white md:text-4xl">{{ t('landing_cta_title') }}</h2>
          <p class="mt-4 text-lg text-gray-300">{{ t('landing_cta_subtitle') }}</p>
          <div class="mt-8 flex flex-col items-center justify-center gap-4 sm:flex-row">
            <RouterLink
              to="/register"
              class="inline-flex min-w-[200px] items-center justify-center rounded-xl bg-primary px-6 py-3.5 text-sm font-semibold text-white hover:bg-blue-600"
            >
              {{ t('action_create_account') }}
            </RouterLink>
            <RouterLink
              :to="loginTo"
              class="inline-flex min-w-[200px] items-center justify-center rounded-xl border border-gray-600 px-6 py-3.5 text-sm font-semibold text-white hover:border-gray-400"
            >
              {{ t('action_sign_in') }}
            </RouterLink>
          </div>
        </div>
      </section>
    </main>

    <footer class="border-t border-gray-100 bg-white px-4 py-12">
      <div class="mx-auto max-w-6xl">
        <div class="flex flex-col items-center gap-8 text-center md:flex-row md:items-center md:justify-between md:text-left">
          <div class="flex flex-col items-center gap-3 md:items-start">
            <div class="flex items-center gap-3">
              <img src="/recordplus_logo_app.png" alt="" class="h-10 w-10 rounded-xl" width="40" height="40" aria-hidden="true" />
              <p class="text-xl font-bold text-gray-900">Record<span class="text-primary">Plus</span></p>
            </div>
            <p class="max-w-sm text-sm text-gray-600">{{ t('app_tagline') }}</p>
          </div>
          <div class="flex flex-wrap justify-center gap-6 text-sm md:justify-end">
            <RouterLink :to="loginTo" class="font-medium text-gray-600 hover:text-primary">{{ t('action_sign_in') }}</RouterLink>
            <RouterLink to="/register" class="font-medium text-primary hover:underline">{{ t('action_create_account') }}</RouterLink>
          </div>
        </div>
        <p class="mt-10 border-t border-gray-100 pt-8 text-center text-sm text-gray-500">
          {{ t('landing_copyright') }}
        </p>
      </div>
    </footer>
  </div>
</template>
