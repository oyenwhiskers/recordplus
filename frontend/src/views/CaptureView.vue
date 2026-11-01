<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import api, { ensureCsrf } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import type { Category, DocumentItem, DocumentStatus } from '@/types'
import AppPage from '@/components/AppPage.vue'
import StatusBadge from '@/components/StatusBadge.vue'
import { confirmAction, notify, promptCategoryChoice, showSuccess } from '@/lib/confirm'
import { formatAmount as documentFormatAmount, primary as documentPrimary, secondary as documentSecondary } from '@/lib/documentDisplay'

defineOptions({ name: 'CaptureView' })

const { t } = useI18n()

type CapturePhase = 'uploading' | 'analysing' | 'filed' | 'needs_review' | 'failed'

interface PendingFile {
  localId: string
  file: File
  name: string
  previewUrl: string | null
  isPdf: boolean
}

interface SessionItem {
  localId: string
  profileId: number
  profileName: string
  filename: string
  previewUrl: string | null
  isPdf: boolean
  phase: CapturePhase
  document: DocumentItem | null
  error: string | null
  selectedCategoryId: number | ''
  newCategoryName: string
  confirming: boolean
}

const auth = useAuthStore()
const fileInput = ref<HTMLInputElement | null>(null)
const galleryInput = ref<HTMLInputElement | null>(null)
const cameraInput = ref<HTMLInputElement | null>(null)
const pending = ref<PendingFile[]>([])
const session = ref<SessionItem[]>([])
const categories = ref<Category[]>([])
const submitting = ref(false)
const dragging = ref(false)
const isMobile = ref(false)
const webcamSupported = ref(false)

function phaseToStatus(phase: CapturePhase): DocumentStatus | null {
  if (phase === 'uploading') {
    return null
  }
  if (phase === 'analysing') {
    return 'processing'
  }
  if (phase === 'failed') {
    return 'processing_failed'
  }
  return phase
}

const confirmingAll = ref(false)

const summary = computed(() => {
  const items = session.value
  return {
    total: items.length,
    filed: items.filter((i) => i.phase === 'filed').length,
    needsReview: items.filter((i) => i.phase === 'needs_review').length,
    failed: items.filter((i) => i.phase === 'failed').length,
    active: items.filter((i) => i.phase === 'uploading' || i.phase === 'analysing').length,
  }
})

const showSummary = computed(
  () => session.value.length > 0 && summary.value.active === 0,
)

const showConfirmAll = computed(
  () => summary.value.needsReview > 0 && summary.value.active === 0,
)

const showUploadBar = computed(() => pending.value.length > 0)

const showPinnedAction = computed(() => showUploadBar.value || showConfirmAll.value)

const confirmableItems = computed(() =>
  session.value.filter((item) => {
    if (item.phase !== 'needs_review' || !item.document || item.confirming) {
      return false
    }
    if (isNewCategorySuggestion(item)) {
      return true
    }
    return Boolean(item.selectedCategoryId)
  }),
)

/** Upload sources only when nothing is in this session yet. */
const showUploadSources = computed(() => session.value.length === 0)

function detectDevice() {
  isMobile.value =
    window.matchMedia('(max-width: 768px)').matches ||
    window.matchMedia('(pointer: coarse)').matches
  webcamSupported.value = Boolean(navigator.mediaDevices?.getUserMedia)
}

