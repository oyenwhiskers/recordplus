<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import api, { ensureCsrf } from '@/api/client'
import { useAuthStore } from '@/stores/auth'
import type { Category, DocumentItem } from '@/types'
import StatusBadge from '@/components/StatusBadge.vue'
import { confirmAction, notify, promptCategoryChoice, promptMoveToProfile, showSuccess } from '@/lib/confirm'
import {
  amountToCentsDigits,
  applyAmountDigits,
  centsDigitsToApiAmount,
  formatCentsDigits,
} from '@/lib/amountInput'
import { formatAmount as documentFormatAmount, primary as documentPrimary } from '@/lib/documentDisplay'
import { canShareFiles, shareBlobFile } from '@/lib/shareFile'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const document = ref<DocumentItem | null>(null)
const moving = ref(false)
const categories = ref<Category[]>([])
const selectedCategoryId = ref<number | ''>('')
const newCategoryName = ref('')
const saving = ref(false)
const filing = ref(false)
const loading = ref(true)
const loadError = ref('')
const editing = ref(false)
const replacing = ref(false)
const replaceError = ref('')
const replaceInput = ref<HTMLInputElement | null>(null)
const previewUrl = ref<string | null>(null)
const previewLoading = ref(false)
const previewError = ref('')
const showPreview = ref(false)
const shareAvailable = ref(false)
const sharing = ref(false)

const draft = ref({
  title: '',
  document_type: '',
  document_date: '',
  merchant: '',
  reference_number: '',
  amount: '',
  currency: '',
  category_id: '' as number | '',
})
/** Digits = cents while editing (cash-register entry). */
const amountCentsDigits = ref('')
const amountFieldDisplay = computed(() =>
  amountCentsDigits.value ? formatCentsDigits(amountCentsDigits.value) : '',
)

function onAmountInput(event: Event) {
  const el = event.target as HTMLInputElement
  amountCentsDigits.value = applyAmountDigits(el.value)
  el.value = amountFieldDisplay.value
}

const id = computed(() => Number(route.params.id))

function documentIsImage(doc: DocumentItem | null | undefined): boolean {
  return Boolean(doc?.mime_type?.startsWith('image/'))
}

function documentIsPdf(doc: DocumentItem | null | undefined): boolean {
  const mime = doc?.mime_type || ''
  const name = doc?.original_filename || ''
  return mime === 'application/pdf' || name.toLowerCase().endsWith('.pdf')
}

const isImage = computed(() => documentIsImage(document.value))
const isPdf = computed(() => documentIsPdf(document.value))
const canPreviewFile = computed(() => isImage.value || isPdf.value)

/**
 * Same-origin inline PDF. toolbar=0 hides Chrome/Edge Drive/print/download chrome
 * (our app bar already has Close / Open / Download). view=FitH starts at page-width.
 */
const pdfPreviewSrc = computed(() => {
  if (!showPreview.value || !document.value || !isPdf.value) return ''
  return `/api/documents/${document.value.id}/download?inline=1#toolbar=0&navpanes=0&view=FitH`
})

function openPdfInNewTab() {
  if (!document.value || !isPdf.value) return
  window.open(`/api/documents/${document.value.id}/download?inline=1`, '_blank', 'noopener,noreferrer')
}

const amountDisplay = computed(() => {
  if (!document.value) return null
  if (document.value.display_amount) {
    return {
      value: document.value.display_amount,
      label: document.value.display_amount_label || null,
    }
  }
  if (document.value.amount == null || document.value.amount === '') return null
  return {
    value: documentFormatAmount(document.value),
    label: document.value.currency || null,
  }
})

const needsReview = computed(() =>
  document.value?.status === 'needs_review' || document.value?.status === 'uncategorised',
)

const isFiled = computed(() => document.value?.status === 'filed')

const isNewCategorySuggestion = computed(() =>
  Boolean(document.value?.suggests_new_category && !selectedCategoryId.value),
)

const showStatusBadge = computed(() => {
  const status = document.value?.status
  if (!status || status === 'filed') return false
  return true
})

function formatDisplayDate(value: string | null | undefined): string {
  if (!value) return '—'
  const raw = String(value).slice(0, 10)
  const date = new Date(`${raw}T00:00:00`)
  if (Number.isNaN(date.getTime())) return raw
  return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
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
  return findCategoryByName(doc.suggested_category_name)?.id ?? ''
}

function applyCategorySuggestion(doc: DocumentItem) {
  newCategoryName.value = doc.suggested_category_name || ''
  if (doc.suggests_new_category) {
    const existing = findCategoryByName(doc.suggested_category_name)
    if (existing) {
      selectedCategoryId.value = existing.id
      document.value = {
        ...doc,
        suggests_new_category: false,
        suggested_category_name: existing.name,
      }
      return
    }
    selectedCategoryId.value = ''
    return
  }
  selectedCategoryId.value = resolveSuggestedCategoryId(doc)
}

