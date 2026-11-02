<script setup lang="ts">
import { computed, onActivated, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/api/client'
import { notify } from '@/lib/confirm'
import { useAuthStore } from '@/stores/auth'
import type { Category, DocumentItem } from '@/types'
import AppPage from '@/components/AppPage.vue'
import DocumentListRow from '@/components/DocumentListRow.vue'
import EmptyState from '@/components/EmptyState.vue'
import LoadingModal from '@/components/LoadingModal.vue'

defineOptions({ name: 'RecentView' })

const { t } = useI18n()

const auth = useAuthStore()
const documents = ref<DocumentItem[]>([])
const categories = ref<Category[]>([])
const q = ref('')
const appliedQ = ref('')
const categoryId = ref<number | ''>('')
const dateFrom = ref('')
const dateTo = ref('')
const draftDateFrom = ref('')
const draftDateTo = ref('')
const showDateFilter = ref(false)
const showCategoryFilter = ref(false)
const loading = ref(false)
const loadingMore = ref(false)
const page = ref(1)
const lastPage = ref(1)
let searchTimer: ReturnType<typeof setTimeout> | null = null

const dateFilterActive = computed(() => Boolean(dateFrom.value || dateTo.value))
const categoryFilterActive = computed(() => Boolean(categoryId.value))
const filtersActive = computed(() => dateFilterActive.value || categoryFilterActive.value)

const selectedCategoryName = computed(
  () => categories.value.find((c) => c.id === categoryId.value)?.name ?? null,
)

async function loadCategories() {
  const { data } = await api.get('/categories')
  categories.value = data.categories
}

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
  }

  try {
    const { data } = await api.get('/documents', {
      params: {
        q: appliedQ.value || undefined,
        category_id: categoryId.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        sort: 'newest',
        page: page.value,
      },
    })
    const paginator = data.documents
    const rows: DocumentItem[] = paginator.data ?? paginator
    documents.value = reset ? rows : [...documents.value, ...rows]
    lastPage.value = paginator.last_page ?? 1
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function applySearch() {
  appliedQ.value = q.value.trim()
  load(true)
}

function onSearchInput() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(applySearch, 300)
}

function openDateFilter() {
  draftDateFrom.value = dateFrom.value
  draftDateTo.value = dateTo.value
  showCategoryFilter.value = false
  showDateFilter.value = true
}

function closeDateFilter() {
  showDateFilter.value = false
}

function openCategoryFilter() {
  showDateFilter.value = false
  showCategoryFilter.value = true
}

function closeCategoryFilter() {
  showCategoryFilter.value = false
}

function applyDateFilter() {
  if (draftDateFrom.value && draftDateTo.value && draftDateFrom.value > draftDateTo.value) {
    notify({ title: t('msg_date_range_invalid'), icon: 'warning' })
    return
  }

  dateFrom.value = draftDateFrom.value
  dateTo.value = draftDateTo.value
  showDateFilter.value = false
  load(true)
}

function clearDateFilter() {
  draftDateFrom.value = ''
  draftDateTo.value = ''
  dateFrom.value = ''
  dateTo.value = ''
  showDateFilter.value = false
  load(true)
}

function setDatePreset(preset: 'month' | '30d' | 'clear') {
  if (preset === 'clear') {
    draftDateFrom.value = ''
    draftDateTo.value = ''
    return
  }

  const today = new Date()
  const to = today.toISOString().slice(0, 10)

  if (preset === '30d') {
    const from = new Date(today)
    from.setDate(from.getDate() - 30)
    draftDateFrom.value = from.toISOString().slice(0, 10)
    draftDateTo.value = to
    return
  }

  const from = new Date(today.getFullYear(), today.getMonth(), 1)
  draftDateFrom.value = from.toISOString().slice(0, 10)
  draftDateTo.value = to
}

function selectCategory(id: number | '') {
  categoryId.value = id
  showCategoryFilter.value = false
  load(true)
}

function clearCategoryFilter() {
  categoryId.value = ''
  showCategoryFilter.value = false
  load(true)
}

// KeepAlive: refetch whenever Recent is shown (after Capture confirm, etc.)
onActivated(async () => {
  await loadCategories()
  await load(true)
})

onUnmounted(() => {
  if (searchTimer) clearTimeout(searchTimer)
})

watch(
  () => auth.activeProfile?.id,
  async () => {
    q.value = ''
    appliedQ.value = ''
    categoryId.value = ''
    dateFrom.value = ''
    dateTo.value = ''
    showDateFilter.value = false
    showCategoryFilter.value = false
    await loadCategories()
    await load(true)
  },
)
</script>

