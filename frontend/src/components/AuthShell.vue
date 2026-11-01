<script setup lang="ts">
withDefaults(
  defineProps<{
    title: string
    subtitle: string
    showMedia?: boolean
    mediaSrc?: string
  }>(),
  {
    showMedia: false,
    mediaSrc: '/landing/auth-illustration.png',
  },
)
</script>

<template>
  <div class="flex h-dvh flex-col overflow-hidden bg-background font-sans text-gray-900 antialiased">
    <div
      class="pointer-events-none fixed inset-0 bg-[radial-gradient(ellipse_80%_45%_at_50%_0%,rgba(45,107,255,0.1),transparent_50%),radial-gradient(ellipse_55%_35%_at_100%_100%,rgba(232,241,255,0.85),transparent_45%)] lg:hidden"
      aria-hidden="true"
    />

    <header class="relative z-20 shrink-0 px-4 pb-1 pt-4 sm:px-6 sm:pt-5 lg:absolute lg:left-0 lg:top-0 lg:z-30 lg:px-10 lg:pt-7">
      <RouterLink to="/" class="inline-flex items-center gap-2.5">
        <img
          src="/recordplus_logo_app.png"
          alt=""
          class="h-9 w-9 rounded-xl"
          width="36"
          height="36"
          aria-hidden="true"
        />
        <span class="text-xl font-bold tracking-tight text-gray-900 sm:text-2xl">Record<span class="text-primary">Plus</span></span>
      </RouterLink>
    </header>

    <main class="relative z-10 flex min-h-0 flex-1">
      <div
        class="flex w-full flex-1 flex-col"
        :class="showMedia ? 'lg:grid lg:grid-cols-2' : ''"
      >
        <!-- Form column -->
        <section
          class="flex min-h-0 flex-1 flex-col items-center justify-start px-4 pb-4 pt-1 sm:px-6 lg:items-center lg:justify-center lg:bg-white lg:px-10 lg:pb-10 lg:pt-24 xl:px-16"
        >
          <div class="flex w-full max-w-[22rem] flex-col lg:max-w-[26rem]">
            <!-- Mobile / tablet sprite above form -->
            <div v-if="showMedia" class="mb-2 flex shrink-0 justify-center lg:hidden">
              <div class="relative w-full">
                <div
                  class="absolute inset-[6%] rounded-full bg-soft-blue/90 blur-2xl"
                  aria-hidden="true"
                />
                <img
                  :src="mediaSrc"
                  alt=""
                  class="relative z-10 mx-auto h-auto max-h-[40dvh] w-full object-contain"
                  width="640"
                  height="640"
                  decoding="async"
                />
              </div>
            </div>

            <div class="text-center lg:text-left">
              <h1 class="text-2xl font-bold leading-tight tracking-tight text-primary sm:text-[1.75rem]">
                {{ title }}
              </h1>
              <p class="mt-1.5 text-sm leading-snug text-gray-500">
                {{ subtitle }}
              </p>
            </div>

            <div class="mt-4 sm:mt-5">
              <slot />
            </div>
          </div>
        </section>

        <!-- Desktop sprite panel -->
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
              class="h-auto w-[min(100%,42rem)] max-h-[min(88dvh,46rem)] object-contain drop-shadow-md"
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