function uid() {
  return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

function isAllowed(file: File) {
  return file.type.startsWith('image/') || file.type === 'application/pdf'
}

function addFiles(fileList: FileList | null) {
  if (!fileList?.length) return

  for (const file of Array.from(fileList)) {
    if (!isAllowed(file)) continue

    const duplicate = pending.value.some(
      (p) => p.name === file.name && p.file.size === file.size && p.file.lastModified === file.lastModified,
    )
    if (duplicate) continue

    const isPdf = file.type === 'application/pdf'
    pending.value.push({
      localId: uid(),
      file,
      name: file.name,
      previewUrl: isPdf ? null : URL.createObjectURL(file),
      isPdf,
    })
  }

  if (fileInput.value) fileInput.value.value = ''
  if (cameraInput.value) cameraInput.value.value = ''
}

function removePending(localId: string) {
  const idx = pending.value.findIndex((p) => p.localId === localId)
  if (idx < 0) return
  const [removed] = pending.value.splice(idx, 1)
  if (removed?.previewUrl) URL.revokeObjectURL(removed.previewUrl)
}

function clearPending() {
  for (const item of pending.value) {
    if (item.previewUrl) URL.revokeObjectURL(item.previewUrl)
  }
  pending.value = []
}

function mapPhase(doc: DocumentItem, fallback: CapturePhase = 'analysing'): CapturePhase {
  if (doc.status === 'filed') return 'filed'
  if (doc.status === 'needs_review' || doc.status === 'uncategorised') return 'needs_review'
  if (doc.status === 'processing_failed') return 'failed'
  if (doc.status === 'processing') return 'analysing'
  return fallback
}

async function loadCategories() {
  try {
    const { data } = await api.get('/categories')
    categories.value = data.categories
  } catch {
    categories.value = []
  }
}

function sessionItem(localId: string): SessionItem | undefined {
  return session.value.find((item) => item.localId === localId)
}

function findCategoryByName(name: string | null | undefined): Category | undefined {
  if (!name?.trim()) return undefined
  const needle = name.trim().toLowerCase()
  return categories.value.find((cat) => cat.name.trim().toLowerCase() === needle)
}

function resolveSuggestedCategoryId(doc: DocumentItem): number | '' {
  if (doc.category_id) return doc.category_id

  const recommended = doc.extracted_data?.recommended_category_id
  if (typeof recommended === 'number' && categories.value.some((cat) => cat.id === recommended)) {
    return recommended
  }

  const byName = findCategoryByName(doc.suggested_category_name)
  return byName?.id ?? ''
}

function applyDocumentSuggestion(row: SessionItem, doc: DocumentItem) {
  row.document = doc
  row.phase = mapPhase(doc)
  row.newCategoryName = doc.suggested_category_name || row.newCategoryName
  if (!doc.suggests_new_category) {
    row.selectedCategoryId = resolveSuggestedCategoryId(doc)
    // Prefer filing the existing category — never show create-new for a known match.
    if (row.selectedCategoryId && row.document) {
      row.document = { ...row.document, suggests_new_category: false }
    }
  } else {
    const existing = findCategoryByName(doc.suggested_category_name)
    if (existing) {
      row.selectedCategoryId = existing.id
      row.document = { ...doc, suggests_new_category: false, suggested_category_name: existing.name }
    } else {
      row.selectedCategoryId = ''
    }
  }
}

function metaLine(doc: DocumentItem): string {
  const parts: string[] = []
  const secondary = documentSecondary(doc)
  if (secondary) {
    parts.push(secondary)
  } else if (doc.reference_number) {
    parts.push(doc.reference_number)
  }

  if (doc.document_type && !parts.some((part) => part.toLowerCase().includes(doc.document_type!.toLowerCase()))) {
    parts.push(doc.document_type)
  }

  if (doc.amount != null && doc.amount !== '') {
    const amountBit = documentFormatAmount(doc)
    if (!parts.some((part) => part.includes(amountBit))) {
      parts.push(amountBit)
    }
  }

  return parts.join(' · ') || doc.original_filename
}

function selectedCategoryLabel(item: SessionItem): string {
  if (item.selectedCategoryId) {
    return categories.value.find((cat) => cat.id === item.selectedCategoryId)?.name
      || item.document?.suggested_category_name
      || t('label_choose_category')
  }
  return item.document?.suggested_category_name || t('label_choose_category')
}

function isNewCategorySuggestion(item: SessionItem): boolean {
  return Boolean(item.document?.suggests_new_category && !item.selectedCategoryId)
}

async function onCategorySelect(item: SessionItem, event: Event) {
  const select = event.target as HTMLSelectElement
  const value = select.value

  if (value === '__new__') {
    select.value = item.selectedCategoryId ? String(item.selectedCategoryId) : ''
    await changeCategory(item)
    return
  }

  if (!value) {
    item.selectedCategoryId = ''
    return
  }

  const categoryId = Number(value)
  item.selectedCategoryId = categoryId
  const name = categories.value.find((cat) => cat.id === categoryId)?.name || ''
  item.newCategoryName = name
  if (item.document) {
    item.document = {
      ...item.document,
      suggests_new_category: false,
      suggested_category_name: name || item.document.suggested_category_name,
    }
  }
}

async function submitPending() {
  if (submitting.value || !pending.value.length || !auth.activeProfile) return

  submitting.value = true
  const profileId = auth.activeProfile.id
  const profileName = auth.activeProfile.name
  const batch = [...pending.value]
  pending.value = []

  await ensureCsrf()
  await loadCategories()

  for (const item of batch) {
    session.value.unshift({
      localId: item.localId,
      profileId,
      profileName,
      filename: item.name,
      previewUrl: item.previewUrl,
      isPdf: item.isPdf,
      phase: 'uploading',
      document: null,
      error: null,
      selectedCategoryId: '',
      newCategoryName: '',
      confirming: false,
    })

    const row = sessionItem(item.localId)
    if (!row) continue

    try {
      const form = new FormData()
      form.append('file', item.file)
      form.append('profile_id', String(profileId))

      const { data } = await api.post('/documents', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      })

      applyDocumentSuggestion(row, data.document as DocumentItem)
      if (row.phase === 'filed') {
        notify({ title: t('msg_filed_automatically') })
      }

      if (row.phase === 'analysing' || row.phase === 'uploading') {
        pollDocument(item.localId)
      }
    } catch (e: any) {
      row.phase = 'failed'
      row.error = e?.response?.data?.message || t('msg_upload_failed')
    }
  }

  submitting.value = false
}

