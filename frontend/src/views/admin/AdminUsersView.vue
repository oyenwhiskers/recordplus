<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { ensureCsrf } from '@/api/client'
import AppPage from '@/components/AppPage.vue'
import EmptyState from '@/components/EmptyState.vue'
import PageHeader from '@/components/PageHeader.vue'

const { t } = useI18n()

type Period = 'all' | 'month'

interface AdminUserRow {
  id: number
  name: string
  email: string
  ai_enabled: boolean
  is_admin: boolean
  documents_count: number
  ai_calls_count: number
  ai_calls_success_count: number
  ai_calls_failed_count: number
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  month_ai_calls_count: number
  month_ai_calls_success_count: number
  month_ai_calls_failed_count: number
  month_prompt_tokens: number
  month_completion_tokens: number
  month_total_tokens: number
}

interface AdminDocumentRow {
  id: number
  title: string | null
  original_filename: string
  status: string
  created_at: string
}

interface AdminLogRow {
  id: number
  model: string
  status: string
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  created_at: string
  document_id: number | null
  document?: {
    id: number
    title: string | null
    original_filename: string
  } | null
}

interface AdminUserDetail {
  user: AdminUserRow
  recent_logs: AdminLogRow[]
  recent_documents: AdminDocumentRow[]
}

// shortcut: gemini-3.1-flash-lite Standard paid tier (text/image/video input $0.25, output $1.50 incl. thinking) × fixed USD→MYR; update when Google pricing or FX drifts
const PROMPT_USD_PER_M = 0.25
const COMPLETION_USD_PER_M = 1.5
const USD_TO_MYR = 4.09
const PROMPT_MYR_PER_M = PROMPT_USD_PER_M * USD_TO_MYR
const COMPLETION_MYR_PER_M = COMPLETION_USD_PER_M * USD_TO_MYR

const users = ref<AdminUserRow[]>([])
const period = ref<Period>('all')
const loading = ref(false)
const error = ref('')

const modalOpen = ref(false)
const detail = ref<AdminUserDetail | null>(null)
const detailLoading = ref(false)
const detailError = ref('')

function estimateCost(promptTokens: number, completionTokens: number) {
  const promptCost = (promptTokens / 1_000_000) * PROMPT_MYR_PER_M
  const completionCost = (completionTokens / 1_000_000) * COMPLETION_MYR_PER_M
  return {
    promptCost,
    completionCost,
    totalCost: promptCost + completionCost,
  }
}

function formatMyr(value: number): string {
  if (value === 0) return 'RM0.00'
  if (value < 0.01) return `RM${value.toFixed(6)}`
  return `RM${value.toFixed(4)}`
}

function formatAvgTokens(value: number): string {
  if (value === 0) return '0'
  if (value < 10) return value.toFixed(1)
  return Math.round(value).toLocaleString()
}

/** Derived spend signals from aggregated tokens/calls (estimate only). */
function costInsights(metrics: {
  aiCalls: number
  aiCallsSuccess: number
  totalTokens: number
  totalCost: number
}, documentsCount: number) {
  const calls = metrics.aiCalls
  const success = metrics.aiCallsSuccess
  return {
    avgCostPerCall: calls > 0 ? metrics.totalCost / calls : 0,
    avgCostPerSuccess: success > 0 ? metrics.totalCost / success : 0,
    avgTokensPerCall: calls > 0 ? metrics.totalTokens / calls : 0,
    costPerDocument: documentsCount > 0 ? metrics.totalCost / documentsCount : 0,
  }
}

