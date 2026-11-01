<script setup lang="ts">
import { ref } from 'vue'
import { useRouter, type RouteLocationNormalized } from 'vue-router'

const AUTH_PAIR = new Set(['login', 'register'])
const AUTH_ORDER: Record<string, number> = { login: 0, register: 1 }

const transitionName = ref('auth-none')
const router = useRouter()

function isAuthPairRoute(route: RouteLocationNormalized): boolean {
  return AUTH_PAIR.has(String(route.name ?? ''))
}

router.beforeEach((to, from) => {
  if (isAuthPairRoute(to) && isAuthPairRoute(from)) {
    const toOrder = AUTH_ORDER[String(to.name)] ?? 0
    const fromOrder = AUTH_ORDER[String(from.name)] ?? 0
    transitionName.value = toOrder > fromOrder ? 'auth-forward' : 'auth-back'
    return
  }
  transitionName.value = 'auth-none'
})
</script>

<template>
  <div class="rp-route-root">
    <RouterView v-slot="{ Component, route }">
      <Transition :name="transitionName">
        <component :is="Component" :key="String(route.name ?? route.path)" />
      </Transition>
    </RouterView>
  </div>
</template>