<template>
  <AppPage width="fluid" fill>
    <div class="mx-auto flex min-h-0 w-full max-w-3xl flex-1 flex-col gap-4 overflow-hidden">
      <div class="relative flex shrink-0 items-center gap-2">
        <label class="relative min-w-0 flex-1">
          <span class="sr-only">{{ t('aria_search_recent') }}</span>
          <svg
            class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            aria-hidden="true"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 100-15 7.5 7.5 0 000 15z" />
          </svg>
          <input
            id="recent-search"
            v-model="q"
            type="search"
            :placeholder="t('hint_search')"
            class="min-h-11 w-full rounded-xl border border-gray-200 bg-white py-2.5 pl-10 pr-3 text-sm outline-none focus:border-primary"
            @input="onSearchInput"
            @keydown.enter.prevent="applySearch"
          />
        </label>

        <button
          type="button"
          class="relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border bg-white text-gray-600 hover:bg-gray-50"
          :class="dateFilterActive || showDateFilter ? 'border-primary text-primary' : 'border-gray-200'"
          :title="t('label_date_filter')"
          :aria-label="t('label_date_filter')"
          :aria-expanded="showDateFilter"
          @click="openDateFilter"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
          <span
            v-if="dateFilterActive"
            class="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary"
            aria-hidden="true"
          />
        </button>

        <button
          type="button"
          class="relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border bg-white text-gray-600 hover:bg-gray-50"
          :class="categoryFilterActive || showCategoryFilter ? 'border-primary text-primary' : 'border-gray-200'"
          :title="t('label_category_filter')"
          :aria-label="t('label_category_filter')"
          :aria-expanded="showCategoryFilter"
          @click="openCategoryFilter"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 4h18l-7 8v5l-4 2v-7L3 4z" />
          </svg>
          <span
            v-if="categoryFilterActive"
            class="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary"
            aria-hidden="true"
          />
        </button>
      </div>

      <p
        v-if="appliedQ || filtersActive"
        class="shrink-0 text-xs text-gray-500"
      >
        <template v-if="selectedCategoryName">{{ selectedCategoryName }} · </template>
        <template v-if="dateFilterActive">
          {{ dateFrom || '…' }} – {{ dateTo || '…' }} ·
        </template>
        {{ t('recent_filtered_results') }}
      </p>

      <div class="flex min-h-0 flex-1 flex-col overflow-y-auto pb-4">
        <EmptyState
          v-if="!loading && !documents.length"
          icon="document"
          :title="appliedQ || filtersActive ? t('recent_empty_match_title') : t('recent_empty_title')"
          :description="appliedQ || filtersActive ? t('files_empty_match_desc') : t('recent_empty_desc')"
        >
          <RouterLink
            v-if="!appliedQ && !filtersActive"
            to="/capture"
            class="text-sm font-semibold text-primary hover:underline"
          >
            {{ t('action_go_to_capture') }}
          </RouterLink>
        </EmptyState>
        <ul v-else-if="documents.length" class="space-y-3">
          <li v-for="doc in documents" :key="doc.id">
            <DocumentListRow
              :doc="doc"
              variant="card"
              :category-label="doc.category?.name ?? doc.suggested_category_name"
            />
          </li>
        </ul>
        <div v-if="page < lastPage" class="mt-3 text-center">
          <button
            type="button"
            class="min-h-10 rounded-xl px-4 text-sm font-semibold text-primary hover:underline disabled:opacity-60"
            :disabled="loadingMore"
            @click="page += 1; load(false)"
          >
            {{ loadingMore ? t('action_loading') : t('action_load_more') }}
          </button>
        </div>
      </div>
    </div>

    <LoadingModal :show="loading" />

    <Teleport to="body">
      <div
        v-if="showDateFilter"
        class="fixed inset-0 z-50 flex items-end justify-center bg-gray-950/40 sm:items-center sm:p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="t('label_date_filter')"
      >
        <button type="button" class="absolute inset-0 cursor-default" :aria-label="t('action_close')" @click="closeDateFilter" />
        <div class="relative z-10 w-full max-w-md overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
          <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-4">
            <h2 class="text-sm font-semibold text-gray-900">{{ t('label_date') }}</h2>
            <div class="flex items-center gap-1">
              <button
                type="button"
                class="rounded-lg px-2 py-1.5 text-xs font-semibold text-gray-500 hover:bg-gray-100 hover:text-primary"
                @click="clearDateFilter"
              >
                {{ t('action_clear') }}
              </button>
              <button
                type="button"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                :aria-label="t('action_close')"
                @click="closeDateFilter"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <div class="space-y-4 px-5 py-4 pb-[max(1rem,var(--rp-sab))]">
            <div>
              <p class="text-xs font-medium text-gray-500">{{ t('label_document_date') }}</p>
              <div class="mt-1.5 grid grid-cols-3 gap-1.5">
                <button
                  type="button"
                  class="min-h-9 rounded-lg border border-gray-200 px-1 text-xs font-medium text-gray-600 hover:bg-gray-50"
                  @click="setDatePreset('clear')"
                >
                  {{ t('filter_any') }}
                </button>
                <button
                  type="button"
                  class="min-h-9 rounded-lg border border-gray-200 px-1 text-xs font-medium text-gray-600 hover:bg-gray-50"
                  @click="setDatePreset('30d')"
                >
                  {{ t('filter_last_30_days') }}
                </button>
                <button
                  type="button"
                  class="min-h-9 rounded-lg border border-gray-200 px-1 text-xs font-medium text-gray-600 hover:bg-gray-50"
                  @click="setDatePreset('month')"
                >
                  {{ t('filter_this_month') }}
                </button>
              </div>
              <div class="mt-2 grid min-w-0 grid-cols-2 gap-2">
                <label class="block min-w-0">
                  <span class="sr-only">{{ t('aria_from_date') }}</span>
                  <input
                    v-model="draftDateFrom"
                    type="date"
                    class="min-h-10 w-full min-w-0 rounded-xl border border-gray-200 px-2 text-sm outline-none focus:border-primary"
                  />
                </label>
                <label class="block min-w-0">
                  <span class="sr-only">{{ t('aria_to_date') }}</span>
                  <input
                    v-model="draftDateTo"
                    type="date"
                    class="min-h-10 w-full min-w-0 rounded-xl border border-gray-200 px-2 text-sm outline-none focus:border-primary"
                  />
                </label>
              </div>
            </div>

            <button
              type="button"
              class="flex min-h-11 w-full items-center justify-center rounded-xl bg-primary text-sm font-semibold text-white hover:bg-blue-600"
              @click="applyDateFilter"
            >
              {{ t('action_apply') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="showCategoryFilter"
        class="fixed inset-0 z-50 flex items-end justify-center bg-gray-950/40 sm:items-center sm:p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="t('label_category_filter')"
      >
        <button type="button" class="absolute inset-0 cursor-default" :aria-label="t('action_close')" @click="closeCategoryFilter" />
        <div class="relative z-10 flex max-h-[min(28rem,80vh)] w-full max-w-md flex-col overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
          <div class="flex shrink-0 items-center justify-between gap-2 border-b border-gray-100 px-5 py-4">
            <h2 class="text-sm font-semibold text-gray-900">{{ t('label_category') }}</h2>
            <div class="flex items-center gap-1">
              <button
                v-if="categoryFilterActive"
                type="button"
                class="rounded-lg px-2 py-1.5 text-xs font-semibold text-gray-500 hover:bg-gray-100 hover:text-primary"
                @click="clearCategoryFilter"
              >
                {{ t('action_clear') }}
              </button>
              <button
                type="button"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                :aria-label="t('action_close')"
                @click="closeCategoryFilter"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <ul class="min-h-0 flex-1 overflow-y-auto px-2 py-2 pb-[max(0.5rem,var(--rp-sab))]">
            <li>
              <button
                type="button"
                class="flex min-h-11 w-full items-center rounded-xl px-3 text-left text-sm"
                :class="categoryId === '' ? 'bg-soft-blue font-semibold text-primary' : 'text-gray-600 hover:bg-gray-50'"
                @click="selectCategory('')"
              >
                {{ t('files_all_categories') }}
              </button>
            </li>
            <li v-for="cat in categories" :key="cat.id">
              <button
                type="button"
                class="flex min-h-11 w-full items-center rounded-xl px-3 text-left text-sm"
                :class="categoryId === cat.id ? 'bg-soft-blue font-semibold text-primary' : 'text-gray-600 hover:bg-gray-50'"
                @click="selectCategory(cat.id)"
              >
                <span class="truncate">{{ cat.name }}</span>
              </button>
            </li>
          </ul>
        </div>
      </div>
    </Teleport>
  </AppPage>
</template>
