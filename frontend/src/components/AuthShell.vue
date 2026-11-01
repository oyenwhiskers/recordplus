<script setup lang="ts">
withDefaults(
  defineProps<{
    title: string
    subtitle: string
    showMedia?: boolean
    mediaSrc?: string
    /** Smaller sprite so denser pages (signup) can still vertical-center */
    mediaCompact?: boolean
  }>(),
  {
    showMedia: false,
    mediaSrc: '/landing/auth-illustration.png',
    mediaCompact: false,
  },
)
</script>

<template>
  <!-- svh = visible area in installed PWA; flex-1 art was blowing past Android nav -->
  <div class="flex h-svh max-h-svh flex-col overflow-hidden bg-background font-sans text-gray-900 antialiased">
    <div
      class="pointer-events-none fixed inset-0 bg-[radial-gradient(ellipse_80%_45%_at_50%_0%,rgba(45,107,255,0.1),transparent_50%),radial-gradient(ellipse_55%_35%_at_100%_100%,rgba(232,241,255,0.85),transparent_45%)] lg:hidden"
      aria-hidden="true"
    />

    <header class="relative z-20 shrink-0 px-5 pb-2 pt-[max(1rem,calc(var(--rp-sat)+0.35rem))] sm:px-6 sm:pt-[max(1.25rem,calc(var(--rp-sat)+0.5rem))] lg:absolute lg:left-0 lg:top-0 lg:z-30 lg:px-10 lg:pb-0 lg:pt-7">
      <RouterLink to="/" class="inline-flex items-center gap-2">
        <img
          src="/recordplus_logo_app.png"
          alt=""
          class="h-8 w-8 rounded-xl sm:h-9 sm:w-9"
          width="36"
          height="36"
          aria-hidden="true"
        />
        <span class="text-lg font-bold tracking-tight text-gray-900 sm:text-2xl">Record<span class="text-primary">Plus</span></span>
      </RouterLink>
    </header>

    <main class="relative z-10 flex min-h-0 flex-1">
      <div
        class="flex min-h-0 w-full flex-1 flex-col"
        :class="showMedia ? 'lg:grid lg:grid-cols-2' : ''"
      >
        <section
          class="flex min-h-0 flex-1 flex-col overflow-y-auto overscroll-y-contain px-5 pb-[max(1.5rem,calc(var(--rp-sab)+0.75rem))] pt-0 sm:px-6 lg:overflow-y-visible lg:bg-white lg:px-10 lg:pb-10 lg:pt-24 xl:px-16"
        >
          <!-- min-h-full + justify-center: centers when short, scrolls from top when tall -->
          <div
            class="flex min-h-full w-full flex-col justify-center lg:min-h-0 lg:py-0"
            :class="mediaCompact ? 'py-0' : 'py-2'"
          >
            <div class="mx-auto flex w-full max-w-[22rem] flex-col lg:max-w-[26rem]">
              <div
                v-if="showMedia"
                class="mx-auto flex max-w-full shrink-0 items-center justify-center overflow-hidden lg:hidden"
                :class="
                  mediaCompact
                    ? 'mb-1 size-[min(38svh,16.5rem)]'
                    : 'mb-2 size-[min(52svh,21.5rem)]'
                "
              >
                <div class="relative flex size-full items-center justify-center overflow-hidden">
                  <div
                    class="absolute inset-[8%] rounded-full bg-soft-blue/90 blur-2xl"
                    aria-hidden="true"
                  />
                  <img
                    :src="mediaSrc"
                    alt=""
                    class="relative z-10 size-full object-contain"
                    :class="mediaCompact ? 'origin-bottom scale-110 object-[center_42%]' : ''"
                    width="640"
                    height="640"
                    decoding="async"
                  />
                </div>
              </div>

              <div class="shrink-0 text-center lg:text-left">
                <h1
                  class="font-bold leading-tight tracking-tight text-primary"
                  :class="mediaCompact ? 'text-lg sm:text-[1.75rem]' : 'text-xl sm:text-[1.75rem]'"
                >
                  {{ title }}
                </h1>
                <p
                  class="leading-snug text-gray-500"
                  :class="mediaCompact ? 'mt-0.5 text-xs sm:text-sm' : 'mt-1 text-sm'"
                >
                  {{ subtitle }}
                </p>
              </div>

              <div class="shrink-0 sm:mt-5" :class="mediaCompact ? 'mt-1.5' : 'mt-2.5'">
                <slot />
              </div>
            </div>
          </div>
        </section>

        <aside
          v-if="showMedia"
          class="relative hidden overflow-hidden lg:flex lg:items-center lg:justify-center"
        >
          <div
            class="pointer-events-none absolute inset-0"
            aria-hidden="true"
            style="background:
              linear-gradient(145deg, #dbe8ff 0%, #e8f1ff 28%, #f4f8ff 55%, #dce9ff 100%),
              radial-gradient(ellipse 80% 70% at 70% 30%, rgba(45, 107, 255, 0.22), transparent 55%),
              radial-gradient(ellipse 60% 50% at 20% 80%, rgba(45, 107, 255, 0.14), transparent 50%);"
          />
          <div
            class="pointer-events-none absolute -right-16 -top-16 h-72 w-72 rounded-full bg-primary/15 blur-3xl"
            aria-hidden="true"
          />
          <div
            class="pointer-events-none absolute -bottom-20 -left-10 h-80 w-80 rounded-full bg-white/50 blur-3xl"
            aria-hidden="true"
          />

          <div class="relative z-10 flex h-full w-full items-center justify-center p-6 xl:p-8">
            <img
              :src="mediaSrc"
              alt=""
              class="h-auto w-[min(100%,42rem)] max-h-[min(88svh,46rem)] object-contain drop-shadow-md"
              width="900"
              height="900"
              decoding="async"
            />
          </div>
        </aside>
      </div>
    </main>
  </div>
</template>
