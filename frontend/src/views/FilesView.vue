<script setup lang="ts">
import { computed, nextTick, onActivated, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import Swal from 'sweetalert2'
import api, { ensureCsrf } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import type { Category, DocumentItem } from '@/types'
import AppPage from '@/components/AppPage.vue'
import DocumentListRow from '@/components/DocumentListRow.vue'
import type { DocumentMenuAction } from '@/components/DocumentListRow.vue'
import EmptyState from '@/components/EmptyState.vue'
import LoadingModal from '@/components/LoadingModal.vue'
import { confirmAction, notify, promptCategoryChoice, promptMoveToProfile, showError, showSuccess } from '@/lib/confirm'
import { primary as documentPrimary } from '@/lib/documentDisplay'
import { canShareFiles, shareBlobFile } from '@/lib/shareFile'

defineOptions({ name: 'FilesView' })

const { t } = useI18n()

type SortKey = 'newest' | 'oldest' | 'name_asc' | 'name_desc'

type DateFacetMonth = {
  month: number
  total: number
  display_amount?: string | null
  display_amount_label?: string | null
}
type DateFacetYear = { year: number; total: number; months: DateFacetMonth[] }
type DateFacetsPayload = {
  years: DateFacetYear[]
  undated: number
  undated_preview?: {
    display_amount?: string | null
    display_amount_label?: string | null
  } | null
}

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const browseRouteNames = new Set([
  'files-browse',
  'files-year',
  'files-month',
  'files-undated',
])

const documents = ref<DocumentItem[]>([])
const categories = ref<Category[]>([])
const counts = ref({ all: 0, needs_review: 0 })
const q = ref('')
const appliedQ = ref('')
const status = ref('')
const categoryId = ref<number | ''>('')
const sort = ref<SortKey>('newest')
const dateFrom = ref('')
const dateTo = ref('')
const draftSort = ref<SortKey>('newest')
const draftDateFrom = ref('')
const draftDateTo = ref('')
const showFilters = ref(false)
const loading = ref(false)
const loadingMore = ref(false)
const page = ref(1)
const lastPage = ref(1)
const total = ref(0)
const showCategorySettings = ref(false)
const newCategoryName = ref('')
const savingCategory = ref(false)
const editingCategoryId = ref<number | null>(null)
const editingCategoryName = ref('')
const editingCategoryError = ref('')
const savingCategoryRename = ref(false)
const draggingCategoryId = ref<number | null>(null)
const categoryOrderDirty = ref(false)
const savingCategoryOrder = ref(false)
const editNameInput = ref<HTMLInputElement | null>(null)

function setEditNameInput(el: Element | null) {
  editNameInput.value = el instanceof HTMLInputElement ? el : null
}
const dateFacets = ref<DateFacetsPayload | null>(null)
const browseYear = ref<number | null>(null)
const browseMonth = ref<number | null>(null)
const browseUndated = ref(false)
const facetsLoading = ref(false)
const showAllMonths = ref(false)
const selectionMode = ref(false)
const selectedIds = ref<Set<number>>(new Set())
const bulkDeleting = ref(false)
const exporting = ref(false)

const filtersActive = computed(
  () => sort.value !== 'newest' || Boolean(dateFrom.value || dateTo.value),
)

/** When browsing a category month folder, date filters stay inside that month. */
const folderDateBounds = computed(() => {
  if (browseYear.value == null || browseMonth.value == null) {
    return null
  }
  const year = browseYear.value
  const month = browseMonth.value
  const min = `${year}-${String(month).padStart(2, '0')}-01`
  const max = toYmd(new Date(year, month, 0))
  return { min, max }
})

const selectedCount = computed(() => selectedIds.value.size)
const allVisibleSelected = computed(
  () => documents.value.length > 0 && documents.value.every((doc) => selectedIds.value.has(doc.id)),
)

const emptyCopy = computed(() => {
  if (appliedQ.value || filtersActive.value) {
    return {
      title: t('files_empty_match_title'),
      description: t('files_empty_match_desc'),
      capture: false,
    }
  }
  if (status.value === 'needs_review') {
    return {
      title: t('files_empty_review_title'),
      description: t('files_empty_review_desc'),
      capture: false,
    }
  }
  if (categoryId.value) {
    return {
      title: t('files_empty_category_title'),
      description: t('files_empty_category_desc'),
      capture: true,
    }
  }
  return {
    title: t('files_empty_title'),
    description: t('files_empty_desc'),
    capture: true,
  }
})

const hasMore = computed(() => page.value < lastPage.value)

/** On a category browse route (year grid, month, or undated) — not All / {{ t('needs_review_label') }}. */
const useCategoryBrowse = computed(() => {
  const name = String(route.name ?? '')
  return browseRouteNames.has(name) && Boolean(categoryId.value) && !status.value
})

const browseReady = computed(
  () => browseUndated.value || (browseYear.value != null && browseMonth.value != null),
)

const isFolderDetail = computed(() => useCategoryBrowse.value && browseReady.value)

const selectedCategoryName = computed(
  () => categories.value.find((c) => c.id === categoryId.value)?.name ?? t('label_category'),
)

const folderDetailTitle = computed(() => {
  if (browseUndated.value) {
    return t('label_undated')
  }
  if (browseMonth.value != null && browseYear.value != null) {
    return `${t(`month_${browseMonth.value}`)} ${browseYear.value}`
  }
  return selectedCategoryName.value
})

const shareAvailable = canShareFiles()

const categoryHasNoDocs = computed(() =>
  Boolean(dateFacets.value && !dateFacets.value.years.length && !dateFacets.value.undated),
)

/** Facet years, or a single fallback so empty categories keep the year control. */
const browseYearOptions = computed(() => {
  const years = dateFacets.value?.years.map((y) => y.year) ?? []
  if (years.length) {
    return years
  }
  if (categoryHasNoDocs.value) {
    return [browseYear.value ?? new Date().getFullYear()]
  }
  return []
})

/** Full Jan–Dec for the selected year; missing months count as 0. */
const monthFolders = computed(() => {
  const yearRow = dateFacets.value?.years.find((y) => y.year === browseYear.value)
  const byMonth = new Map((yearRow?.months ?? []).map((m) => [m.month, m]))
  return Array.from({ length: 12 }, (_, index) => {
    const month = index + 1
    const facet = byMonth.get(month)
    return {
      month,
      label: t(`month_${month}`),
      total: facet?.total ?? 0,
      displayAmount: facet?.display_amount ?? null,
      displayAmountLabel: facet?.display_amount_label ?? null,
    }
  })
})

const visibleMonthFolders = computed(() => {
  if (showAllMonths.value) {
    return monthFolders.value
  }
  return monthFolders.value.filter((folder) => folder.total > 0)
})

const hasEmptyMonths = computed(() => monthFolders.value.some((folder) => folder.total === 0))

function showStatusBadge(doc: DocumentItem): boolean {
  if (doc.status === 'filed') {
    return false
  }
  if (status.value === 'needs_review' && doc.status === 'needs_review') {
    return false
  }
  return true
}

function categoryLabelFor(doc: DocumentItem): string | null {
  if (categoryId.value) {
    return null
  }
  return doc.category?.name ?? doc.suggested_category_name ?? null
}

async function loadCategories() {
  const { data } = await api.get('/categories')
  categories.value = data.categories
  counts.value = data.counts ?? { all: 0, needs_review: 0 }
}

function clearBrowseState() {
  browseYear.value = null
  browseMonth.value = null
  browseUndated.value = false
  dateFacets.value = null
}

async function loadDateFacets(preferredYear?: number | null) {
  if (!categoryId.value) {
    dateFacets.value = null
    return null as number | null
  }
  facetsLoading.value = true
  try {
    const { data } = await api.get('/documents/date-facets', {
      params: { category_id: categoryId.value },
    })
    dateFacets.value = data
    const years: number[] = (data.years ?? []).map((y: DateFacetYear) => y.year)
    if (preferredYear != null && years.includes(preferredYear)) {
      return preferredYear
    }
    if (years.length) {
      return years[0]
    }
    // Empty category: keep year chrome (select above pills) instead of dropping it.
    if (!(data.undated > 0)) {
      return preferredYear ?? new Date().getFullYear()
    }
    return null
  } finally {
    facetsLoading.value = false
  }
}

function routeCategoryId(): number | null {
  const raw = route.params.categoryId
  const id = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isFinite(id) && id > 0 ? id : null
}

function routeYear(): number | null {
  const raw = route.params.year
  const year = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isFinite(year) && year > 0 ? year : null
}

function routeMonth(): number | null {
  const raw = route.params.month
  const month = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isFinite(month) && month >= 1 && month <= 12 ? month : null
}

async function syncFromRoute() {
  const name = String(route.name ?? '')

  if (name === 'file-detail') {
    return
  }

  // KeepAlive + nested file-detail leave pill/sidebar counts stale unless we refetch.
  await loadCategories()

  if (name === 'files' || name === '') {
    const statusQuery = route.query.status
    categoryId.value = ''
    clearBrowseState()
    if (statusQuery === 'needs_review') {
      status.value = 'needs_review'
    } else {
      status.value = ''
    }
    if (route.query.q != null && route.query.q !== '') {
      const qVal = String(Array.isArray(route.query.q) ? route.query.q[0] : route.query.q)
      q.value = qVal
      appliedQ.value = qVal
    }
    await loadDocuments(true)
    return
  }

  if (!browseRouteNames.has(name)) {
    return
  }

  const catId = routeCategoryId()
  if (catId == null) {
    await router.replace({ name: 'files' })
    return
  }

  status.value = ''
  appliedQ.value = ''
  q.value = ''
  categoryId.value = catId
  browseUndated.value = name === 'files-undated'
  browseMonth.value = name === 'files-month' ? routeMonth() : null

  const preferredYear = name === 'files-undated' ? null : routeYear()
  const resolvedYear = await loadDateFacets(preferredYear)

  if (name === 'files-browse') {
    if (resolvedYear != null) {
      await router.replace({
        name: 'files-year',
        params: { categoryId: String(catId), year: String(resolvedYear) },
      })
      return
    }
    browseYear.value = null
    await loadDocuments(true)
    return
  }

  if (name === 'files-undated') {
    browseYear.value = resolvedYear
    await loadDocuments(true)
    return
  }

  // loadDateFacets already drops preferredYear when this category has no docs that year.
  // Never keep the Home/URL year anyway — that left an empty month grid while
  // documents_count (delete dialog) still showed docs in other years.
  if (name === 'files-year') {
    if (resolvedYear != null && preferredYear !== resolvedYear) {
      await router.replace({
        name: 'files-year',
        params: { categoryId: String(catId), year: String(resolvedYear) },
      })
      return
    }
    if (resolvedYear == null) {
      await router.replace({
        name: 'files-browse',
        params: { categoryId: String(catId) },
      })
      return
    }
    browseYear.value = resolvedYear
    documents.value = []
    total.value = 0
    await loadDocuments(true)
    return
  }

  if (name === 'files-month') {
    if (resolvedYear != null && preferredYear !== resolvedYear) {
      await router.replace({
        name: 'files-year',
        params: { categoryId: String(catId), year: String(resolvedYear) },
      })
      return
    }
    browseYear.value = resolvedYear
    if (browseMonth.value == null || browseYear.value == null) {
      await router.replace({
        name: 'files-year',
        params: {
          categoryId: String(catId),
          year: String(browseYear.value ?? resolvedYear ?? new Date().getFullYear()),
        },
      })
      return
    }
    clearDatesOutsideFolder()
    await loadDocuments(true)
  }
}

async function loadDocuments(reset = true) {
  if (useCategoryBrowse.value && !browseReady.value) {
    documents.value = []
    total.value = 0
    lastPage.value = 1
    loading.value = false
    loadingMore.value = false
    return
  }

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
        status: status.value || undefined,
        category_id: categoryId.value || undefined,
        sort: sort.value,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
        year: useCategoryBrowse.value && !browseUndated.value ? browseYear.value ?? undefined : undefined,
        month: useCategoryBrowse.value && !browseUndated.value ? browseMonth.value ?? undefined : undefined,
        undated: useCategoryBrowse.value && browseUndated.value ? 1 : undefined,
        page: page.value,
      },
    })
    const paginator = data.documents
    const rows: DocumentItem[] = paginator.data ?? paginator
    documents.value = reset ? rows : [...documents.value, ...rows]
    lastPage.value = paginator.last_page ?? 1
    total.value = paginator.total ?? rows.length
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