async function pollDocument(localId: string) {
  for (let i = 0; i < 40; i++) {
    if (i > 0) {
      await new Promise((r) => setTimeout(r, 1500))
    }

    const item = sessionItem(localId)
    if (!item?.document) return

    try {
      const { data } = await api.get(`/documents/${item.document.id}`)
      const row = sessionItem(localId)
      if (!row) return

      const wasAnalysing = row.phase === 'analysing' || row.phase === 'uploading'
      applyDocumentSuggestion(row, data.document)
      row.error = data.document.ai_error
      if (wasAnalysing && row.phase === 'filed') {
        notify({ title: t('msg_filed_automatically') })
      }
      if (row.phase !== 'analysing' && row.phase !== 'uploading') return
    } catch (e: any) {
      const row = sessionItem(localId)
      if (!row) return

      const status = e?.response?.status
      row.phase = 'failed'
      row.error =
        status === 404
          ? t('msg_upload_gone')
          : e?.response?.data?.message || t('msg_analysis_status_failed')
      return
    }
  }

  const row = sessionItem(localId)
  if (row && (row.phase === 'analysing' || row.phase === 'uploading')) {
    row.error = t('msg_analysis_slow')
  }
}

async function retryAnalysis(item: SessionItem) {
  if (!item.document) return
  await ensureCsrf()
  item.phase = 'analysing'
  item.error = null
  const { data } = await api.post(`/documents/${item.document.id}/retry`)
  item.document = data.document
  item.phase = mapPhase(data.document, 'analysing')
  pollDocument(item.localId)
}

async function confirmFiling(
  item: SessionItem,
  mode: 'confirm' | 'create' = 'confirm',
  options: { silent?: boolean } = {},
): Promise<boolean> {
  if (!item.document || item.confirming) return false

  const payload: Record<string, unknown> = {}

  if (mode === 'create' || (item.document.suggests_new_category && !item.selectedCategoryId)) {
    const name = item.newCategoryName.trim() || item.document.suggested_category_name?.trim() || ''
    const existing = findCategoryByName(name)
    if (existing) {
      payload.category_id = existing.id
      item.selectedCategoryId = existing.id
    } else if (name) {
      payload.new_category_name = name
    } else {
      notify({ title: t('msg_no_category_suggestion'), icon: 'warning' })
      return false
    }
  } else if (item.selectedCategoryId) {
    payload.category_id = item.selectedCategoryId
  } else {
    const resolved = resolveSuggestedCategoryId(item.document)
    if (resolved) {
      payload.category_id = resolved
      item.selectedCategoryId = resolved
    } else {
      notify({ title: t('msg_choose_category_first'), icon: 'warning' })
      if (!options.silent) {
        await changeCategory(item)
      }
      return false
    }
  }

  item.confirming = true
  item.error = null
  try {
    await ensureCsrf()
    const { data } = await api.post(`/documents/${item.document.id}/confirm`, payload)
    item.document = data.document
    item.phase = 'filed'
    if (!options.silent) {
      await showSuccess({
        title: t('msg_filed_to_category', {
          category: data.document.category?.name || t('label_category'),
        }),
      })
      await loadCategories()
      maybeResetSession()
    }
    return true
  } catch (e: any) {
    item.error = e?.response?.data?.message || t('msg_could_not_file')
    notify({ title: item.error, icon: 'error' })
    return false
  } finally {
    item.confirming = false
  }
}

async function confirmAll() {
  const items = [...confirmableItems.value]
  if (!items.length || confirmingAll.value) return

  confirmingAll.value = true
  let filed = 0
  try {
    for (const item of items) {
      const mode = isNewCategorySuggestion(item) ? 'create' : 'confirm'
      if (await confirmFiling(item, mode, { silent: true })) {
        filed++
      }
    }
    if (filed > 0) {
      await loadCategories()
      await showSuccess({
        title: t(filed === 1 ? 'msg_filed_n' : 'msg_filed_n_plural', { count: filed }),
      })
    }
  } finally {
    confirmingAll.value = false
  }
  if (filed > 0) {
    maybeResetSession()
  }
}