function syncDraft(doc: DocumentItem) {
  amountCentsDigits.value = amountToCentsDigits(doc.amount)
  draft.value = {
    title: doc.title || '',
    document_type: doc.document_type || '',
    document_date: doc.document_date ? String(doc.document_date).slice(0, 10) : '',
    merchant: doc.merchant || '',
    reference_number: doc.reference_number || '',
    amount: doc.amount || '',
    currency: doc.currency || '',
    category_id: doc.category_id ?? selectedCategoryId.value ?? '',
  }
}

function fallbackRecordRoute() {
  const categoryId = document.value?.category_id
  if (categoryId) {
    const rawDate = document.value?.document_date
    if (rawDate) {
      const d = new Date(String(rawDate).slice(0, 10) + 'T00:00:00')
      if (!Number.isNaN(d.getTime())) {
        return {
          name: 'files-month' as const,
          params: {
            categoryId: String(categoryId),
            year: String(d.getFullYear()),
            month: String(d.getMonth() + 1),
          },
        }
      }
    }
    return {
      name: 'files-browse' as const,
      params: { categoryId: String(categoryId) },
    }
  }
  return { name: 'files' as const }
}

function closeModal() {
  if (window.history.length > 1) {
    router.back()
    return
  }
  router.push(fallbackRecordRoute())
}

async function loadPreview(doc: DocumentItem) {
  previewLoading.value = true
  previewError.value = ''
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value)
    previewUrl.value = null
  }

  try {
    const { data } = await api.get(`/documents/${doc.id}/download`, { responseType: 'blob' })
    previewUrl.value = URL.createObjectURL(data)
  } catch {
    previewError.value = t('msg_preview_unavailable')
  } finally {
    previewLoading.value = false
  }
}

async function viewDocument() {
  if (!document.value) return
  showPreview.value = true
  // PDFs use same-origin iframe URL — no blob fetch.
  if (documentIsPdf(document.value)) {
    previewLoading.value = false
    previewError.value = ''
    return
  }
  if (!previewUrl.value && !previewLoading.value) {
    await loadPreview(document.value)
  }
}

function closePreview() {
  showPreview.value = false
}

function onPreviewKeydown(event: KeyboardEvent) {
  if (event.key === 'Escape' && showPreview.value) {
    closePreview()
  }
}

watch(showPreview, (open) => {
  window.document.body.style.overflow = open ? 'hidden' : ''
})

async function load() {
  loading.value = true
  loadError.value = ''
  showPreview.value = false
  editing.value = false
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value)
    previewUrl.value = null
  }
  previewError.value = ''
  try {
    const [docRes, catRes] = await Promise.all([
      api.get(`/documents/${id.value}`),
      api.get('/categories'),
    ])
    const doc = {
      ...docRes.data.document,
      document_date: docRes.data.document.document_date
        ? String(docRes.data.document.document_date).slice(0, 10)
        : null,
    } as DocumentItem
    categories.value = catRes.data.categories
    document.value = doc
    syncDraft(doc)
    applyCategorySuggestion(doc)
    // Images only in the inline card — PDF iframe scrolls inside 4:3; open full view on tap.
    if (documentIsImage(doc)) {
      void loadPreview(doc)
    }
  } catch (e: any) {
    document.value = null
    loadError.value = e?.response?.data?.message || t('detail_load_error')
  } finally {
    loading.value = false
  }
}

function startEdit() {
  if (!document.value) return
  syncDraft(document.value)
  editing.value = true
}

function cancelEdit() {
  if (replacing.value) return
  if (document.value) syncDraft(document.value)
  editing.value = false
  replaceError.value = ''
}

function applyDocumentPayload(raw: DocumentItem) {
  const doc = {
    ...raw,
    document_date: raw.document_date ? String(raw.document_date).slice(0, 10) : null,
  } as DocumentItem
  document.value = doc
  syncDraft(doc)
  applyCategorySuggestion(doc)
  return doc
}

async function refreshPreviewFor(doc: DocumentItem) {
  if (previewUrl.value) {
    URL.revokeObjectURL(previewUrl.value)
    previewUrl.value = null
  }
  previewError.value = ''
  if (documentIsImage(doc)) {
    await loadPreview(doc)
  }
}

function openReplacePicker() {
  if (replacing.value || saving.value || filing.value) return
  replaceError.value = ''
  replaceInput.value?.click()
}