function formatDateTime(iso: string): string {
  return new Date(iso).toLocaleString(undefined, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
}

function monthStart(): Date {
  const date = new Date()
  date.setDate(1)
  date.setHours(0, 0, 0, 0)
  return date
}

function inPeriod(iso: string): boolean {
  if (period.value === 'all') return true
  return new Date(iso) >= monthStart()
}

function rowMetrics(user: AdminUserRow) {
  const base =
    period.value === 'month'
      ? {
          aiCalls: user.month_ai_calls_count,
          aiCallsSuccess: user.month_ai_calls_success_count ?? 0,
          aiCallsFailed: user.month_ai_calls_failed_count ?? 0,
          promptTokens: user.month_prompt_tokens,
          completionTokens: user.month_completion_tokens,
          totalTokens: user.month_total_tokens,
        }
      : {
          aiCalls: user.ai_calls_count,
          aiCallsSuccess: user.ai_calls_success_count ?? 0,
          aiCallsFailed: user.ai_calls_failed_count ?? 0,
          promptTokens: user.prompt_tokens,
          completionTokens: user.completion_tokens,
          totalTokens: user.total_tokens,
        }

  return { ...base, ...estimateCost(base.promptTokens, base.completionTokens) }
}

const totals = computed(() => {
  const metrics = users.value.map(rowMetrics)
  const aiCalls = metrics.reduce((sum, m) => sum + m.aiCalls, 0)
  const aiCallsSuccess = metrics.reduce((sum, m) => sum + m.aiCallsSuccess, 0)
  const tokens = metrics.reduce((sum, m) => sum + m.totalTokens, 0)
  const cost = metrics.reduce((sum, m) => sum + m.totalCost, 0)
  const documents = users.value.reduce((sum, u) => sum + u.documents_count, 0)
  return {
    users: users.value.length,
    documents,
    aiCalls,
    aiCallsSuccess,
    tokens,
    cost,
    ...costInsights(
      { aiCalls, aiCallsSuccess, totalTokens: tokens, totalCost: cost },
      documents,
    ),
  }
})

const detailMetrics = computed(() => {
  if (!detail.value) {
    return {
      aiCalls: 0,
      aiCallsSuccess: 0,
      aiCallsFailed: 0,
      promptTokens: 0,
      completionTokens: 0,
      totalTokens: 0,
      ...estimateCost(0, 0),
    }
  }
  return rowMetrics(detail.value.user)
})

const detailCostInsights = computed(() =>
  costInsights(detailMetrics.value, overviewDocumentsCount.value),
)

const visibleDocuments = computed(() => {
  if (!detail.value) return []
  return detail.value.recent_documents.filter((doc) => inPeriod(doc.created_at))
})

const visibleLogs = computed(() => {
  if (!detail.value) return []
  return detail.value.recent_logs.filter((log) => inPeriod(log.created_at))
})

function metricClass(value: number): string {
  return value === 0 ? 'text-gray-400' : 'text-gray-900'
}

function statusLabel(status: string): string {
  const keys: Record<string, string> = {
    processing: 'status_processing',
    filed: 'status_filed',
    needs_review: 'status_needs_review',
    uncategorised: 'status_uncategorised',
    processing_failed: 'status_processing_failed',
  }
  return keys[status] ? t(keys[status]) : status.replace(/_/g, ' ')
}

const overviewDocumentsCount = computed(() => {
  if (!detail.value) return 0
  return period.value === 'all' ? detail.value.user.documents_count : visibleDocuments.value.length
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const { data } = await api.get('/admin/users')
    users.value = data.users.data ?? data.users
  } catch (e: any) {
    error.value = e?.response?.data?.message || t('admin_load_error')
  } finally {
    loading.value = false
  }
}

async function toggleAi(user: AdminUserRow) {
  await ensureCsrf()
  const { data } = await api.patch(`/admin/users/${user.id}`, {
    ai_enabled: !user.ai_enabled,
  })
  user.ai_enabled = data.user.ai_enabled
  if (detail.value?.user.id === user.id) {
    detail.value.user.ai_enabled = data.user.ai_enabled
  }
}

async function openUserDetail(user: AdminUserRow) {
  modalOpen.value = true
  detailLoading.value = true
  detailError.value = ''
  detail.value = null
  try {
    const { data } = await api.get(`/admin/users/${user.id}`)
    detail.value = {
      user: data.user,
      recent_logs: data.recent_logs ?? [],
      recent_documents: data.recent_documents ?? [],
    }
  } catch (e: any) {
    detailError.value = e?.response?.data?.message || t('admin_detail_error')
  } finally {
    detailLoading.value = false
  }
}

function closeModal() {
  modalOpen.value = false
  detail.value = null
  detailError.value = ''
}

function onKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && modalOpen.value) {
    closeModal()
  }
}

