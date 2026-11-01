<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const offline = ref(!navigator.onLine)

function update() {
  offline.value = !navigator.onLine
}

onMounted(() => {
  window.addEventListener('online', update)
  window.addEventListener('offline', update)
})

onUnmounted(() => {
  window.removeEventListener('online', update)
  window.removeEventListener('offline', update)
})
</script>

<template>
  <div v-if="offline" class="bg-amber-500 px-4 py-2 text-center text-sm font-medium text-white">
    {{ t('offline_banner') }}
  </div>
</template>