async function onReplaceFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file || !document.value) return

  replacing.value = true
  replaceError.value = ''
  try {
    await ensureCsrf()
    const form = new FormData()
    form.append('file', file)
    const { data } = await api.post(`/documents/${document.value.id}/replace`, form, {
      headers: { 'Content-Type': 'multipart/form-data' },
    })
    let doc = applyDocumentPayload(data.document as DocumentItem)
    await refreshPreviewFor(doc)

    if (doc.status === 'processing') {
      for (let i = 0; i < 40; i++) {
        if (i > 0) {
          await new Promise((r) => setTimeout(r, 1500))
        }
        const { data: polled } = await api.get(`/documents/${doc.id}`)
        doc = applyDocumentPayload(polled.document as DocumentItem)
        if (doc.status !== 'processing') {
          break
        }
      }
      await refreshPreviewFor(doc)
    }

    if (doc.ai_error) {
      replaceError.value = doc.ai_error
      notify({ title: t('msg_document_replaced_failed'), icon: 'warning' })
    } else {
      notify({ title: t('msg_document_replaced') })
    }
  } catch (e: any) {
    replaceError.value = e?.response?.data?.message || t('msg_could_not_replace')
    notify({ title: replaceError.value, icon: 'error' })
  } finally {
    replacing.value = false
  }
}

async function saveMeta() {
  if (!document.value || replacing.value) return
  saving.value = true
  const previousCategoryId = document.value.category_id
  const nextCategoryId = draft.value.category_id
  try {
    await ensureCsrf()
    const { data } = await api.patch(`/documents/${document.value.id}`, {
      title: draft.value.title || null,
      document_type: draft.value.document_type || null,
      document_date: draft.value.document_date || null,
      merchant: draft.value.merchant || null,
      reference_number: draft.value.reference_number || null,
      amount: centsDigitsToApiAmount(amountCentsDigits.value),
      currency: draft.value.currency || null,
    })
    document.value = {
      ...data.document,
      document_date: data.document.document_date
        ? String(data.document.document_date).slice(0, 10)
        : null,
    }
    syncDraft(document.value)
    editing.value = false

    if (
      isFiled.value
      && nextCategoryId
      && nextCategoryId !== previousCategoryId
    ) {
      selectedCategoryId.value = nextCategoryId
      await confirmFiling('confirm')
    } else {
      notify({ title: t('msg_details_saved') })
    }
  } catch (e: any) {
    notify({ title: e?.response?.data?.message || t('msg_could_not_save_details'), icon: 'error' })
  } finally {
    saving.value = false
  }
}

async function confirmFiling(mode: 'confirm' | 'create' = 'confirm') {
  if (!document.value || filing.value) return

  const payload: Record<string, unknown> = {}
  if (mode === 'create' || isNewCategorySuggestion.value) {
    const name = newCategoryName.value.trim() || document.value.suggested_category_name?.trim() || ''
    const existing = findCategoryByName(name)
    if (existing) {
      payload.category_id = existing.id
      selectedCategoryId.value = existing.id
    } else if (name) {
      payload.new_category_name = name
    } else {
      notify({ title: t('msg_no_category_suggestion'), icon: 'warning' })
      return
    }
  } else if (selectedCategoryId.value) {
    payload.category_id = selectedCategoryId.value
  } else {
    notify({ title: t('msg_choose_category_first'), icon: 'warning' })
    return
  }

  filing.value = true
  try {
    await ensureCsrf()
    const { data } = await api.post(`/documents/${document.value.id}/confirm`, payload)
    document.value = {
      ...data.document,
      document_date: data.document.document_date
        ? String(data.document.document_date).slice(0, 10)
        : null,
    }
    selectedCategoryId.value = document.value.category_id ?? ''
    await showSuccess({
      title: t('msg_filed_to_category', {
        category: data.document.category?.name || t('label_category'),
      }),
    })
    await loadCategories()
  } catch (e: any) {
    notify({ title: e?.response?.data?.message || t('msg_could_not_file'), icon: 'error' })
  } finally {
    filing.value = false
  }
}

async function loadCategories() {
  try {
    const { data } = await api.get('/categories')
    categories.value = data.categories
  } catch {
    // keep existing list
  }
}