onMounted(() => {
  load()
  window.addEventListener('keydown', onKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', onKeydown)
})
</script>

<template>
  <AppPage width="fluid">
  <div class="space-y-5">
    <PageHeader
      :title="t('admin_usage_title')"
      :description="t('admin_usage_desc')"
    >
      <template #actions>
        <div class="inline-flex rounded-xl border border-gray-200 bg-white p-1" role="group" :aria-label="t('admin_period')">
          <button
            type="button"
            class="rounded-lg px-4 py-2 text-sm font-semibold"
            :class="period === 'all' ? 'bg-primary text-white' : 'text-gray-600 hover:bg-soft-blue'"
            @click="period = 'all'"
          >
            {{ t('admin_all_time') }}
          </button>
          <button
            type="button"
            class="rounded-lg px-4 py-2 text-sm font-semibold"
            :class="period === 'month' ? 'bg-primary text-white' : 'text-gray-600 hover:bg-soft-blue'"
            @click="period = 'month'"
          >
            {{ t('admin_this_month') }}
          </button>
        </div>
      </template>
    </PageHeader>

    <div v-if="!loading && !error" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
      <div class="rounded-2xl border border-gray-100 bg-white px-4 py-3">
        <p class="text-xs font-medium text-gray-500">{{ t('admin_users') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums text-gray-900">{{ totals.users }}</p>
      </div>
      <div class="rounded-2xl border border-gray-100 bg-white px-4 py-3">
        <p class="text-xs font-medium text-gray-500">{{ t('admin_documents') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(totals.documents)">{{ totals.documents }}</p>
      </div>
      <div class="rounded-2xl border border-gray-100 bg-white px-4 py-3">
        <p class="text-xs font-medium text-gray-500">{{ t('admin_ai_calls') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(totals.aiCalls)">{{ totals.aiCalls }}</p>
      </div>
      <div class="rounded-2xl border border-gray-100 bg-white px-4 py-3">
        <p class="text-xs font-medium text-gray-500">{{ t('admin_tokens') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(totals.tokens)">{{ totals.tokens.toLocaleString() }}</p>
      </div>
      <div class="rounded-2xl border border-gray-100 bg-white px-4 py-3">
        <p class="text-xs font-medium text-gray-500">{{ t('admin_est_cost') }}</p>
        <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(totals.cost)">{{ formatMyr(totals.cost) }}</p>
        <p class="mt-0.5 text-xs text-gray-400">
          {{ t('admin_per_call', { amount: formatMyr(totals.avgCostPerCall) }) }}
          <span v-if="totals.documents"> · {{ t('admin_per_doc', { amount: formatMyr(totals.costPerDocument) }) }}</span>
        </p>
      </div>
    </div>

    <p v-if="error" class="text-sm text-red-600">{{ error }}</p>
    <p v-if="loading" class="text-sm text-gray-500">{{ t('action_loading') }}</p>

    <div v-else class="overflow-x-auto rounded-2xl border border-gray-100 bg-white">
      <table class="min-w-full text-sm">
        <thead class="border-b border-gray-100 bg-background text-xs font-semibold uppercase tracking-wide text-gray-500">
          <tr>
            <th class="min-w-[14rem] px-4 py-3 text-left">{{ t('admin_col_user') }}</th>
            <th class="px-4 py-3 text-left">{{ t('admin_col_role') }}</th>
            <th class="px-4 py-3 text-right">{{ t('admin_col_documents') }}</th>
            <th class="px-4 py-3 text-right">{{ t('admin_col_ai_calls') }}</th>
            <th class="min-w-[9rem] px-4 py-3 text-right">{{ t('admin_col_tokens') }}</th>
            <th class="min-w-[7rem] px-4 py-3 text-right">{{ t('admin_col_cost') }}</th>
            <th class="px-4 py-3 text-right">{{ t('admin_col_ai_access') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
          <tr
            v-for="user in users"
            :key="user.id"
            class="cursor-pointer hover:bg-soft-blue/40"
            @click="openUserDetail(user)"
          >
            <td class="px-4 py-4">
              <p class="font-semibold text-gray-900">{{ user.name }}</p>
              <p class="mt-0.5 text-xs text-gray-500">{{ user.email }}</p>
            </td>
            <td class="px-4 py-4">
              <span
                class="inline-flex rounded-md px-2 py-0.5 text-xs font-semibold"
                :class="user.is_admin ? 'bg-soft-blue text-primary' : 'bg-gray-100 text-gray-600'"
              >
                {{ user.is_admin ? t('admin_role_admin') : t('admin_role_user') }}
              </span>
            </td>
            <td class="px-4 py-4 text-right tabular-nums" :class="metricClass(user.documents_count)">
              {{ user.documents_count }}
            </td>
            <td class="px-4 py-4 text-right tabular-nums" :class="metricClass(rowMetrics(user).aiCalls)">
              {{ rowMetrics(user).aiCalls }}
            </td>
            <td class="px-4 py-4 text-right tabular-nums font-medium" :class="metricClass(rowMetrics(user).totalTokens)">
              {{ rowMetrics(user).totalTokens.toLocaleString() }}
            </td>
            <td class="px-4 py-4 text-right tabular-nums font-medium" :class="metricClass(rowMetrics(user).totalCost)">
              {{ formatMyr(rowMetrics(user).totalCost) }}
            </td>
            <td class="px-4 py-4 text-right">
              <button
                type="button"
                class="rounded-xl px-3 py-1.5 text-xs font-semibold"
                :class="user.ai_enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-gray-100 text-gray-600'"
                @click.stop="toggleAi(user)"
              >
                {{ user.ai_enabled ? t('admin_enabled') : t('admin_disabled') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <EmptyState
        v-if="!users.length"
        class="py-10"
        icon="users"
        :title="t('admin_empty_users_title')"
        :description="t('admin_empty_users_desc')"
      />
    </div>
  </div>

  <Teleport to="body">
    <div
      v-if="modalOpen"
      class="fixed inset-0 z-50 flex items-stretch justify-center bg-gray-900/40 p-3 sm:p-4"
      @click.self="closeModal"
    >
      <div
        role="dialog"
        aria-modal="true"
        :aria-label="detail?.user.name ? t('admin_user_detail_aria', { name: detail.user.name }) : t('admin_user_detail_fallback')"
        class="flex h-full w-full max-w-none flex-col overflow-hidden rounded-2xl bg-white shadow-xl"
      >
        <div class="flex flex-col gap-3 border-b border-gray-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="truncate text-xl font-bold text-gray-900">
                {{ detail?.user.name || t('action_loading') }}
              </h2>
              <span
                v-if="detail"
                class="inline-flex shrink-0 rounded-md px-2 py-0.5 text-xs font-semibold"
                :class="detail.user.is_admin ? 'bg-soft-blue text-primary' : 'bg-gray-100 text-gray-600'"
              >
                {{ detail.user.is_admin ? t('admin_role_admin') : t('admin_role_user') }}
              </span>
            </div>
            <p v-if="detail" class="mt-0.5 truncate text-sm text-gray-500">{{ detail.user.email }}</p>
          </div>

          <div class="flex shrink-0 items-center gap-2 self-end sm:self-center">
            <div
              class="inline-flex rounded-xl border border-gray-200 bg-background p-1"
              role="group"
              :aria-label="t('admin_period')"
            >
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
              class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-xl text-gray-400 hover:bg-gray-100 hover:text-gray-700"
              :aria-label="t('action_close')"
              @click="closeModal"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <div class="overflow-y-auto px-5 py-5 sm:px-6">
          <p v-if="detailLoading" class="text-sm text-gray-500">{{ t('admin_loading_details') }}</p>
          <p v-else-if="detailError" class="text-sm text-red-600">{{ detailError }}</p>

          <div v-else-if="detail" class="space-y-5">
            <section>
              <h3 class="text-sm font-semibold text-gray-900">{{ t('admin_overview') }}</h3>
              <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-xl border border-gray-100 bg-background/80 px-4 py-3">
                  <p class="text-xs font-medium text-gray-500">{{ t('admin_documents') }}</p>
                  <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(overviewDocumentsCount)">
                    {{ overviewDocumentsCount }}
                  </p>
                </div>
                <div class="rounded-xl border border-gray-100 bg-background/80 px-4 py-3">
                  <p class="text-xs font-medium text-gray-500">{{ t('admin_ai_calls') }}</p>
                  <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(detailMetrics.aiCalls)">
                    {{ detailMetrics.aiCalls }}
                  </p>
                  <p class="mt-0.5 text-xs text-gray-400">
                    {{ t('admin_success_failed', { success: detailMetrics.aiCallsSuccess, failed: detailMetrics.aiCallsFailed }) }}
                  </p>
                </div>
                <div class="rounded-xl border border-gray-100 bg-background/80 px-4 py-3">
                  <p class="text-xs font-medium text-gray-500">{{ t('admin_tokens') }}</p>
                  <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(detailMetrics.totalTokens)">
                    {{ detailMetrics.totalTokens.toLocaleString() }}
                  </p>
                  <p class="mt-0.5 text-xs text-gray-400">
                    {{ t('admin_prompt_completion', { prompt: detailMetrics.promptTokens.toLocaleString(), completion: detailMetrics.completionTokens.toLocaleString() }) }}
                  </p>
                </div>
                <div class="rounded-xl border border-gray-100 bg-background/80 px-4 py-3">
                  <p class="text-xs font-medium text-gray-500">{{ t('admin_avg_tokens_call') }}</p>
                  <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(detailCostInsights.avgTokensPerCall)">
                    {{ formatAvgTokens(detailCostInsights.avgTokensPerCall) }}
                  </p>
                  <p class="mt-0.5 text-xs text-gray-400">{{ t('admin_avg_tokens_hint') }}</p>
                </div>
                <div class="rounded-xl border border-gray-100 bg-background/80 px-4 py-3">
                  <p class="text-xs font-medium text-gray-500">{{ t('admin_est_cost') }}</p>
                  <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(detailMetrics.totalCost)">
                    {{ formatMyr(detailMetrics.totalCost) }}
                  </p>
                  <p class="mt-0.5 text-xs text-gray-400">
                    {{ t('admin_prompt_completion', { prompt: formatMyr(detailMetrics.promptCost), completion: formatMyr(detailMetrics.completionCost) }) }}
                  </p>
                </div>
                <div class="rounded-xl border border-gray-100 bg-background/80 px-4 py-3">
                  <p class="text-xs font-medium text-gray-500">{{ t('admin_avg_cost_call') }}</p>
                  <p class="mt-1 text-2xl font-bold tabular-nums" :class="metricClass(detailCostInsights.avgCostPerCall)">
                    {{ formatMyr(detailCostInsights.avgCostPerCall) }}
                  </p>
                  <p class="mt-0.5 text-xs text-gray-400">
                    {{ t('admin_per_success', { amount: formatMyr(detailCostInsights.avgCostPerSuccess) }) }}
                    <span v-if="overviewDocumentsCount">
                      · {{ t('admin_per_doc', { amount: formatMyr(detailCostInsights.costPerDocument) }) }}
                    </span>
                  </p>
                </div>
              </div>
            </section>

            <section>
              <h3 class="text-sm font-semibold text-gray-900">{{ t('admin_documents') }}</h3>
              <EmptyState
                v-if="!visibleDocuments.length"
                class="mt-1"
                icon="document"
                :title="t('admin_empty_docs_title')"
                :description="t('admin_empty_docs_desc')"
              />
              <ul v-else class="mt-3 divide-y divide-gray-50 rounded-xl border border-gray-100">
                <li
                  v-for="doc in visibleDocuments"
                  :key="doc.id"
                  class="px-3 py-3 text-sm"
                >
                  <p class="truncate font-medium text-gray-900">{{ doc.title || doc.original_filename }}</p>
                  <p class="mt-0.5 text-xs text-gray-500">
                    {{ statusLabel(doc.status) }} · {{ formatDateTime(doc.created_at) }}
                  </p>
                </li>
              </ul>
            </section>

            <section>
              <h3 class="text-sm font-semibold text-gray-900">{{ t('admin_ai_history') }}</h3>
              <EmptyState
                v-if="!visibleLogs.length"
                class="mt-1"
                icon="ai"
                :title="t('admin_empty_ai_title')"
                :description="t('admin_empty_ai_desc')"
              />
              <ul v-else class="mt-3 divide-y divide-gray-50 rounded-xl border border-gray-100">
                <li
                  v-for="log in visibleLogs"
                  :key="log.id"
                  class="flex flex-wrap items-start justify-between gap-3 px-3 py-3 text-sm"
                >
                  <div class="min-w-0">
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
                      {{ formatDateTime(log.created_at) }}
                      <span v-if="log.document">
                        · {{ log.document.title || log.document.original_filename }}
                      </span>
                    </p>
                  </div>
                  <div class="text-right">
                    <p class="font-semibold tabular-nums text-gray-900">
                      {{ formatMyr(estimateCost(log.prompt_tokens, log.completion_tokens).totalCost) }}
                    </p>
                    <p class="text-xs tabular-nums text-gray-400">
                      {{ log.total_tokens.toLocaleString() }} tokens
                      · {{ log.prompt_tokens.toLocaleString() }} / {{ log.completion_tokens.toLocaleString() }}
                    </p>
                  </div>
                </li>
              </ul>
              <p class="mt-2 text-xs text-gray-400">
                Gemini 3.1 Flash-Lite paid: ${{ PROMPT_USD_PER_M.toFixed(2) }}/1M input · ${{ COMPLETION_USD_PER_M.toFixed(2) }}/1M output
                → RM{{ PROMPT_MYR_PER_M.toFixed(2) }} / RM{{ COMPLETION_MYR_PER_M.toFixed(2) }} (USD×{{ USD_TO_MYR }})
              </p>
            </section>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
  </AppPage>
</template>