function applySearch() {
  appliedQ.value = q.value.trim()
  if (isFolderDetail.value) {
    loadDocuments(true)
    return
  }
  if (categoryId.value && !status.value) {
    router.push({
      name: 'files',
      query: appliedQ.value ? { q: appliedQ.value } : {},
    })
    return
  }
  loadDocuments(true)
}

function clearSearch() {
  q.value = ''
  appliedQ.value = ''
  if (isFolderDetail.value) {
    loadDocuments(true)
    return
  }
  if (categoryId.value && !status.value && browseYear.value != null) {
    router.push({
      name: 'files-year',
      params: { categoryId: String(categoryId.value), year: String(browseYear.value) },
    })
    return
  }
  loadDocuments(true)
}

async function shareFolderDetail() {
  if (!shareAvailable) {
    notify({ title: t('msg_share_unavailable'), icon: 'info' })
    return
  }
  if (documents.value.length !== 1) {
    notify({
      title: documents.value.length ? t('msg_share_open_document') : t('msg_no_documents_to_share'),
      icon: 'info',
    })
    return
  }
  await shareDocument(documents.value[0])
}

async function shareDocument(doc: DocumentItem) {
  if (!shareAvailable) {
    notify({ title: t('msg_share_unavailable'), icon: 'info' })
    return
  }
  try {
    const { data } = await api.get(`/documents/${doc.id}/download`, { responseType: 'blob' })
    const shared = await shareBlobFile(
      data,
      doc.original_filename,
      doc.mime_type,
      documentPrimary(doc),
    )
    if (!shared) {
      notify({ title: t('msg_share_unavailable'), icon: 'info' })
    }
  } catch (e: any) {
    notify({ title: e?.response?.data?.message || t('msg_could_not_share'), icon: 'error' })
  }
}

async function exportAttachments() {
  if (browseYear.value == null || exporting.value) {
    return
  }

  exporting.value = true
  try {
    const { data } = await api.get('/documents/export', {
      params: { year: browseYear.value },
      responseType: 'blob',
    })
    const url = URL.createObjectURL(data)
    const a = window.document.createElement('a')
    a.href = url
    a.download = `attachments-${browseYear.value}.zip`
    a.click()
    URL.revokeObjectURL(url)
  } catch (e: any) {
    let message = t('msg_could_not_export')
    const payload = e?.response?.data
    if (payload instanceof Blob) {
      try {
        const parsed = JSON.parse(await payload.text())
        if (parsed?.message) {
          message = parsed.message
        }
      } catch {
        // keep default
      }
    } else if (payload?.message) {
      message = payload.message
    }
    notify({ title: message, icon: 'error' })
  } finally {
    exporting.value = false
  }
}

async function changeDocumentCategory(doc: DocumentItem) {
  const choice = await promptCategoryChoice({
    categories: categories.value,
    selectedId: doc.category_id || '',
    allowNew: true,
    title: doc.status === 'filed' ? t('title_change_category') : t('title_choose_category'),
  })
  if (!choice) return

  await ensureCsrf()
  try {
    if ('categoryId' in choice) {
      await api.post(`/documents/${doc.id}/confirm`, { category_id: choice.categoryId })
    } else {
      await api.post(`/documents/${doc.id}/confirm`, { new_category_name: choice.newCategoryName })
    }
    notify({ title: t('msg_category_updated') })
    await loadCategories()
    await loadDocuments(true)
    if (useCategoryBrowse.value) {
      await loadDateFacets()
    }
  } catch (e: any) {
    notify({ title: e?.response?.data?.message || t('msg_could_not_change_category'), icon: 'error' })
  }
}