async function changeCategory(item: SessionItem) {
  if (!item.document) return

  const choice = await promptCategoryChoice({
    categories: categories.value,
    selectedId: item.selectedCategoryId,
    allowNew: true,
    title: t('title_change_category'),
  })
  if (!choice) return

  if ('categoryId' in choice) {
    item.selectedCategoryId = choice.categoryId
    item.newCategoryName = categories.value.find((cat) => cat.id === choice.categoryId)?.name || ''
    if (item.document) {
      item.document = {
        ...item.document,
        suggests_new_category: false,
        suggested_category_name: item.newCategoryName || item.document.suggested_category_name,
      }
    }
    return
  }

  const existing = findCategoryByName(choice.newCategoryName)
  if (existing) {
    item.selectedCategoryId = existing.id
    item.newCategoryName = existing.name
    if (item.document) {
      item.document = {
        ...item.document,
        suggests_new_category: false,
        suggested_category_name: existing.name,
      }
    }
    return
  }

  item.selectedCategoryId = ''
  item.newCategoryName = choice.newCategoryName
  if (item.document) {
    item.document = {
      ...item.document,
      suggests_new_category: true,
      suggested_category_name: choice.newCategoryName,
    }
  }
}

async function previewDocument(item: SessionItem) {
  if (!item.document) return

  try {
    const { data } = await api.get(`/documents/${item.document.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(data)
    window.open(url, '_blank', 'noopener,noreferrer')
    window.setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch {
    notify({ title: t('msg_could_not_open_preview'), icon: 'error' })
  }
}

function dropSessionItem(localId: string) {
  const idx = session.value.findIndex((row) => row.localId === localId)
  if (idx < 0) return
  const [removed] = session.value.splice(idx, 1)
  if (removed?.previewUrl) {
    URL.revokeObjectURL(removed.previewUrl)
  }
}

/** Clear this session UI so upload sources return; does not delete filed docs. */
function captureAnother() {
  for (const item of session.value) {
    if (item.previewUrl) {
      URL.revokeObjectURL(item.previewUrl)
    }
  }
  session.value = []
  clearPending()
}

/** After everything is filed, drop the session list so capture sources return. */
function maybeResetSession() {
  const s = summary.value
  if (s.total > 0 && s.active === 0 && s.needsReview === 0 && s.failed === 0) {
    captureAnother()
  }
}

async function cancelSessionItem(item: SessionItem) {
  if (item.confirming) return

  if (!item.document) {
    dropSessionItem(item.localId)
    return
  }

  await removeDocument(item)
}

async function removeDocument(item: SessionItem) {
  if (!item.document || item.confirming) return

  const ok = await confirmAction({
    title: t('title_delete_document'),
    text: t('msg_delete_document_confirm'),
    confirmText: t('action_remove'),
  })
  if (!ok) return

  item.confirming = true
  item.error = null
  try {
    await ensureCsrf()
    await api.delete(`/documents/${item.document.id}`)
    dropSessionItem(item.localId)
    notify({ title: t('msg_document_deleted') })
  } catch (e: any) {
    item.error = e?.response?.data?.message || t('msg_could_not_delete_document')
    notify({ title: item.error, icon: 'error' })
    item.confirming = false
  }
}

function onDrop(e: DragEvent) {
  e.preventDefault()
  dragging.value = false
  if (submitting.value) return
  addFiles(e.dataTransfer?.files ?? null)
}

function onZoneClick() {
  if (submitting.value) return
  if (isMobile.value) return
  fileInput.value?.click()
}

onMounted(() => {
  detectDevice()
  window.addEventListener('resize', detectDevice)
  loadCategories()
})

onUnmounted(() => {
  window.removeEventListener('resize', detectDevice)
  clearPending()
})
</script>

<template>
  <AppPage
    width="capture"
    :fill="isMobile"
    :class="showPinnedAction ? '!pb-2' : undefined"
  >
    <div
      class="flex min-w-0 max-w-full flex-col"
      :class="isMobile
        ? (showPinnedAction
          ? 'min-h-0 flex-1 gap-3 overflow-x-hidden overflow-y-hidden'
          : 'min-h-0 flex-1 gap-5 overflow-x-hidden overflow-y-auto')
        : 'gap-5'"
    >
      <div
        class="grid min-w-0 max-w-full gap-5"
        :class="[
          isMobile && showUploadSources && !pending.length ? 'min-h-0 flex-1' : '',
          isMobile && showPinnedAction ? 'min-h-0 flex-1 overflow-y-auto' : '',
        ]"
      >
        <div
          v-if="showUploadSources || pending.length"
          class="flex min-w-0 max-w-full flex-col gap-5"
          :class="isMobile && showUploadSources && !pending.length ? 'h-full min-h-0' : ''"
        >
      <!-- Mobile: capture source actions (hidden once something is in this session) -->
      <div
        v-if="isMobile && showUploadSources"
        class="flex min-w-0 max-w-full flex-col gap-3"
        :class="!pending.length ? 'min-h-0 flex-1' : ''"
      >
        <!-- label+for: iOS ignores .click() on display:none file inputs -->
        <label
          for="capture-camera-input"
          class="flex w-full cursor-pointer flex-col items-center justify-center rounded-3xl bg-primary px-4 text-white shadow-sm ring-1 ring-primary/30 hover:bg-blue-600"
          :class="[
            pending.length ? 'h-28' : 'h-[12.5rem] px-6',
            submitting ? 'pointer-events-none opacity-60' : '',
          ]"
        >
          <span
            class="flex items-center justify-center rounded-full bg-white/15 ring-1 ring-white/25"
            :class="pending.length ? 'h-11 w-11' : 'h-14 w-14'"
            aria-hidden="true"
          >
            <svg
              :class="pending.length ? 'h-5 w-5' : 'h-7 w-7'"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </span>
          <span
            class="font-bold tracking-tight"
            :class="pending.length ? 'mt-2 text-base' : 'mt-3 text-lg'"
          >{{ t('capture_take_photo') }}</span>
        </label>

        <div class="grid min-w-0 grid-cols-2 gap-3">
          <label
            for="capture-gallery-input"
            class="flex min-w-0 cursor-pointer flex-col items-center justify-center gap-2 rounded-3xl border border-gray-200 bg-white px-2 text-center shadow-sm hover:border-primary/25 hover:bg-soft-blue"
            :class="[
              pending.length ? 'h-24' : 'h-[7.25rem] px-3',
              submitting ? 'pointer-events-none opacity-60' : '',
            ]"
          >
            <span
              class="flex items-center justify-center rounded-2xl bg-soft-blue text-primary"
              :class="pending.length ? 'h-9 w-9' : 'h-11 w-11'"
              aria-hidden="true"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
              </svg>
            </span>
            <span class="truncate text-sm font-semibold text-gray-900">{{ t('capture_gallery') }}</span>
          </label>
          <label
            for="capture-file-input"
            class="flex min-w-0 cursor-pointer flex-col items-center justify-center gap-2 rounded-3xl border border-gray-200 bg-white px-2 text-center shadow-sm hover:border-primary/25 hover:bg-soft-blue"
            :class="[
              pending.length ? 'h-24' : 'h-[7.25rem] px-3',
              submitting ? 'pointer-events-none opacity-60' : '',
            ]"
          >
            <span
              class="flex items-center justify-center rounded-2xl bg-soft-blue text-primary"
              :class="pending.length ? 'h-9 w-9' : 'h-11 w-11'"
              aria-hidden="true"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z" />
              </svg>
            </span>
            <span class="truncate text-sm font-semibold text-gray-900">{{ t('capture_pdf_files') }}</span>
          </label>
        </div>

        <div
          v-if="!pending.length"
          class="mt-2 flex flex-1 flex-col justify-center px-1 pb-2"
        >
          <p class="text-center text-xs font-semibold uppercase tracking-wide text-gray-400">
            {{ t('capture_how_it_works') }}
          </p>
          <ol class="mt-4 space-y-4 text-sm leading-relaxed text-gray-600">
            <li class="flex gap-3">
              <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-soft-blue text-xs font-bold text-primary"
                aria-hidden="true"
              >1</span>
              <div>
                <p class="font-semibold text-gray-900">{{ t('capture_step1_title') }}</p>
                <p class="mt-0.5">
                  {{ t('capture_step1_body', { profile: auth.activeProfile?.name || t('capture_your_profile') }) }}
                </p>
              </div>
            </li>
            <li class="flex gap-3">
              <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-soft-blue text-xs font-bold text-primary"
                aria-hidden="true"
              >2</span>
              <div>
                <p class="font-semibold text-gray-900">{{ t('capture_step2_title') }}</p>
                <p class="mt-0.5">
                  {{ t('capture_step2_body') }}
                </p>
              </div>
            </li>
            <li class="flex gap-3">
              <span
                class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-soft-blue text-xs font-bold text-primary"
                aria-hidden="true"
              >3</span>
              <div>
                <p class="font-semibold text-gray-900">{{ t('capture_step3_title') }}</p>
                <p class="mt-0.5">
                  {{ t('capture_step3_body') }}
                </p>
              </div>
            </li>
          </ol>
        </div>
      </div>

      <!-- Desktop: drop zone primary, camera secondary -->
      <div
        v-else-if="showUploadSources"
        class="cursor-pointer rounded-2xl border-2 border-dashed px-5 py-10 text-center transition-colors sm:px-8 sm:py-12"
        :class="[
          dragging ? 'border-primary bg-soft-blue' : 'border-primary/30 bg-soft-blue/50',
          submitting ? 'pointer-events-none opacity-60' : '',
        ]"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop="onDrop"
        @click="onZoneClick"
      >
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-primary shadow-sm">
          <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" />
          </svg>
        </div>
        <p class="text-lg font-semibold text-gray-900">{{ t('capture_drop_title') }}</p>
        <p class="mt-1 text-sm text-gray-500">{{ t('capture_drop_subtitle') }}</p>
        <div class="mt-6 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center" @click.stop>
          <label
            for="capture-file-input"
            class="inline-flex min-h-11 cursor-pointer items-center justify-center rounded-xl bg-primary px-6 py-3 text-sm font-semibold text-white hover:bg-blue-600"
            :class="submitting ? 'pointer-events-none opacity-60' : ''"
          >
            {{ t('capture_choose_files') }}
          </label>
          <label
            v-if="webcamSupported"
            for="capture-camera-input"
            class="inline-flex min-h-11 cursor-pointer items-center justify-center rounded-xl border border-gray-200 bg-white px-6 py-3 text-sm font-semibold text-gray-700 hover:bg-gray-50"
            :class="submitting ? 'pointer-events-none opacity-60' : ''"
          >
            {{ t('capture_take_photo') }}
          </label>
        </div>
      </div>

      <!-- fixed 1px (not display:none): mobile Safari blocks programmatic / label activation of hidden file inputs -->
      <input
        id="capture-camera-input"
        ref="cameraInput"
        type="file"
        accept="image/*"
        capture="environment"
        class="pointer-events-none fixed left-0 top-0 h-px w-px opacity-0"
        :disabled="submitting"
        @change="addFiles(($event.target as HTMLInputElement).files)"
      />
      <input
        id="capture-gallery-input"
        ref="galleryInput"
        type="file"
        accept="image/*"
        multiple
        class="pointer-events-none fixed left-0 top-0 h-px w-px opacity-0"
        :disabled="submitting"
        @change="addFiles(($event.target as HTMLInputElement).files)"
      />
      <input
        id="capture-file-input"
        ref="fileInput"
        type="file"
        accept="image/*,application/pdf"
        multiple
        class="pointer-events-none fixed left-0 top-0 h-px w-px opacity-0"
        :disabled="submitting"
        @change="addFiles(($event.target as HTMLInputElement).files)"
      />

      <!-- Pending selection -->
      <section v-if="pending.length" class="rounded-2xl border border-gray-100 bg-white p-4 sm:p-5">
        <div class="flex flex-wrap items-center justify-between gap-2">
          <div>
            <h2 class="text-base font-semibold text-gray-900">{{ t('capture_ready_title') }}</h2>
            <p class="text-sm text-gray-500">
              {{ t(pending.length === 1 ? 'capture_ready_count' : 'capture_ready_count_plural', { count: pending.length, name: auth.activeProfile?.name }) }}
            </p>
          </div>
          <button
            type="button"
            class="text-sm font-medium text-gray-500 hover:text-gray-800"
            :disabled="submitting"
            @click="clearPending"
          >
            {{ t('action_clear_all') }}
          </button>
        </div>

        <ul class="mt-4 space-y-3">
          <li
            v-for="item in pending"
            :key="item.localId"
            class="flex items-center gap-3 rounded-xl border border-gray-100 bg-background/80 px-3 py-2.5"
          >
            <div class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-white">
              <img v-if="item.previewUrl" :src="item.previewUrl" :alt="item.name" class="h-full w-full object-cover" />
              <span v-else class="text-xs font-bold text-primary">PDF</span>
            </div>
            <p class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900">{{ item.name }}</p>
            <button
              type="button"
              class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-gray-400 hover:bg-red-50 hover:text-red-600"
              :title="t('action_remove')"
              :aria-label="t('aria_remove_file')"
              :disabled="submitting"
              @click="removePending(item.localId)"
            >
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </li>
        </ul>
      </section>
        </div>

      <!-- Session overview + item list -->
      <div v-if="session.length" class="flex min-w-0 max-w-full flex-col gap-3">
        <section class="min-w-0 rounded-2xl border border-gray-100 bg-white p-4 sm:p-5">
          <div class="flex min-w-0 items-center justify-between gap-2">
            <h2 class="min-w-0 text-base font-semibold text-gray-900">
              {{ summary.needsReview > 0 && summary.active === 0 ? t('capture_review_documents') : t('capture_this_session') }}
            </h2>
            <button
              type="button"
              class="shrink-0 text-sm font-semibold text-primary hover:underline"
              @click="captureAnother"
            >
              {{ t('action_reset') }}
            </button>
          </div>

          <div v-if="showSummary" class="mt-3 grid grid-cols-3 gap-2">
            <div class="rounded-xl bg-emerald-50 px-2 py-3 text-center">
              <p class="text-2xl font-bold tabular-nums tracking-tight text-emerald-700">{{ summary.filed }}</p>
              <p class="mt-0.5 text-[11px] font-medium leading-tight text-emerald-700/80">{{ t('status_filed') }}</p>
            </div>
            <div class="rounded-xl bg-amber-50 px-2 py-3 text-center">
              <p class="text-2xl font-bold tabular-nums tracking-tight text-amber-700">{{ summary.needsReview }}</p>
              <p class="mt-0.5 text-[11px] font-medium leading-tight text-amber-700/80">{{ t('status_needs_review') }}</p>
            </div>
            <div class="rounded-xl bg-red-50 px-2 py-3 text-center">
              <p class="text-2xl font-bold tabular-nums tracking-tight text-red-700">{{ summary.failed }}</p>
              <p class="mt-0.5 text-[11px] font-medium leading-tight text-red-700/80">{{ t('status_processing_failed') }}</p>
            </div>
          </div>
        </section>

        <ul class="min-w-0 space-y-3">
          <li
            v-for="item in session"
            :key="item.localId"
            class="min-w-0 max-w-full rounded-2xl border border-gray-100 bg-white px-3 py-3 sm:px-4 sm:py-4"
          >
            <div class="flex flex-col gap-3">
              <div class="min-w-0">
                <div class="flex items-start gap-2">
                  <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-gray-900">
                      {{ item.document ? documentPrimary(item.document) : item.filename }}
                    </p>
                    <p class="mt-0.5 truncate text-xs text-gray-500">
                      <template v-if="item.document">{{ metaLine(item.document) }}</template>
                      <template v-else>{{ item.profileName }}</template>
                    </p>
                    <p v-if="item.phase === 'analysing' && !item.error" class="mt-1 text-xs text-gray-500">
                      {{ t('capture_reading_ai') }}
                    </p>
                    <p v-if="item.error && item.phase !== 'failed'" class="mt-1 text-xs text-red-600">{{ item.error }}</p>
                    <p v-if="item.phase === 'failed'" class="mt-1 text-xs text-gray-600">
                      {{ item.error || item.document?.ai_error || t('capture_analyse_failed') }}
                    </p>
                  </div>

                  <div class="shrink-0 self-start">
                    <span
                      v-if="item.phase === 'uploading'"
                      class="inline-flex items-center gap-1.5 rounded-full bg-soft-blue px-2.5 py-1 text-xs font-semibold text-primary"
                    >
                      <span
                        class="h-3 w-3 shrink-0 animate-spin rounded-full border-2 border-primary/25 border-t-primary"
                        aria-hidden="true"
                      />
                      {{ t('capture_phase_uploading') }}
                    </span>
                    <StatusBadge
                      v-else
                      :status="(phaseToStatus(item.phase) ?? 'processing_failed')"
                    />
                  </div>
                </div>

                <!-- Filed -->
                <div v-if="item.phase === 'filed' && item.document" class="mt-2 flex flex-wrap items-center gap-2">
                  <p v-if="item.document.category" class="text-xs text-gray-500">
                    {{ item.document.category.name }}
                  </p>
                  <RouterLink
                    :to="`/files/${item.document.id}`"
                    class="text-xs font-semibold text-primary hover:underline"
                  >
                    {{ t('action_view') }}
                  </RouterLink>
                </div>

                <!-- Needs review: existing category -->
                <label
                  v-else-if="item.phase === 'needs_review' && item.document && !isNewCategorySuggestion(item)"
                  class="mt-2.5 flex min-w-0 flex-col gap-1.5 text-sm text-gray-600"
                >
                  <span class="shrink-0">{{ t('label_file_under') }}</span>
                  <span class="block min-w-0 max-w-full overflow-hidden">
                    <select
                      class="box-border min-h-9 w-full max-w-full cursor-pointer rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-sm font-medium text-gray-900 outline-none focus:border-primary"
                      :value="item.selectedCategoryId || ''"
                      :disabled="item.confirming"
                      :aria-label="`${t('label_file_under')} ${selectedCategoryLabel(item)}`"
                      @change="onCategorySelect(item, $event)"
                    >
                      <option value="">{{ t('label_choose_category') }}</option>
                      <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                      <option value="__new__">{{ t('label_create_new') }}</option>
                    </select>
                  </span>
                </label>

                <!-- Needs review: new category suggestion -->
                <div
                  v-else-if="item.phase === 'needs_review' && item.document && isNewCategorySuggestion(item)"
                  class="mt-2.5"
                >
                  <p class="text-xs text-gray-600">
                    {{ t('capture_suggested_new_category') }}
                    <span class="font-semibold text-gray-900">{{ item.document.suggested_category_name || t('capture_general_documents') }}</span>
                  </p>
                </div>

                <!-- Failed or stalled analysis -->
                <div
                  v-else-if="item.phase === 'failed' || (item.phase === 'analysing' && item.error)"
                  class="mt-2 flex flex-wrap gap-2"
                >
                  <button
                    v-if="item.document"
                    type="button"
                    class="inline-flex min-h-9 cursor-pointer items-center rounded-xl bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-blue-600"
                    @click="retryAnalysis(item)"
                  >
                    {{ t('action_retry_analysis') }}
                  </button>
                  <RouterLink
                    v-if="item.document"
                    :to="`/files/${item.document.id}`"
                    class="inline-flex min-h-9 cursor-pointer items-center rounded-xl border border-gray-200 bg-white px-3 py-1.5 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                  >
                    {{ t('action_file_manually') }}
                  </RouterLink>
                  <button
                    type="button"
                    class="inline-flex min-h-9 cursor-pointer items-center rounded-xl border border-red-200 bg-red-50 px-3 py-1.5 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="item.confirming"
                    @click="cancelSessionItem(item)"
                  >
                    {{ t('action_remove') }}
                  </button>
                </div>

                <!-- Uploading / analysing: cancel restores capture sources -->
                <div
                  v-else-if="item.phase === 'uploading' || item.phase === 'analysing'"
                  class="mt-2.5"
                >
                  <button
                    type="button"
                    class="inline-flex min-h-10 w-full cursor-pointer items-center justify-center rounded-xl border border-red-200 bg-red-50 px-3.5 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="item.confirming"
                    @click="cancelSessionItem(item)"
                  >
                    {{ t('action_cancel') }}
                  </button>
                </div>
              </div>

              <div
                v-if="item.phase === 'needs_review' && item.document"
                class="grid w-full min-w-0 grid-cols-3 gap-2"
              >
                <button
                  type="button"
                  class="inline-flex min-h-10 min-w-0 cursor-pointer items-center justify-center rounded-xl border border-red-200 bg-red-50 px-2 py-2 text-sm font-semibold text-red-700 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="item.confirming || confirmingAll"
                  @click="removeDocument(item)"
                >
                  {{ t('action_remove') }}
                </button>
                <button
                  type="button"
                  class="inline-flex min-h-10 min-w-0 cursor-pointer items-center justify-center rounded-xl border border-gray-200 bg-white px-2 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                  :disabled="item.confirming || confirmingAll"
                  @click="previewDocument(item)"
                >
                  {{ t('action_view') }}
                </button>
                <button
                  v-if="isNewCategorySuggestion(item)"
                  type="button"
                  class="inline-flex min-h-10 min-w-0 cursor-pointer items-center justify-center rounded-xl bg-primary px-2 py-2 text-sm font-semibold text-white hover:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="item.confirming || confirmingAll"
                  @click="confirmFiling(item, 'create')"
                >
                  {{ item.confirming ? t('capture_filing') : t('action_create') }}
                </button>
                <button
                  v-else
                  type="button"
                  class="inline-flex min-h-10 min-w-0 cursor-pointer items-center justify-center rounded-xl bg-primary px-2 py-2 text-sm font-semibold text-white hover:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-60"
                  :disabled="item.confirming || confirmingAll || !item.selectedCategoryId"
                  @click="confirmFiling(item)"
                >
                  {{ item.confirming ? t('capture_filing') : t('action_confirm') }}
                </button>
              </div>
            </div>
          </li>
        </ul>
      </div>
      </div>

      <div
        v-if="showUploadBar"
        class="shrink-0"
      >
        <button
          type="button"
          class="flex min-h-11 w-full cursor-pointer items-center justify-center rounded-xl border-0 bg-primary text-sm font-semibold text-white shadow-none outline-none ring-0 hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="submitting || !pending.length"
          @click="submitPending"
        >
          {{ submitting ? t('capture_uploading') : t(pending.length === 1 ? 'capture_upload_n' : 'capture_upload_n_plural', { count: pending.length }) }}
        </button>
      </div>
      <div
        v-else-if="showConfirmAll"
        class="shrink-0"
      >
        <button
          type="button"
          class="flex min-h-11 w-full cursor-pointer items-center justify-center rounded-xl border-0 bg-primary text-sm font-semibold text-white shadow-none outline-none ring-0 hover:bg-blue-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40 disabled:cursor-not-allowed disabled:opacity-60"
          :disabled="confirmingAll || confirmableItems.length === 0"
          @click="confirmAll"
        >
          {{ confirmingAll ? t('capture_filing') : t('action_confirm_all') }}
        </button>
      </div>
    </div>
  </AppPage>
</template>