async function changeCategory() {
  if (!document.value) return
  const choice = await promptCategoryChoice({
    categories: categories.value,
    selectedId: selectedCategoryId.value || document.value.category_id || '',
    allowNew: true,
    title: isFiled.value ? t('title_change_category') : t('title_choose_category'),
  })
  if (!choice) return

  if ('categoryId' in choice) {
    selectedCategoryId.value = choice.categoryId
    newCategoryName.value = categories.value.find((cat) => cat.id === choice.categoryId)?.name || ''
    if (document.value) {
      document.value = {
        ...document.value,
        suggests_new_category: false,
        suggested_category_name: newCategoryName.value || document.value.suggested_category_name,
      }
    }
    if (isFiled.value) {
      await confirmFiling('confirm')
    }
    return
  }

  const existing = findCategoryByName(choice.newCategoryName)
  if (existing) {
    selectedCategoryId.value = existing.id
    newCategoryName.value = existing.name
    if (document.value) {
      document.value = {
        ...document.value,
        suggests_new_category: false,
        suggested_category_name: existing.name,
      }
    }
    if (isFiled.value) {
      await confirmFiling('confirm')
    }
    return
  }

  selectedCategoryId.value = ''
  newCategoryName.value = choice.newCategoryName
  if (document.value) {
    document.value = {
      ...document.value,
      suggests_new_category: true,
      suggested_category_name: choice.newCategoryName,
    }
  }
}

async function onCategorySelect(event: Event) {
  const select = event.target as HTMLSelectElement
  const value = select.value
  if (value === '__new__') {
    select.value = selectedCategoryId.value ? String(selectedCategoryId.value) : ''
    await changeCategory()
    return
  }
  if (!value) {
    selectedCategoryId.value = ''
    return
  }
  selectedCategoryId.value = Number(value)
  const name = categories.value.find((cat) => cat.id === Number(value))?.name || ''
  newCategoryName.value = name
  if (document.value) {
    document.value = {
      ...document.value,
      suggests_new_category: false,
      suggested_category_name: name || document.value.suggested_category_name,
    }
  }
}

async function retry() {
  if (!document.value) return
  await ensureCsrf()
  const { data } = await api.post(`/documents/${document.value.id}/retry`)
  document.value = data.document
  notify({ title: t('msg_analysis_restarted') })
}

async function remove() {
  if (!document.value) return
  const ok = await confirmAction({
    title: t('title_delete_document'),
    text: t('msg_delete_document_confirm'),
    confirmText: t('action_delete_document'),
  })
  if (!ok) return
  await ensureCsrf()
  await api.delete(`/documents/${document.value.id}`)
  closeModal()
}

async function download() {
  if (!document.value) return
  const { data } = await api.get(`/documents/${document.value.id}/download`, { responseType: 'blob' })
  const url = URL.createObjectURL(data)
  const a = window.document.createElement('a')
  a.href = url
  a.download = document.value.original_filename
  a.click()
  URL.revokeObjectURL(url)
}

async function shareDocument() {
  if (!document.value || sharing.value) return
  if (!shareAvailable.value) {
    notify({ title: t('msg_share_unavailable'), icon: 'info' })
    return
  }
  sharing.value = true
  try {
    const { data } = await api.get(`/documents/${document.value.id}/download`, { responseType: 'blob' })
    const shared = await shareBlobFile(
      data,
      document.value.original_filename,
      document.value.mime_type,
      documentPrimary(document.value),
    )
    if (!shared) {
      notify({ title: t('msg_share_unavailable'), icon: 'warning' })
    }
  } catch (e: any) {
    if (e?.name !== 'AbortError') {
      notify({ title: e?.response?.data?.message || t('msg_could_not_share'), icon: 'error' })
    }
  } finally {
    sharing.value = false
  }
}

async function moveDocument() {
  if (!document.value || moving.value) return
  const currentProfileId = auth.activeProfile?.id ?? document.value.profile_id
  const choice = await promptMoveToProfile({
    profiles: auth.profiles,
    currentProfileId,
  })
  if (!choice) return

  moving.value = true
  await ensureCsrf()
  try {
    const payload =
      'categoryId' in choice
        ? { profile_id: choice.profileId, category_id: choice.categoryId }
        : { profile_id: choice.profileId, new_category_name: choice.newCategoryName }
    await api.post(`/documents/${document.value.id}/move`, payload)
    notify({ title: t('msg_document_moved') })
    closeModal()
  } catch (e: any) {
    notify({ title: e?.response?.data?.message || t('msg_could_not_move'), icon: 'error' })
  } finally {
    moving.value = false
  }
}

watch(id, () => {
  load()
})

onMounted(() => {
  shareAvailable.value = canShareFiles()
  window.addEventListener('keydown', onPreviewKeydown)
  load()
})

onUnmounted(() => {
  window.removeEventListener('keydown', onPreviewKeydown)
  window.document.body.style.overflow = ''
  if (previewUrl.value) URL.revokeObjectURL(previewUrl.value)
})
</script>