async function moveDocumentProfile(doc: DocumentItem) {
  const currentProfileId = auth.activeProfile?.id ?? doc.profile_id
  const choice = await promptMoveToProfile({
    profiles: auth.profiles,
    currentProfileId,
  })
  if (!choice) return

  await ensureCsrf()
  try {
    const payload =
      'categoryId' in choice
        ? { profile_id: choice.profileId, category_id: choice.categoryId }
        : { profile_id: choice.profileId, new_category_name: choice.newCategoryName }
    await api.post(`/documents/${doc.id}/move`, payload)
    notify({ title: t('msg_document_moved') })
    await loadCategories()
    await loadDocuments(true)
    if (useCategoryBrowse.value) {
      await loadDateFacets()
    }
  } catch (e: any) {
    notify({ title: e?.response?.data?.message || t('msg_could_not_move'), icon: 'error' })
  }
}

function clearSelection() {
  selectionMode.value = false
  selectedIds.value = new Set()
}

function startSelection(docId: number) {
  // Long-press always selects (never toggles off) so release/contextmenu can't undo it.
  const next = new Set(selectedIds.value)
  next.add(docId)
  selectedIds.value = next
  selectionMode.value = true
}

function toggleSelection(docId: number) {
  const next = new Set(selectedIds.value)
  if (next.has(docId)) {
    next.delete(docId)
  } else {
    next.add(docId)
  }
  selectedIds.value = next
  selectionMode.value = next.size > 0
}

function selectAllVisible() {
  if (!documents.value.length) return
  selectedIds.value = new Set(documents.value.map((doc) => doc.id))
  selectionMode.value = true
}

async function deleteDocument(doc: DocumentItem) {
  const ok = await confirmAction({
    title: t('title_delete_document'),
    text: t('msg_delete_document_confirm'),
    confirmText: t('action_delete_document'),
  })
  if (!ok) return

  await ensureCsrf()
  try {
    await api.delete(`/documents/${doc.id}`)
    notify({ title: t('msg_document_deleted') })
    await loadCategories()
    await loadDocuments(true)
    if (useCategoryBrowse.value) {
      await loadDateFacets()
    }
  } catch (e: any) {
    notify({ title: e?.response?.data?.message || t('msg_could_not_delete_document'), icon: 'error' })
  }
}

async function deleteSelectedDocuments() {
  const ids = [...selectedIds.value]
  if (!ids.length || bulkDeleting.value) return

  const ok = await confirmAction({
    title: t('title_delete_documents', { count: ids.length }),
    text: t('msg_delete_documents_confirm', { count: ids.length }),
    confirmText: t('action_delete'),
  })
  if (!ok) return

  bulkDeleting.value = true
  await ensureCsrf()
  try {
    await Promise.all(ids.map((id) => api.delete(`/documents/${id}`)))
    clearSelection()
    notify({ title: t('msg_documents_deleted', { count: ids.length }) })
    await loadCategories()
    await loadDocuments(true)
    if (useCategoryBrowse.value) {
      await loadDateFacets()
    }
  } catch (e: any) {
    notify({ title: e?.response?.data?.message || t('msg_could_not_delete_document'), icon: 'error' })
    await loadDocuments(true)
  } finally {
    bulkDeleting.value = false
  }
}

async function onDocumentMenu(doc: DocumentItem, action: DocumentMenuAction) {
  if (action === 'share') {
    await shareDocument(doc)
    return
  }
  if (action === 'move') {
    await moveDocumentProfile(doc)
    return
  }
  if (action === 'change_category') {
    await changeDocumentCategory(doc)
    return
  }
  if (action === 'delete') {
    await deleteDocument(doc)
  }
}

function onBrowseYearSelect(event: Event) {
  const value = Number((event.target as HTMLSelectElement).value)
  if (!Number.isFinite(value) || !categoryId.value) {
    return
  }
  router.push({
    name: 'files-year',
    params: { categoryId: String(categoryId.value), year: String(value) },
  })
}

function selectMonth(month: number) {
  if (browseYear.value == null || !categoryId.value) {
    return
  }
  router.push({
    name: 'files-month',
    params: {
      categoryId: String(categoryId.value),
      year: String(browseYear.value),
      month: String(month),
    },
  })
}

function selectUndatedBrowse() {
  if (!categoryId.value) {
    return
  }
  router.push({
    name: 'files-undated',
    params: { categoryId: String(categoryId.value) },
  })
}

function breadcrumbToCategory() {
  if (!categoryId.value) {
    return
  }
  if (browseYear.value != null) {
    router.push({
      name: 'files-year',
      params: { categoryId: String(categoryId.value), year: String(browseYear.value) },
    })
    return
  }
  router.push({
    name: 'files-browse',
    params: { categoryId: String(categoryId.value) },
  })
}

function breadcrumbToYear() {
  clearSelection()
  breadcrumbToCategory()
}

