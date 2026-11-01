<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import api, { ensureCsrf } from '@/api/client'
import AppPage from '@/components/AppPage.vue'
import EmptyState from '@/components/EmptyState.vue'
import PageHeader from '@/components/PageHeader.vue'

const { t } = useI18n()

type Period = 'all' | 'month'

const route = useRoute()
const id = computed(() => Number(route.params.id))

const user = ref<any>(null)
const logs = ref<any[]>([])
const period = ref<Period>('all')
const error = ref('')

const periodLabel = computed(() => (period.value === 'month' ? t('admin_this_month') : t('admin_all_time')))

const metrics = computed(() => {
  if (!user.value) {
    return { aiCalls: 0, aiCallsSuccess: 0, aiCallsFailed: 0, promptTokens: 0, completionTokens: 0, totalTokens: 0 }
  }

  if (period.value === 'month') {
    return {
      aiCalls: user.value.month_ai_calls_count,
      aiCallsSuccess: user.value.month_ai_calls_success_count ?? 0,
      aiCallsFailed: user.value.month_ai_calls_failed_count ?? 0,
      promptTokens: user.value.month_prompt_tokens,
      completionTokens: user.value.month_completion_tokens,
      totalTokens: user.value.month_total_tokens,
    }
  }

  return {
    aiCalls: user.value.ai_calls_count,
    aiCallsSuccess: user.value.ai_calls_success_count ?? 0,
    aiCallsFailed: user.value.ai_calls_failed_count ?? 0,
    promptTokens: user.value.prompt_tokens,
    completionTokens: user.value.completion_tokens,
    totalTokens: user.value.total_tokens,
  }
})

const visibleLogs = computed(() => {
  if (period.value === 'all') {
    return logs.value
  }

  const monthStart = new Date()
  monthStart.setDate(1)
  monthStart.setHours(0, 0, 0, 0)

  return logs.value.filter((log) => new Date(log.created_at) >= monthStart)
})

function metricClass(value: number): string {
  return value === 0 ? 'text-gray-400' : 'text-gray-900'
}

async function load() {
  try {
    const { data } = await api.get(`/admin/users/${id.value}`)
    user.value = data.user
    logs.value = data.recent_logs
  } catch (e: any) {
    error.value = e?.response?.data?.message || t('admin_detail_error')
  }
}

async function toggleAi() {
  if (!user.value) return
  await ensureCsrf()
  const { data } = await api.patch(`/admin/users/${user.value.id}`, {
    ai_enabled: !user.value.ai_enabled,
  })
  user.value.ai_enabled = data.user.ai_enabled
}

onMounted(load)
</script>

<template>
  <AppPage width="fluid">
  <div class="space-y-5">
    <PageHeader
      :title="user?.name ?? t('action_loading')"
      :description="user?.email"
      back-to="/admin"
      :back-label="t('admin_back_all_users')"
    >
      <template v-if="user" #meta>
        <span
          v-if="user.is_admin"
          class="inline-flex rounded-md bg-soft-blue px-2 py-0.5 text-xs font-semibold text-primary"
        >
          {{ t('admin_role_admin') }}
        </span>
      </template>
      <template v-if="user" #actions>
        <div class="inline-flex rounded-xl border border-gray-200 bg-white p-1" role="group" :aria-label="t('admin_period')">
          <button
            type="button"
            class="rounded-lg px-3 py-1.5 text-sm font-semibold"
            :class="period === 'all' ? 'bg-primary text-white' : 'text-gray-600 hover:bg-soft-blue'"
            @click="period = 'all'"
          >
            {{ t('admin_all_time') }}
          </button>
          <button
            type="button"
            class="rounded-lg px-3 py-1.5 text-sm font-semibold"
            :class="period === 'month' ? 'bg-primary text-white' : 'text-gray-600 hover:bg-soft-blue'"
            @click="period = 'month'"
          >
            {{ t('admin_this_month') }}
          </button>
        </div>
        <button
          type="button"
          class="rounded-xl px-4 py-2 text-sm font-semibold"
          :class="user.ai_enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'"
          @click="toggleAi"
        >
          {{ t('admin_ai_access_status', { status: user.ai_enabled ? t('admin_enabled') : t('admin_disabled') }) }}
        </button>
      </template>
    </PageHeader>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>

    <div v-if="user" class="grid gap-3 sm:grid-cols-3">
      <div class="rounded-2xl border border-gray-100 bg-white px-4 py-3">
        <p class="text-xs font-medium text-gray-500">{{ t('admin_documents') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(user.documents_count)">{{ user.documents_count }}</p>
      </div>
      <div class="rounded-2xl border border-gray-100 bg-white px-4 py-3">
        <p class="text-xs font-medium text-gray-500">{{ t('admin_ai_calls') }} · {{ periodLabel }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(metrics.aiCalls)">{{ metrics.aiCalls }}</p>
        <p class="mt-0.5 text-xs text-gray-400">
          {{ t('admin_success_failed', { success: metrics.aiCallsSuccess, failed: metrics.aiCallsFailed }) }}
        </p>
      </div>
      <div class="rounded-2xl border border-gray-100 bg-white px-4 py-3">
        <p class="text-xs font-medium text-gray-500">{{ t('admin_tokens') }} · {{ periodLabel }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(metrics.totalTokens)">{{ metrics.totalTokens.toLocaleString() }}</p>
        <p class="mt-1 text-xs text-gray-400">
          {{ t('admin_prompt_completion', { prompt: metrics.promptTokens.toLocaleString(), completion: metrics.completionTokens.toLocaleString() }) }}
        </p>
      </div>
    </div>

    <section v-if="user" class="rounded-2xl border border-gray-100 bg-white p-5">
      <h2 class="text-base font-semibold text-gray-900">{{ t('admin_ai_calls') }} · {{ periodLabel }}</h2>
      <EmptyState
        v-if="!visibleLogs.length"
        class="mt-2"
        icon="ai"
        :title="t('admin_empty_ai_title')"
        :description="t('admin_empty_ai_desc')"
      />
      <ul v-else class="mt-4 divide-y divide-gray-50">
        <li v-for="log in visibleLogs" :key="log.id" class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm">
          <div>
            <p class="font-medium text-gray-900">
              {{ log.model }}
              <span
                class="ml-2 rounded-md px-1.5 py-0.5 text-xs font-semibold"
                :class="log.status === 'success' ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700'"
              >
                {{ log.status }}
              </span>
            </p>
            <p class="mt-0.5 text-xs text-gray-500">
              {{ new Date(log.created_at).toLocaleString() }}
              <span v-if="log.document_id"> · {{ t('document_fallback', { id: log.document_id }) }}</span>
            </p>
          </div>
          <div class="text-right">
            <p class="font-semibold tabular-nums text-gray-900">{{ t('admin_total_tokens', { count: log.total_tokens.toLocaleString() }) }}</p>
            <p class="text-xs text-gray-400">
              {{ t('admin_prompt_completion', { prompt: log.prompt_tokens.toLocaleString(), completion: log.completion_tokens.toLocaleString() }) }}
            </p>
          </div>
        </li>
      </ul>
    </section>
  </div>
  </AppPage>
</template>