<template>
  <Teleport to="body">
    <div
      class="fixed inset-0 z-50 flex flex-col bg-white md:items-center md:justify-center md:bg-gray-950/40 md:p-4"
      role="dialog"
      aria-modal="true"
      :aria-label="t('aria_document_details')"
    >
      <button
        type="button"
        class="absolute inset-0 hidden cursor-default md:block"
        :aria-label="t('action_close')"
        @click="closeModal"
      />

      <div
        class="relative z-10 flex h-dvh w-full flex-col bg-white md:h-auto md:max-h-[min(44rem,92vh)] md:max-w-2xl md:rounded-2xl md:shadow-xl"
      >
        <div class="flex shrink-0 items-center gap-2 border-b border-gray-100 px-4 py-3 sm:px-6 sm:py-4">
          <button
            type="button"
            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl text-primary hover:bg-soft-blue"
            :aria-label="t('action_back')"
            @click="closeModal"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 19l-7-7 7-7" />
            </svg>
          </button>

          <div class="min-w-0 flex-1 text-center">
            <h2 class="truncate text-base font-bold text-primary">{{ t('title_document_details') }}</h2>
          </div>

          <button
            type="button"
            class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-primary hover:bg-soft-blue disabled:opacity-40"
            :aria-label="t('aria_share_document')"
            :disabled="!document || !canPreviewFile || sharing || !shareAvailable"
            @click="shareDocument"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z" />
            </svg>
          </button>
        </div>

        <div v-if="loading" class="px-5 py-8 text-sm text-gray-500 sm:px-6">{{ t('detail_loading') }}</div>

        <div v-else-if="loadError || !document" class="px-5 py-8 text-sm text-red-600 sm:px-6">
          {{ loadError || t('detail_not_found') }}
        </div>

        <div v-else-if="document" class="min-h-0 flex-1 space-y-4 overflow-y-auto px-5 py-4 sm:px-6">
          <p v-if="document.ai_error" class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
            {{ document.ai_error }}
            <button type="button" class="ml-2 cursor-pointer font-semibold underline" @click="retry">{{ t('action_retry_analysis') }}</button>
          </p>

          <!-- Inline file preview (matches mobile detail: 4:3, tap for full view) -->
          <button
            v-if="canPreviewFile"
            type="button"
            class="group relative w-full cursor-pointer overflow-hidden rounded-2xl border border-gray-100 bg-gray-50 text-left focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
            :aria-label="t('aria_view_document')"
            @click="viewDocument"
          >
            <div class="aspect-[4/3] w-full overflow-hidden">
              <div
                v-if="isImage && previewLoading"
                class="flex h-full items-center justify-center text-sm text-gray-500"
              >
                {{ t('detail_loading_preview') }}
              </div>
              <div
                v-else-if="isImage && previewError"
                class="flex h-full flex-col items-center justify-center gap-2 px-4 text-center"
              >
                <p class="text-sm text-gray-600">{{ previewError }}</p>
                <span class="text-xs font-semibold text-primary">{{ t('detail_tap_full_view') }}</span>
              </div>
              <img
                v-else-if="previewUrl && isImage"
                :src="previewUrl"
                :alt="document.original_filename"
                class="h-full w-full object-cover"
              />
              <div
                v-else-if="isPdf"
                class="flex h-full flex-col items-center justify-center gap-3 bg-gradient-to-b from-soft-blue/40 to-gray-50 px-4 text-center"
              >
                <span
                  class="inline-flex h-14 w-14 items-center justify-center rounded-2xl bg-white text-sm font-bold text-primary shadow-sm"
                >
                  PDF
                </span>
                <p class="max-w-[16rem] truncate text-sm font-semibold text-gray-800">
                  {{ document.original_filename }}
                </p>
                <p class="text-xs font-medium text-gray-500">{{ t('detail_tap_view') }}</p>
              </div>
              <div
                v-else
                class="flex h-full flex-col items-center justify-center gap-2 text-gray-500"
              >
                <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 3h7l5 5v13a1 1 0 01-1 1H7a1 1 0 01-1-1V4a1 1 0 011-1z" />
                </svg>
                <span class="text-sm font-medium">{{ t('detail_tap_view') }}</span>
              </div>
            </div>
            <span
              v-if="showStatusBadge"
              class="pointer-events-none absolute left-3 top-3"
            >
              <StatusBadge :status="document.status" />
            </span>
            <span
              class="pointer-events-none absolute bottom-3 right-3 rounded-full bg-gray-950/65 px-3 py-1.5 text-xs font-semibold text-white opacity-90 group-hover:opacity-100"
            >
              {{ t('action_view') }}
            </span>
          </button>
          <div
            v-else
            class="relative flex aspect-[4/3] w-full flex-col items-center justify-center gap-2 rounded-2xl border border-dashed border-gray-200 bg-gray-50 text-gray-500"
          >
            <span
              v-if="showStatusBadge"
              class="absolute left-3 top-3"
            >
              <StatusBadge :status="document.status" />
            </span>
            <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span class="text-sm font-medium">{{ t('msg_no_file_attached') }}</span>
          </div>

          <div v-if="editing" class="space-y-2">
            <input
              ref="replaceInput"
              type="file"
              class="hidden"
              accept="image/*,application/pdf"
              @change="onReplaceFileChange"
            />
            <button
              type="button"
              class="inline-flex min-h-10 w-full cursor-pointer items-center justify-center rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-60"
              :disabled="replacing || saving || filing"
              @click="openReplacePicker"
            >
              {{ replacing ? t('detail_replacing') : t('action_replace_document') }}
            </button>
            <p v-if="replaceError" class="text-xs text-red-600">{{ replaceError }}</p>
          </div>

          <div
            v-if="amountDisplay"
            class="rounded-2xl border border-gray-100 bg-white px-4 py-4"
          >
            <div class="flex items-center justify-between gap-2">
              <p class="text-xs font-medium text-gray-500">
                {{ amountDisplay.label || t('label_total_amount') }}
              </p>
              <span
                v-if="document.currency && !document.display_amount"
                class="rounded-lg bg-soft-blue px-2 py-0.5 text-[11px] font-semibold text-primary"
              >
                {{ document.currency }}
              </span>
            </div>
            <p class="mt-1 text-3xl font-bold tabular-nums text-primary">
              {{ amountDisplay.value }}
            </p>
          </div>

          <!-- Needs-review strip -->
          <div
            v-if="needsReview && !isNewCategorySuggestion"
            class="rounded-2xl border border-amber-100 bg-amber-50/60 p-3 sm:p-4"
          >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
              <label class="flex min-w-0 flex-1 flex-col gap-1.5 text-sm text-gray-700">
                <span class="font-medium">{{ t('label_file_under_plain') }}</span>
                <select
                  class="min-h-10 min-w-0 cursor-pointer rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-900 outline-none focus:border-primary"
                  :value="selectedCategoryId || ''"
                  :disabled="filing"
                  @change="onCategorySelect"
                >
                  <option value="">{{ t('label_choose_category') }}</option>
                  <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                  <option value="__new__">{{ t('label_create_new') }}</option>
                </select>
              </label>
              <button
                type="button"
                class="inline-flex min-h-10 shrink-0 cursor-pointer items-center justify-center rounded-xl bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="filing || !selectedCategoryId"
                @click="confirmFiling('confirm')"
              >
                {{ filing ? t('capture_filing') : t('action_confirm_filing') }}
              </button>
            </div>
          </div>

          <div
            v-else-if="needsReview && isNewCategorySuggestion"
            class="rounded-2xl border border-amber-100 bg-amber-50/60 p-3 sm:p-4"
          >
            <p class="text-sm text-gray-700">
              {{ t('capture_suggested_new_category') }}
              <span class="font-semibold text-gray-900">{{ document.suggested_category_name || t('capture_general_documents') }}</span>
            </p>
            <div class="mt-3 flex flex-col gap-2 sm:flex-row">
              <button
                type="button"
                class="inline-flex min-h-10 cursor-pointer items-center justify-center rounded-xl bg-primary px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-60"
                :disabled="filing"
                @click="confirmFiling('create')"
              >
                {{ t('capture_create_and_file', { category: document.suggested_category_name || t('capture_general_documents') }) }}
              </button>
              <button
                type="button"
                class="inline-flex min-h-10 cursor-pointer items-center justify-center rounded-xl border border-gray-200 bg-white px-3.5 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50"
                :disabled="filing"
                @click="changeCategory"
              >
                {{ t('capture_choose_existing') }}
              </button>
            </div>
          </div>

          <!-- Compact extras (view) / full form (edit) -->
          <section v-if="editing" class="space-y-4 text-sm">
            <h3 class="text-sm font-semibold text-gray-900">{{ t('title_edit_details') }}</h3>
            <div class="grid grid-cols-[9rem_1fr] items-center gap-x-3 gap-y-3 sm:grid-cols-[10rem_1fr]">
              <span class="text-gray-500">{{ t('label_display_name') }}</span>
              <input
                v-model="draft.title"
                class="w-full min-w-0 rounded-xl border border-gray-200 px-3 py-2 outline-none focus:border-primary disabled:opacity-60"
                :placeholder="t('placeholder_display_name')"
                :disabled="replacing"
              />

              <span class="text-gray-500">{{ t('label_document_type') }}</span>
              <input
                v-model="draft.document_type"
                class="w-full min-w-0 rounded-xl border border-gray-200 px-3 py-2 outline-none focus:border-primary disabled:opacity-60"
                :placeholder="t('placeholder_document_type')"
                :disabled="replacing"
              />

              <span class="text-gray-500">{{ t('label_document_date_field') }}</span>
              <input
                v-model="draft.document_date"
                type="date"
                class="w-full min-w-0 rounded-xl border border-gray-200 px-3 py-2 outline-none focus:border-primary disabled:opacity-60"
                :disabled="replacing"
              />

              <span class="text-gray-500">{{ t('label_issued_by') }}</span>
              <input
                v-model="draft.merchant"
                class="w-full min-w-0 rounded-xl border border-gray-200 px-3 py-2 outline-none focus:border-primary disabled:opacity-60"
                :placeholder="t('placeholder_issued_by')"
                :disabled="replacing"
              />

              <span class="text-gray-500">{{ t('label_invoice_ref') }}</span>
              <input
                v-model="draft.reference_number"
                class="w-full min-w-0 rounded-xl border border-gray-200 px-3 py-2 outline-none focus:border-primary disabled:opacity-60"
                :disabled="replacing"
              />

              <span class="text-gray-500">{{ t('label_total_amount') }}</span>
              <div class="grid min-w-0 grid-cols-[1fr_4.5rem] gap-2">
                <input
                  :value="amountFieldDisplay"
                  inputmode="numeric"
                  autocomplete="off"
                  class="w-full min-w-0 rounded-xl border border-gray-200 px-3 py-2 outline-none focus:border-primary disabled:opacity-60"
                  :aria-label="t('label_total_amount')"
                  :disabled="replacing"
                  @input="onAmountInput"
                />
                <input
                  v-model="draft.currency"
                  class="w-full min-w-0 rounded-xl border border-gray-200 px-3 py-2 outline-none focus:border-primary disabled:opacity-60"
                  placeholder="MYR"
                  :aria-label="t('label_currency')"
                  :disabled="replacing"
                />
              </div>

              <template v-if="isFiled || needsReview">
                <span class="text-gray-500">{{ t('label_filed_under') }}</span>
                <select
                  v-model="draft.category_id"
                  class="min-h-10 w-full cursor-pointer rounded-xl border border-gray-200 bg-white px-3 py-2 text-sm font-medium text-gray-900 outline-none focus:border-primary disabled:opacity-60"
                  :disabled="replacing"
                >
                  <option value="">{{ t('label_choose_category') }}</option>
                  <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                </select>
              </template>
            </div>
          </section>

          <section v-else class="space-y-2.5 text-sm">
            <div class="grid grid-cols-[9rem_1fr] items-baseline gap-x-3 gap-y-2.5 sm:grid-cols-[10rem_1fr]">
              <template v-if="document.display_facts?.length">
                <template v-for="(fact, index) in document.display_facts" :key="`${fact.label}-${index}`">
                  <span class="text-gray-500">{{ fact.label }}</span>
                  <span class="min-w-0 break-words font-medium text-gray-900">{{ fact.value }}</span>
                </template>
              </template>
              <template v-else>
                <span class="text-gray-500">{{ t('label_document_type') }}</span>
                <span class="min-w-0 font-medium text-gray-900">{{ document.document_type || '—' }}</span>

                <span class="text-gray-500">{{ t('label_document_date_field') }}</span>
                <span class="min-w-0 font-medium text-gray-900">{{ formatDisplayDate(document.document_date) }}</span>

                <span class="text-gray-500">{{ t('label_organisation') }}</span>
                <span class="min-w-0 break-words font-medium text-gray-900">{{ document.merchant || '—' }}</span>

                <span class="text-gray-500">{{ t('label_reference') }}</span>
                <span class="min-w-0 break-words font-medium text-gray-900">{{ document.reference_number || '—' }}</span>

                <span v-if="document.amount != null && document.amount !== ''" class="text-gray-500">{{ t('label_amount') }}</span>
                <span v-if="document.amount != null && document.amount !== ''" class="min-w-0 font-medium text-gray-900">
                  {{ documentFormatAmount(document) }}
                </span>
              </template>

              <template v-if="isFiled">
                <span class="text-gray-500">{{ t('label_filed_under') }}</span>
                <span class="min-w-0 font-medium text-gray-900">{{ document.category?.name || '—' }}</span>
              </template>

              <template v-if="document.title">
                <span class="text-gray-500">{{ t('label_display_name') }}</span>
                <span class="min-w-0 break-words font-medium text-gray-900">{{ document.title }}</span>
              </template>
            </div>
          </section>
        </div>

        <div
          v-if="document && editing"
          class="flex shrink-0 gap-2 border-t border-gray-100 px-4 py-3 sm:px-6"
        >
          <button
            type="button"
            class="inline-flex min-h-11 flex-1 cursor-pointer items-center justify-center rounded-xl border border-gray-200 bg-white px-3 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50"
            :disabled="saving || filing || replacing"
            @click="cancelEdit"
          >
            {{ t('action_cancel') }}
          </button>
          <button
            type="button"
            class="inline-flex min-h-11 flex-1 cursor-pointer items-center justify-center rounded-xl bg-primary px-3 py-2.5 text-sm font-semibold text-white hover:bg-blue-600 disabled:opacity-60"
            :disabled="saving || filing || replacing"
            @click="saveMeta"
          >
            {{ saving || filing ? t('action_saving') : t('action_save_changes') }}
          </button>
        </div>

        <div
          v-else-if="document && !editing"
          class="flex shrink-0 items-center gap-2.5 border-t border-gray-100 px-4 py-3 sm:px-6"
        >
          <button
            type="button"
            class="inline-flex min-h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-600"
            @click="startEdit"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
            </svg>
            {{ t('action_edit') }}
          </button>

          <button
            type="button"
            class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xl bg-soft-blue text-primary hover:bg-blue-100 disabled:opacity-50"
            :aria-label="t('aria_move_to_profile')"
            :disabled="moving"
            @click="moveDocument"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M3 7v10a2 2 0 002 2h3m10-12h3a2 2 0 012 2v10a2 2 0 01-2 2h-3M8 12h8m0 0l-3-3m3 3l-3 3" />
            </svg>
          </button>

          <button
            type="button"
            class="inline-flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-xl bg-red-50 text-red-600 hover:bg-red-100"
            :aria-label="t('aria_delete_document')"
            @click="remove"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3m-7 0h8" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </Teleport>

  <!-- Full-screen document preview: solid app bar above the viewer (never overlays the page) -->
  <Teleport to="body">
    <div
      v-if="showPreview"
      class="fixed inset-0 z-[60] flex flex-col bg-gray-950"
      role="dialog"
      aria-modal="true"
      :aria-label="t('aria_document_preview')"
    >
      <header
        class="flex shrink-0 items-center gap-2 border-b border-white/10 bg-gray-950 px-3 py-2.5 pt-[max(0.625rem,env(safe-area-inset-top))] sm:px-4"
      >
        <button
          type="button"
          class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-xl text-white hover:bg-white/10"
          :aria-label="t('aria_close_preview')"
          @click="closePreview"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
        <p class="min-w-0 flex-1 truncate text-sm font-semibold text-white">
          {{ document?.original_filename || t('label_document') }}
        </p>
        <button
          v-if="isPdf"
          type="button"
          class="inline-flex h-10 shrink-0 cursor-pointer items-center rounded-xl px-2.5 text-xs font-semibold text-white/90 hover:bg-white/10 sm:px-3 sm:text-sm"
          @click="openPdfInNewTab"
        >
          Open
        </button>
        <button
          type="button"
          class="inline-flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center rounded-xl text-white hover:bg-white/10"
          :aria-label="t('aria_download_document')"
          @click="download"
        >
          <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
          </svg>
        </button>
      </header>

      <div class="relative min-h-0 flex-1 bg-[#525659]">
        <div
          v-if="previewLoading"
          class="flex h-full flex-col items-center justify-center gap-3 text-sm text-white/70"
        >
          <span
            class="h-8 w-8 animate-spin rounded-full border-2 border-white/25 border-t-white"
            aria-hidden="true"
          />
          {{ t('detail_loading_preview') }}
        </div>

        <div
          v-else-if="previewError"
          class="flex h-full flex-col items-center justify-center gap-4 px-6 text-center"
        >
          <p class="max-w-sm text-sm text-white/80">{{ previewError }}</p>
          <button
            type="button"
            class="inline-flex min-h-11 cursor-pointer items-center rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-600"
            @click="download"
          >
            Download original
          </button>
        </div>

        <iframe
          v-else-if="pdfPreviewSrc"
          :src="pdfPreviewSrc"
          class="h-full w-full border-0"
          :title="t('aria_document_preview')"
        />

        <div
          v-else-if="previewUrl && isImage"
          class="flex h-full items-center justify-center overflow-auto bg-black px-3 py-4 pb-[max(1rem,env(safe-area-inset-bottom))]"
          @click.self="closePreview"
        >
          <img
            :src="previewUrl"
            :alt="document?.original_filename"
            class="max-h-full max-w-full rounded-lg object-contain shadow-2xl"
            @click.stop
          />
        </div>

        <div
          v-else
          class="flex h-full flex-col items-center justify-center gap-4 px-6 text-center"
        >
          <p class="text-sm text-white/80">{{ t('msg_preview_unavailable_type') }}</p>
          <button
            type="button"
            class="inline-flex min-h-11 cursor-pointer items-center rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-600"
            @click="download"
          >
            Download original
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