function toYmd(date: Date): string {
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

function clampToFolderDate(value: string): string {
  const bounds = folderDateBounds.value
  if (!value || !bounds) {
    return value
  }
  if (value < bounds.min) {
    return bounds.min
  }
  if (value > bounds.max) {
    return bounds.max
  }
  return value
}

function clearDatesOutsideFolder() {
  const bounds = folderDateBounds.value
  if (!bounds) {
    return
  }
  const fromOut =
    Boolean(dateFrom.value) && (dateFrom.value < bounds.min || dateFrom.value > bounds.max)
  const toOut = Boolean(dateTo.value) && (dateTo.value < bounds.min || dateTo.value > bounds.max)
  if (fromOut || toOut) {
    dateFrom.value = ''
    dateTo.value = ''
  }
}

function openFilters() {
  draftSort.value = sort.value
  draftDateFrom.value = dateFrom.value
  draftDateTo.value = dateTo.value
  showFilters.value = true
}

function closeFilters() {
  showFilters.value = false
}

function applyFilters() {
  const from = clampToFolderDate(draftDateFrom.value)
  const to = clampToFolderDate(draftDateTo.value)
  if (from && to && from > to) {
    notify({ title: t('msg_date_range_invalid'), icon: 'warning' })
    return
  }

  sort.value = draftSort.value
  dateFrom.value = from
  dateTo.value = to
  draftDateFrom.value = from
  draftDateTo.value = to
  showFilters.value = false
  loadDocuments(true)
}

function clearFilters() {
  draftSort.value = 'newest'
  draftDateFrom.value = ''
  draftDateTo.value = ''
  sort.value = 'newest'
  dateFrom.value = ''
  dateTo.value = ''
  showFilters.value = false
  loadDocuments(true)
}

function setDatePreset(preset: 'month' | '30d' | 'clear') {
  if (preset === 'clear') {
    draftDateFrom.value = ''
    draftDateTo.value = ''
    return
  }

  const bounds = folderDateBounds.value
  if (preset === 'month' && bounds) {
    const today = toYmd(new Date())
    draftDateFrom.value = bounds.min
    draftDateTo.value = today >= bounds.min && today <= bounds.max ? today : bounds.max
    return
  }

  const today = new Date()
  const to = toYmd(today)

  if (preset === '30d') {
    const from = new Date(today)
    from.setDate(from.getDate() - 30)
    draftDateFrom.value = clampToFolderDate(toYmd(from))
    draftDateTo.value = clampToFolderDate(to)
    return
  }

  const from = new Date(today.getFullYear(), today.getMonth(), 1)
  draftDateFrom.value = clampToFolderDate(toYmd(from))
  draftDateTo.value = clampToFolderDate(to)
}

async function loadMore() {
  if (!hasMore.value || loadingMore.value) return
  page.value += 1
  await loadDocuments(false)
}

function categoryNameValidationError(name: string, excludeId?: number): string | null {
  const trimmed = name.trim()
  if (!trimmed) {
    return t('msg_enter_category_name')
  }
  if (trimmed.length > 100) {
    return t('msg_name_max_100')
  }
  const duplicate = categories.value.some(
    (c) => c.id !== excludeId && c.name.trim().toLowerCase() === trimmed.toLowerCase(),
  )
  if (duplicate) {
    return t('msg_category_name_exists')
  }
  return null
}

function clearCategoryRename() {
  editingCategoryId.value = null
  editingCategoryName.value = ''
  editingCategoryError.value = ''
  savingCategoryRename.value = false
}

function openCategorySettings() {
  newCategoryName.value = ''
  clearCategoryRename()
  showCategorySettings.value = true
}

function closeCategorySettings() {
  showCategorySettings.value = false
  newCategoryName.value = ''
  clearCategoryRename()
}

async function withCategorySettingsPaused<T>(run: () => Promise<T>): Promise<T> {
  const restore = showCategorySettings.value
  if (restore) {
    showCategorySettings.value = false
    await nextTick()
  }
  try {
    return await run()
  } finally {
    if (restore) {
      showCategorySettings.value = true
    }
  }
}

async function categorySuccess(title: string): Promise<void> {
  await withCategorySettingsPaused(() => showSuccess({ title }))
}

async function categoryError(title: string): Promise<void> {
  await withCategorySettingsPaused(() => showError({ title }))
}

async function startCategoryRename(cat: Category) {
  if (savingCategoryRename.value) {
    return
  }
  editingCategoryId.value = cat.id
  editingCategoryName.value = cat.name
  editingCategoryError.value = ''
  await nextTick()
  editNameInput.value?.focus()
  editNameInput.value?.select()
}

function cancelCategoryRename() {
  if (savingCategoryRename.value) {
    return
  }
  clearCategoryRename()
}

function moveCategoryBefore(fromId: number, toId: number) {
  if (fromId === toId) {
    return
  }
  const list = [...categories.value]
  const fromIndex = list.findIndex((c) => c.id === fromId)
  const toIndex = list.findIndex((c) => c.id === toId)
  if (fromIndex < 0 || toIndex < 0) {
    return
  }
  const [moved] = list.splice(fromIndex, 1)
  list.splice(toIndex, 0, moved)
  categories.value = list
  categoryOrderDirty.value = true
}

function onCategoryPointerDown(event: PointerEvent, cat: Category) {
  if (editingCategoryId.value != null || savingCategoryOrder.value || event.button !== 0) {
    return
  }
  event.preventDefault()
  draggingCategoryId.value = cat.id
  categoryOrderDirty.value = false
  ;(event.currentTarget as HTMLElement | null)?.setPointerCapture(event.pointerId)
}

function onCategoryPointerMove(event: PointerEvent) {
  const fromId = draggingCategoryId.value
  if (fromId == null) {
    return
  }
  const el = document.elementFromPoint(event.clientX, event.clientY)
  const row = el?.closest('[data-category-id]') as HTMLElement | null
  if (!row) {
    return
  }
  const toId = Number(row.dataset.categoryId)
  if (!Number.isFinite(toId) || toId === fromId) {
    return
  }
  moveCategoryBefore(fromId, toId)
}

async function onCategoryPointerUp(event: PointerEvent) {
  if (draggingCategoryId.value == null) {
    return
  }
  const handle = event.currentTarget as HTMLElement | null
  if (handle?.hasPointerCapture(event.pointerId)) {
    handle.releasePointerCapture(event.pointerId)
  }
  draggingCategoryId.value = null
  if (categoryOrderDirty.value) {
    categoryOrderDirty.value = false
    await persistCategoryOrder()
  }
}

async function persistCategoryOrder() {
  if (savingCategoryOrder.value) {
    return
  }
  savingCategoryOrder.value = true
  try {
    await ensureCsrf()
    const { data } = await api.patch('/categories/reorder', {
      ids: categories.value.map((c) => c.id),
    })
    if (Array.isArray(data.categories)) {
      categories.value = data.categories
    }
  } catch (e: any) {
    await loadCategories()
    await categoryError(e?.response?.data?.message || e?.response?.data?.errors?.ids?.[0] || t('msg_could_not_reorder_categories'))
  } finally {
    savingCategoryOrder.value = false
  }
}

async function createCategory() {
  const name = newCategoryName.value.trim()
  if (!name || savingCategory.value) return

  const validationError = categoryNameValidationError(name)
  if (validationError) {
    await categoryError(validationError)
    return
  }

  savingCategory.value = true
  try {
    await ensureCsrf()
    await api.post('/categories', { name })
    newCategoryName.value = ''
    await loadCategories()
    await categorySuccess(t('msg_category_created', { name }))
  } catch (e: any) {
    await categoryError(
      e?.response?.data?.message || e?.response?.data?.errors?.name?.[0] || t('msg_could_not_create_category'),
    )
  } finally {
    savingCategory.value = false
  }
}

async function saveCategoryRename() {
  const id = editingCategoryId.value
  if (id == null || savingCategoryRename.value) {
    return
  }

  const cat = categories.value.find((c) => c.id === id)
  if (!cat) {
    clearCategoryRename()
    return
  }

  const name = editingCategoryName.value.trim()
  const validationError = categoryNameValidationError(editingCategoryName.value, id)
  if (validationError) {
    editingCategoryError.value = validationError
    return
  }

  if (name === cat.name) {
    clearCategoryRename()
    return
  }

  savingCategoryRename.value = true
  editingCategoryError.value = ''
  try {
    await ensureCsrf()
    await api.patch(`/categories/${cat.id}`, { name })
    clearCategoryRename()
    await loadCategories()
    await categorySuccess(t('msg_category_renamed'))
  } catch (e: any) {
    editingCategoryError.value =
      e?.response?.data?.message || e?.response?.data?.errors?.name?.[0] || t('msg_could_not_rename_category')
  } finally {
    savingCategoryRename.value = false
  }
}

async function deleteCategory(cat: Category) {
  const docCount = cat.documents_count ?? 0
  let reassignTo: number | undefined

  await withCategorySettingsPaused(async () => {
    if (docCount > 0) {
      const others = categories.value.filter((c) => c.id !== cat.id)
      const optionsHtml = others
        .map((c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`)
        .join('')

      const result = await Swal.fire({
        buttonsStyling: false,
        reverseButtons: true,
        focusCancel: true,
        icon: 'warning',
        title: t('title_delete_category_named', { name: cat.name }),
        html: `
          <p class="text-left text-sm text-gray-600">
            ${escapeHtml(t(docCount === 1 ? 'msg_delete_category_has_doc' : 'msg_delete_category_has_docs', { count: docCount }))}
          </p>
          <label class="rp-swal-field mt-3">
            <span>${escapeHtml(t('label_reassign_to'))}</span>
            <select id="rp-reassign-category" class="rp-swal-input">
              <option value="">${escapeHtml(t('label_leave_uncategorised'))}</option>
              ${optionsHtml}
            </select>
          </label>
        `,
        showCancelButton: true,
        confirmButtonText: t('action_delete_category'),
        cancelButtonText: t('action_cancel'),
        customClass: {
          popup: 'rp-swal-popup',
          title: 'rp-swal-title',
          htmlContainer: 'rp-swal-html',
          actions: 'rp-swal-actions',
          confirmButton: 'rp-swal-confirm rp-swal-confirm-danger',
          cancelButton: 'rp-swal-cancel',
        },
        preConfirm: () => {
          const select = document.getElementById('rp-reassign-category') as HTMLSelectElement | null
          return select?.value || ''
        },
      })

      if (!result.isConfirmed) {
        return
      }
      if (result.value) {
        reassignTo = Number(result.value)
      }
    } else {
      const ok = await confirmAction({
        title: t('title_delete_category_named', { name: cat.name }),
        text: t('msg_delete_category_empty'),
        confirmText: t('action_delete_category'),
      })
      if (!ok) {
        return
      }
    }

    try {
      await ensureCsrf()
      await api.delete(`/categories/${cat.id}`, {
        data: reassignTo ? { reassign_to_category_id: reassignTo } : {},
      })
      if (categoryId.value === cat.id) {
        await router.push({ name: 'files' })
      }
      await loadCategories()
      if (categoryId.value !== cat.id) {
        await loadDocuments(true)
      }
      await showSuccess({ title: t('msg_category_deleted') })
    } catch (e: any) {
      await showError({ title: e?.response?.data?.message || t('msg_could_not_delete_category') })
    }
  })
}

function escapeHtml(value: string): string {
  return value
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
}

function selectAll() {
  router.push({ name: 'files' })
}

function selectNeedsReview() {
  router.push({ name: 'files', query: { status: 'needs_review' } })
}

function selectCategory(id: number) {
  router.push({ name: 'files-browse', params: { categoryId: String(id) } })
}

// KeepAlive: refetch whenever Files is shown (after Capture confirm, etc.)
onActivated(async () => {
  await syncFromRoute()
})

watch(
  () => auth.activeProfile?.id,
  async () => {
    sort.value = 'newest'
    dateFrom.value = ''
    dateTo.value = ''
    showFilters.value = false
    if (String(route.name).startsWith('files-') || route.name === 'file-detail') {
      await router.push({ name: 'files' })
      return
    }
    await syncFromRoute()
  },
)

watch(
  () => auth.activeProfile?.base_currency,
  async (currency, previous) => {
    if (previous === undefined || currency === previous) {
      return
    }
    if (categoryId.value !== '') {
      await loadDateFacets(browseYear.value)
    }
  },
)

watch(
  () => route.fullPath,
  async () => {
    if (!String(route.path).startsWith('/files')) {
      return
    }
    clearSelection()
    await syncFromRoute()
  },
)
</script>

<template>
  <AppPage width="fluid" fill>
    <div class="flex min-h-0 flex-1 flex-col gap-5 overflow-hidden">
      <div class="grid min-h-0 flex-1 gap-5 overflow-hidden lg:grid-cols-[260px_minmax(0,1fr)] lg:gap-6">
        <!-- Desktop sidebar -->
        <aside class="hidden max-h-full flex-col self-start overflow-hidden rounded-2xl border border-gray-100 bg-white p-3 lg:flex">
          <nav class="flex flex-col gap-0.5">
            <button
              type="button"
              class="flex min-h-10 w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm leading-5"
              :class="categoryId === '' && status === '' ? 'bg-soft-blue font-semibold text-primary' : 'text-gray-600 hover:bg-gray-50'"
              @click="selectAll"
            >
              <span class="inline-flex min-w-0 items-center gap-2">
                <svg class="h-4 w-4 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                </svg>
                {{ t('files_all_documents') }}
              </span>
              <span class="tabular-nums text-xs text-gray-400">{{ counts.all }}</span>
            </button>
            <button
              type="button"
              class="flex min-h-10 w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm leading-5"
              :class="status === 'needs_review' ? 'bg-amber-50 font-semibold text-amber-700' : 'text-gray-600 hover:bg-gray-50'"
              @click="selectNeedsReview"
            >
              <span class="inline-flex min-w-0 items-center gap-2">
                <svg class="h-4 w-4 shrink-0 opacity-70" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                </svg>
                {{ t('needs_review_label') }}
              </span>
              <span class="tabular-nums text-xs text-gray-400">{{ counts.needs_review }}</span>
            </button>
          </nav>

          <div class="mt-3 flex items-center justify-between pt-3">
            <h2 class="text-xs font-semibold uppercase tracking-wide text-gray-400">{{ t('label_categories') }}</h2>
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-primary"
              :title="t('tooltip_category_settings')"
              :aria-label="t('tooltip_category_settings')"
              @click="openCategorySettings"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </button>
          </div>

          <ul class="mt-1 max-h-[min(24rem,50vh)] space-y-0.5 overflow-y-auto">
            <li v-for="cat in categories" :key="cat.id">
              <button
                type="button"
                class="flex w-full items-center justify-between gap-2 rounded-xl px-3 py-2 text-left text-sm"
                :class="categoryId === cat.id ? 'bg-soft-blue font-semibold text-primary' : 'text-gray-600 hover:bg-gray-50'"
                @click="selectCategory(cat.id)"
              >
                <span class="truncate">{{ cat.name }}</span>
                <span class="shrink-0 tabular-nums text-xs text-gray-400">{{ cat.documents_count ?? 0 }}</span>
              </button>
            </li>
          </ul>
        </aside>

        <!-- Workspace -->
        <section class="flex min-h-0 min-w-0 flex-col gap-3 overflow-y-auto">
          <template v-if="!useCategoryBrowse">
            <div class="flex shrink-0 items-center gap-2">
              <label class="relative min-w-0 flex-1">
                <span class="sr-only">{{ t('aria_search_documents') }}</span>
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
                  v-model="q"
                  type="search"
                  :placeholder="t('hint_search')"
                  class="min-h-11 w-full rounded-xl border border-gray-200 bg-white py-2.5 pl-10 pr-10 text-sm outline-none focus:border-primary"
                  @keyup.enter="applySearch"
                />
                <button
                  v-if="q || appliedQ"
                  type="button"
                  class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-semibold text-gray-500 hover:bg-gray-100"
                  @click="clearSearch"
                >
                  {{ t('action_clear') }}
                </button>
              </label>

              <button
                type="button"
                class="relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border bg-white text-gray-600 hover:bg-gray-50"
                :class="filtersActive || showFilters ? 'border-primary text-primary' : 'border-gray-200'"
                :title="t('label_filters')"
                :aria-label="t('label_filters')"
                :aria-expanded="showFilters"
                @click="openFilters"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 4h18l-7 8v5l-4 2v-7L3 4z" />
                </svg>
                <span
                  v-if="filtersActive"
                  class="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary"
                  aria-hidden="true"
                />
              </button>
            </div>

          </template>

          <!-- Mobile year + category pills (not sticky during multi-select) -->
          <div
            v-if="!isFolderDetail"
            class="shrink-0 space-y-3 bg-background pb-3 lg:contents"
            :class="selectionMode ? '' : 'sticky top-0 z-20'"
          >
            <div
              v-if="useCategoryBrowse && !browseReady && browseYearOptions.length"
              class="flex shrink-0 items-center gap-2 lg:hidden"
            >
              <label class="min-w-0 flex-1">
                <span class="sr-only">{{ t('label_year') }}</span>
                <select
                  class="min-h-11 w-full cursor-pointer rounded-xl border border-gray-200 bg-white px-3 text-center text-sm font-semibold text-gray-900 outline-none [text-align-last:center] focus:border-primary"
                  :value="browseYear ?? ''"
                  @change="onBrowseYearSelect"
                >
                  <option v-for="year in browseYearOptions" :key="year" :value="year">{{ year }}</option>
                </select>
              </label>
              <button
                type="button"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-60"
                :disabled="exporting || browseYear == null || categoryHasNoDocs"
                :title="exporting ? t('action_exporting') : t('action_export')"
                :aria-label="exporting ? t('action_exporting') : t('action_export')"
                @click="exportAttachments"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                </svg>
              </button>
            </div>

            <div class="flex shrink-0 items-stretch gap-2 lg:hidden">
              <div class="min-w-0 flex-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                <div class="flex w-max items-stretch gap-2 pr-1">
                  <button
                    type="button"
                    class="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-xl border px-3 text-sm font-medium"
                    :class="categoryId === '' && status === ''
                      ? 'border-primary bg-soft-blue text-primary'
                      : 'border-gray-200 bg-white text-gray-600'"
                    @click="selectAll"
                  >
                    {{ t('label_all') }}
                    <span class="tabular-nums text-xs opacity-70">{{ counts.all }}</span>
                  </button>
                  <button
                    type="button"
                    class="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-xl border px-3 text-sm font-medium"
                    :class="status === 'needs_review'
                      ? 'border-amber-300 bg-amber-50 text-amber-700'
                      : 'border-gray-200 bg-white text-gray-600'"
                    @click="selectNeedsReview"
                  >
                    {{ t('needs_review_label') }}
                    <span class="tabular-nums text-xs opacity-70">{{ counts.needs_review }}</span>
                  </button>
                  <button
                    v-for="cat in categories"
                    :key="cat.id"
                    type="button"
                    class="inline-flex h-11 shrink-0 items-center gap-1.5 rounded-xl border px-3 text-sm font-medium"
                    :class="categoryId === cat.id
                      ? 'border-primary bg-soft-blue text-primary'
                      : 'border-gray-200 bg-white text-gray-600'"
                    @click="selectCategory(cat.id)"
                  >
                    {{ cat.name }}
                    <span class="tabular-nums text-xs opacity-70">{{ cat.documents_count ?? 0 }}</span>
                  </button>
                </div>
              </div>
              <button
                type="button"
                class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 hover:bg-gray-50"
                :title="t('tooltip_category_settings')"
                :aria-label="t('tooltip_category_settings')"
                @click="openCategorySettings"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Category folder grid -->
          <div v-if="useCategoryBrowse && !browseReady" class="flex min-h-0 flex-1 flex-col">
            <p v-if="facetsLoading" class="py-3 text-sm text-gray-500">{{ t('files_loading_months') }}</p>
            <template v-else-if="dateFacets">
              <div
                v-if="browseYearOptions.length && !categoryHasNoDocs"
                class="mb-3 hidden shrink-0 items-center gap-2 lg:flex"
              >
                <label>
                  <span class="sr-only">{{ t('label_year') }}</span>
                  <select
                    class="min-h-10 cursor-pointer rounded-xl border border-gray-200 bg-white px-3 text-sm font-semibold text-gray-900 outline-none focus:border-primary"
                    :value="browseYear ?? ''"
                    @change="onBrowseYearSelect"
                  >
                    <option v-for="year in browseYearOptions" :key="year" :value="year">{{ year }}</option>
                  </select>
                </label>
                <button
                  type="button"
                  class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border border-gray-200 bg-white text-gray-600 hover:bg-gray-50 disabled:opacity-60"
                  :disabled="exporting || browseYear == null"
                  :title="exporting ? t('action_exporting') : t('action_export')"
                  :aria-label="exporting ? t('action_exporting') : t('action_export')"
                  @click="exportAttachments"
                >
                  <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
                  </svg>
                </button>
              </div>

              <EmptyState
                v-if="categoryHasNoDocs"
                icon="document"
                :title="t('files_empty_category_title')"
                :description="t('files_empty_category_desc')"
              >
                <RouterLink to="/capture" class="text-sm font-semibold text-primary hover:underline">
                  {{ t('action_go_to_capture') }}
                </RouterLink>
              </EmptyState>

              <div
                v-else-if="browseYear != null && visibleMonthFolders.length"
                class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-3.5 lg:grid-cols-4"
              >
                <button
                  v-for="folder in visibleMonthFolders"
                  :key="folder.month"
                  type="button"
                  class="group flex flex-col rounded-2xl p-3.5 transition-all duration-200 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:p-4"
                  :class="folder.total > 0
                    ? 'border border-gray-100 bg-white text-left shadow-sm hover:-translate-y-0.5 hover:border-primary/30 hover:bg-soft-blue hover:shadow-md'
                    : 'cursor-default border border-dashed border-gray-200 bg-gray-50/80'"
                  :disabled="folder.total === 0"
                  @click="selectMonth(folder.month)"
                >
                  <template v-if="folder.total > 0">
                    <div class="flex items-center justify-between gap-2">
                      <span class="min-w-0 truncate text-base font-bold leading-snug text-gray-900">{{ folder.label }}</span>
                      <svg
                        class="h-3.5 w-3.5 shrink-0 text-gray-300 transition-colors group-hover:text-primary"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                        aria-hidden="true"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                      </svg>
                    </div>
                    <div class="mt-3 flex min-w-0 flex-col gap-1">
                      <template v-if="folder.displayAmount">
                        <span class="truncate text-lg font-bold tabular-nums tracking-tight text-primary">
                          {{ folder.displayAmount }}
                        </span>
                        <span class="text-xs font-medium tabular-nums text-gray-400">
                          {{ t(folder.total === 1 ? 'files_count_document' : 'files_count_documents', { count: folder.total }) }}
                        </span>
                      </template>
                      <span
                        v-else
                        class="truncate text-lg font-bold tabular-nums tracking-tight text-primary"
                      >
                        {{ t(folder.total === 1 ? 'files_count_doc' : 'files_count_docs', { count: folder.total }) }}
                      </span>
                    </div>
                  </template>
                  <template v-else>
                    <div class="flex flex-col items-center justify-center gap-1.5 py-2 text-center">
                      <span class="text-sm font-semibold text-gray-500">{{ folder.label }}</span>
                      <span class="text-[11px] font-medium text-gray-400">{{ t('label_empty') }}</span>
                    </div>
                  </template>
                </button>
              </div>

              <div
                v-if="dateFacets.undated > 0"
                class="mt-3.5 grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-3.5 lg:grid-cols-4"
              >
                <button
                  type="button"
                  class="group flex flex-col rounded-2xl border border-gray-100 bg-white p-3.5 text-left shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/30 hover:bg-soft-blue hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary sm:p-4"
                  @click="selectUndatedBrowse"
                >
                  <div class="flex items-center justify-between gap-2">
                    <div class="flex min-w-0 items-center gap-2">
                      <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-lg bg-soft-blue text-primary ring-1 ring-primary/10 transition-colors group-hover:bg-white"
                        aria-hidden="true"
                      >
                        <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                        </svg>
                      </span>
                      <span class="text-[13px] font-bold leading-snug text-gray-900">{{ t('label_undated') }}</span>
                    </div>
                    <svg
                      class="h-3.5 w-3.5 shrink-0 text-gray-300 transition-colors group-hover:text-primary"
                      fill="none"
                      viewBox="0 0 24 24"
                      stroke="currentColor"
                      aria-hidden="true"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                  </div>

                  <div class="mt-3 flex min-w-0 flex-col gap-1">
                    <template v-if="dateFacets.undated_preview?.display_amount">
                      <span class="truncate text-lg font-bold tabular-nums tracking-tight text-primary">
                        {{ dateFacets.undated_preview.display_amount }}
                      </span>
                    </template>
                    <template v-else>
                      <span class="text-2xl font-bold tabular-nums tracking-tight text-gray-900">
                        {{ dateFacets.undated }}
                      </span>
                    </template>
                    <span class="text-xs font-medium tabular-nums text-gray-400">
                      {{ t(dateFacets.undated === 1 ? 'files_count_document' : 'files_count_documents', { count: dateFacets.undated }) }}
                    </span>
                  </div>
                </button>
              </div>

              <button
                v-if="!categoryHasNoDocs && hasEmptyMonths"
                type="button"
                class="mt-3 block w-full text-center text-xs font-semibold text-primary hover:underline"
                @click="showAllMonths = !showAllMonths"
              >
                {{ showAllMonths ? t('files_show_months_with_docs') : t('files_show_all_months') }}
              </button>
            </template>
          </div>

          <!-- Category month / undated document list -->
          <div
            v-else-if="isFolderDetail"
            class="relative flex min-h-0 flex-1 flex-col"
          >
            <div class="flex items-center gap-2">
              <button
                type="button"
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-primary hover:bg-soft-blue"
                :aria-label="t('aria_back_to_months')"
                @click="breadcrumbToYear"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 19l-7-7 7-7" />
                </svg>
              </button>
              <div class="min-w-0 flex-1 text-center">
                <h2 class="truncate text-base font-bold text-primary">
                  {{ folderDetailTitle }}
                </h2>
                <p class="truncate text-xs font-semibold text-gray-500">{{ selectedCategoryName }}</p>
              </div>
              <button
                type="button"
                class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-primary hover:bg-soft-blue disabled:opacity-40"
                :aria-label="t('aria_share_document')"
                :disabled="!documents.length || selectionMode"
                @click="shareFolderDetail"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
                </svg>
              </button>
            </div>

            <div class="mt-3 flex items-center gap-2">
              <label class="relative min-w-0 flex-1">
                <span class="sr-only">{{ t('hint_search_name') }}</span>
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
                  v-model="q"
                  type="search"
                  :placeholder="t('hint_search_name')"
                  class="min-h-11 w-full rounded-xl border border-gray-200 bg-white py-2.5 pl-10 pr-10 text-sm outline-none focus:border-primary"
                  @keyup.enter="applySearch"
                />
                <button
                  v-if="q || appliedQ"
                  type="button"
                  class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg px-2 py-1 text-xs font-semibold text-gray-500 hover:bg-gray-100"
                  @click="clearSearch"
                >
                  {{ t('action_clear') }}
                </button>
              </label>

              <button
                type="button"
                class="relative inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border bg-white text-gray-600 hover:bg-gray-50"
                :class="filtersActive || showFilters ? 'border-primary text-primary' : 'border-gray-200'"
                :title="t('label_date_filters')"
                :aria-label="t('label_date_filters')"
                :aria-expanded="showFilters"
                @click="openFilters"
              >
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <span
                  v-if="filtersActive"
                  class="absolute right-2 top-2 h-2 w-2 rounded-full bg-primary"
                  aria-hidden="true"
                />
              </button>
            </div>

            <div
              v-if="selectionMode"
              class="mt-3 shrink-0 grid grid-cols-3 items-center gap-1 rounded-xl border border-gray-100 bg-white px-1 py-1.5"
            >
              <button
                type="button"
                class="min-h-10 rounded-lg px-2 text-sm font-semibold text-gray-600 hover:bg-gray-50"
                @click="clearSelection"
              >
                {{ t('action_clear') }}
              </button>
              <p class="truncate text-center text-sm font-bold text-gray-900">
                {{ t('label_n_selected', { count: selectedCount }) }}
              </p>
              <button
                type="button"
                class="min-h-10 rounded-lg px-2 text-sm font-semibold text-primary hover:bg-soft-blue disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="allVisibleSelected"
                @click="selectAllVisible"
              >
                {{ t('action_select_all') }}
              </button>
            </div>

            <div class="mt-3 flex min-h-0 flex-1 flex-col">
              <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <EmptyState
                  v-if="!loading && !documents.length"
                  icon="document"
                  :title="appliedQ || filtersActive ? t('files_empty_match_title') : t('files_empty_folder_title')"
                  :description="appliedQ || filtersActive ? t('files_empty_folder_match_desc') : t('files_empty_folder_desc')"
                />
                <ul v-else-if="documents.length" class="space-y-3 pb-1">
                  <li v-for="doc in documents" :key="doc.id">
                    <DocumentListRow
                      :doc="doc"
                      variant="card"
                      :show-status="showStatusBadge(doc)"
                      selectable
                      :selection-mode="selectionMode"
                      :is-selected="selectedIds.has(doc.id)"
                      @menu="(action) => onDocumentMenu(doc, action)"
                      @longpress="startSelection(doc.id)"
                      @toggle="toggleSelection(doc.id)"
                    />
                  </li>
                </ul>
                <div v-if="hasMore" class="pb-1 text-center">
                  <button
                    type="button"
                    class="min-h-10 rounded-xl px-4 text-sm font-semibold text-primary hover:underline disabled:opacity-60"
                    :disabled="loadingMore || selectionMode"
                    @click="loadMore"
                  >
                    {{ loadingMore ? t('action_loading') : t('action_load_more') }}
                  </button>
                </div>
              </div>

              <div
                v-if="selectionMode"
                class="shrink-0 bg-background pt-3 pb-1"
              >
                <button
                  type="button"
                  class="flex min-h-11 w-full items-center justify-center rounded-xl bg-red-600 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="selectedCount === 0 || bulkDeleting"
                  @click="deleteSelectedDocuments"
                >
                  {{ bulkDeleting ? t('action_loading') : t('action_delete_selected', { count: selectedCount }) }}
                </button>
              </div>
            </div>
          </div>

          <!-- {{ t('files_all_documents') }} / {{ t('needs_review_label') }} / search results -->
          <div
            v-else
            class="flex min-h-0 flex-1 flex-col"
          >
            <div
              v-if="selectionMode"
              class="mb-3 shrink-0 grid grid-cols-3 items-center gap-1 rounded-xl border border-gray-100 bg-white px-1 py-1.5"
            >
              <button
                type="button"
                class="min-h-10 rounded-lg px-2 text-sm font-semibold text-gray-600 hover:bg-gray-50"
                @click="clearSelection"
              >
                {{ t('action_clear') }}
              </button>
              <p class="truncate text-center text-sm font-bold text-gray-900">
                {{ t('label_n_selected', { count: selectedCount }) }}
              </p>
              <button
                type="button"
                class="min-h-10 rounded-lg px-2 text-sm font-semibold text-primary hover:bg-soft-blue disabled:cursor-not-allowed disabled:opacity-40"
                :disabled="allVisibleSelected"
                @click="selectAllVisible"
              >
                {{ t('action_select_all') }}
              </button>
            </div>

            <div class="flex min-h-0 flex-1 flex-col">
              <div class="flex min-h-0 flex-1 flex-col overflow-y-auto">
                <EmptyState
                  v-if="!loading && !documents.length"
                  icon="document"
                  :title="emptyCopy.title"
                  :description="emptyCopy.description"
                >
                  <RouterLink
                    v-if="emptyCopy.capture"
                    to="/capture"
                    class="text-sm font-semibold text-primary hover:underline"
                  >
                    {{ t('action_go_to_capture') }}
                  </RouterLink>
                  <button
                    v-else-if="appliedQ || filtersActive"
                    type="button"
                    class="text-sm font-semibold text-primary hover:underline"
                    @click="q = ''; appliedQ = ''; clearFilters()"
                  >
                    {{ t('action_clear_filters') }}
                  </button>
                </EmptyState>
                <ul v-else-if="documents.length" class="space-y-3 pb-1">
                  <li v-for="doc in documents" :key="doc.id">
                    <DocumentListRow
                      :doc="doc"
                      variant="card"
                      :show-status="showStatusBadge(doc)"
                      :category-label="categoryLabelFor(doc)"
                      selectable
                      :selection-mode="selectionMode"
                      :is-selected="selectedIds.has(doc.id)"
                      @menu="(action) => onDocumentMenu(doc, action)"
                      @longpress="startSelection(doc.id)"
                      @toggle="toggleSelection(doc.id)"
                    />
                  </li>
                </ul>
                <div v-if="hasMore" class="mt-2 pb-1 text-center">
                  <button
                    type="button"
                    class="min-h-10 rounded-xl px-4 text-sm font-semibold text-primary hover:underline disabled:opacity-60"
                    :disabled="loadingMore || selectionMode"
                    @click="loadMore"
                  >
                    {{ loadingMore ? t('action_loading') : t('action_load_more') }}
                  </button>
                </div>
              </div>

              <div
                v-if="selectionMode"
                class="shrink-0 bg-background pt-3 pb-1"
              >
                <button
                  type="button"
                  class="flex min-h-11 w-full items-center justify-center rounded-xl bg-red-600 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="selectedCount === 0 || bulkDeleting"
                  @click="deleteSelectedDocuments"
                >
                  {{ bulkDeleting ? t('action_loading') : t('action_delete_selected', { count: selectedCount }) }}
                </button>
              </div>
            </div>
          </div>
        </section>
      </div>
    </div>

    <LoadingModal :show="loading" />

    <RouterView />

    <Teleport to="body">
      <div
        v-if="showFilters"
        class="fixed inset-0 z-50 flex items-end justify-center bg-gray-950/40 sm:items-center sm:p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="t('label_filters')"
      >
        <button type="button" class="absolute inset-0 cursor-default" :aria-label="t('action_close')" @click="closeFilters" />
        <div class="relative z-10 w-full max-w-md overflow-hidden rounded-t-2xl bg-white shadow-xl sm:rounded-2xl">
          <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-4">
            <h2 class="text-sm font-semibold text-gray-900">{{ t('label_filters') }}</h2>
            <div class="flex items-center gap-1">
              <button
                type="button"
                class="rounded-lg px-2 py-1.5 text-xs font-semibold text-gray-500 hover:bg-gray-100 hover:text-primary"
                @click="clearFilters"
              >
                {{ t('action_clear') }}
              </button>
              <button
                type="button"
                class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                :aria-label="t('action_close')"
                @click="closeFilters"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <div class="space-y-4 px-5 py-4 pb-[max(1rem,var(--rp-sab))]">
            <div>
              <label class="block text-xs font-medium text-gray-500">{{ t('label_sort') }}</label>
              <select
                v-model="draftSort"
                class="mt-1.5 min-h-10 w-full cursor-pointer rounded-xl border border-gray-200 bg-white px-3 text-sm text-gray-700 outline-none focus:border-primary"
              >
                <option value="newest">{{ t('sort_newest') }}</option>
                <option value="oldest">{{ t('sort_oldest') }}</option>
                <option value="name_asc">{{ t('sort_name_asc') }}</option>
                <option value="name_desc">{{ t('sort_name_desc') }}</option>
              </select>
            </div>

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
              <div class="mt-2 grid grid-cols-2 gap-2">
                <label class="block">
                  <span class="sr-only">{{ t('aria_from_date') }}</span>
                  <input
                    v-model="draftDateFrom"
                    type="date"
                    :min="folderDateBounds?.min"
                    :max="folderDateBounds?.max"
                    class="min-h-10 w-full rounded-xl border border-gray-200 px-2 text-sm outline-none focus:border-primary"
                  />
                </label>
                <label class="block">
                  <span class="sr-only">{{ t('aria_to_date') }}</span>
                  <input
                    v-model="draftDateTo"
                    type="date"
                    :min="folderDateBounds?.min"
                    :max="folderDateBounds?.max"
                    class="min-h-10 w-full rounded-xl border border-gray-200 px-2 text-sm outline-none focus:border-primary"
                  />
                </label>
              </div>
            </div>

            <button
              type="button"
              class="flex min-h-11 w-full items-center justify-center rounded-xl bg-primary text-sm font-semibold text-white hover:bg-blue-600"
              @click="applyFilters"
            >
              {{ t('action_apply') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="showCategorySettings"
        class="fixed inset-0 z-50 flex items-center justify-center bg-gray-950/40 p-4"
        role="dialog"
        aria-modal="true"
        :aria-label="t('category_settings_title')"
      >
        <button type="button" class="absolute inset-0 cursor-default" :aria-label="t('aria_close_settings')" @click="closeCategorySettings" />
        <div class="relative z-10 flex max-h-[calc(100dvh-2rem)] min-h-80 w-full max-w-md flex-col overflow-hidden rounded-2xl bg-white shadow-xl">
          <div class="flex shrink-0 items-center justify-between border-b border-gray-100 px-5 py-4">
            <div>
              <h2 class="text-sm font-semibold text-gray-900">{{ t('category_settings_title') }}</h2>
              <p class="mt-0.5 text-xs text-gray-500">{{ t('category_settings_desc') }}</p>
            </div>
            <button
              type="button"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-400 hover:bg-gray-100 hover:text-gray-700"
              :aria-label="t('action_close')"
              @click="closeCategorySettings"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="shrink-0 border-b border-gray-100 px-5 py-4">
            <label class="block text-xs font-medium text-gray-500">{{ t('label_new_category') }}</label>
            <div class="mt-2 flex gap-2">
              <input
                v-model="newCategoryName"
                class="min-h-10 min-w-0 flex-1 rounded-xl border border-gray-200 px-3 text-sm outline-none focus:border-primary"
                :placeholder="t('placeholder_name')"
                maxlength="100"
                @keyup.enter="createCategory"
              />
              <button
                type="button"
                class="inline-flex min-h-10 shrink-0 items-center justify-center rounded-xl bg-primary px-4 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-60"
                :disabled="savingCategory || !newCategoryName.trim()"
                @click="createCategory"
              >
                {{ t('action_add') }}
              </button>
            </div>
          </div>

          <ul class="min-h-0 flex-1 divide-y divide-gray-50 overflow-y-auto px-2 py-1">
          <li
            v-for="cat in categories"
            :key="cat.id"
            class="flex items-center gap-1 px-3 py-3"
            :class="{ 'opacity-60 bg-soft-blue/40': draggingCategoryId === cat.id }"
            :data-category-id="cat.id"
          >
            <button
              v-if="editingCategoryId !== cat.id"
              type="button"
              class="inline-flex h-9 w-7 shrink-0 cursor-grab touch-none items-center justify-center rounded-lg text-gray-300 hover:bg-gray-100 hover:text-gray-500 active:cursor-grabbing disabled:opacity-60"
              :aria-label="t('aria_reorder_category')"
              :disabled="savingCategoryOrder"
              @pointerdown="onCategoryPointerDown($event, cat)"
              @pointermove="onCategoryPointerMove"
              @pointerup="onCategoryPointerUp"
              @pointercancel="onCategoryPointerUp"
            >
              <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <circle cx="9" cy="6" r="1.5" />
                <circle cx="15" cy="6" r="1.5" />
                <circle cx="9" cy="12" r="1.5" />
                <circle cx="15" cy="12" r="1.5" />
                <circle cx="9" cy="18" r="1.5" />
                <circle cx="15" cy="18" r="1.5" />
              </svg>
            </button>
            <div v-if="editingCategoryId === cat.id" class="min-w-0 flex-1">
              <input
                :ref="setEditNameInput"
                v-model="editingCategoryName"
                class="min-h-10 w-full rounded-xl border px-3 text-sm outline-none focus:border-primary"
                :class="editingCategoryError ? 'border-red-400' : 'border-gray-200'"
                :placeholder="t('placeholder_name')"
                maxlength="100"
                :disabled="savingCategoryRename"
                :aria-invalid="Boolean(editingCategoryError)"
                :aria-label="t('label_category')"
                @input="editingCategoryError = ''"
                @keyup.enter="saveCategoryRename"
                @keyup.escape="cancelCategoryRename"
              />
              <p v-if="editingCategoryError" class="mt-1 text-xs text-red-600">{{ editingCategoryError }}</p>
            </div>
            <div v-else class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium text-gray-900">{{ cat.name }}</p>
              <p class="text-xs text-gray-400">
                {{ t((cat.documents_count ?? 0) === 1 ? 'files_count_document' : 'files_count_documents', { count: cat.documents_count ?? 0 }) }}
              </p>
            </div>
            <template v-if="editingCategoryId === cat.id">
              <button
                type="button"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-primary hover:bg-soft-blue disabled:opacity-60"
                :title="t('action_save')"
                :aria-label="t('aria_save_profile_name')"
                :disabled="savingCategoryRename || !editingCategoryName.trim()"
                @click="saveCategoryRename"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4.5 12.75l6 6 9-13.5" />
                </svg>
              </button>
              <button
                type="button"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-gray-500 hover:bg-gray-100 disabled:opacity-60"
                :title="t('action_cancel')"
                :aria-label="t('aria_cancel_rename')"
                :disabled="savingCategoryRename"
                @click="cancelCategoryRename"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </template>
            <template v-else>
              <button
                type="button"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-primary hover:bg-soft-blue"
                :title="t('action_edit')"
                :aria-label="t('action_edit')"
                @click="startCategoryRename(cat)"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                </svg>
              </button>
              <button
                type="button"
                class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-lg text-red-600 hover:bg-red-50"
                :title="t('action_delete_category')"
                :aria-label="t('action_delete_category')"
                @click="deleteCategory(cat)"
              >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                </svg>
              </button>
            </template>
          </li>
          <li v-if="!categories.length" class="px-3 py-8 text-center text-sm text-gray-500">
            {{ t('msg_no_categories_yet') }}
          </li>
          </ul>
        </div>
      </div>
    </Teleport>
  </AppPage>
</template>
