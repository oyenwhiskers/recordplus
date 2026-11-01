import type { DocumentItem } from '@/types'
import { i18n } from '@/i18n'

export function primary(doc: Pick<DocumentItem, 'display_primary' | 'title' | 'original_filename' | 'id'> | null | undefined): string {
  if (!doc) return ''
  if (doc.display_primary) return doc.display_primary
  return doc.title || doc.original_filename || i18n.global.t('document_fallback', { id: doc.id })
}

export function secondary(doc: Pick<DocumentItem, 'display_secondary'> | null | undefined): string | null {
  if (!doc?.display_secondary) return null
  return doc.display_secondary
}

/** Format money with thousand separators, e.g. MYR 12,250.00 */
export function formatAmount(
  doc: Pick<DocumentItem, 'amount' | 'currency'> | null | undefined,
): string {
  if (doc?.amount == null || String(doc.amount).trim() === '') return i18n.global.t('label_em_dash')
  const amount = Number(doc.amount)
  const formatted = Number.isFinite(amount)
    ? amount.toLocaleString('en-MY', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    : String(doc.amount)
  return `${doc.currency?.trim() || 'MYR'} ${formatted}`
}

export function hasAmount(
  doc: Pick<DocumentItem, 'amount'> | null | undefined,
): boolean {
  return doc?.amount != null && String(doc.amount).trim() !== ''
}

function formatDayMonthYear(date: Date): string {
  return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
}

function formatClock(date: Date): string {
  return date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
}

/** Document date + capture time; falls back to created_at when undated. */
export function whenLine(
  doc: Pick<DocumentItem, 'document_date' | 'created_at'> | null | undefined,
): string | null {
  if (!doc) return null

  const created = doc.created_at ? new Date(doc.created_at) : null
  const createdOk = created !== null && !Number.isNaN(created.getTime())

  if (doc.document_date) {
    const raw = String(doc.document_date).slice(0, 10)
    const parsed = new Date(`${raw}T00:00:00`)
    if (!Number.isNaN(parsed.getTime())) {
      // shortcut: document_date has no clock time — append created_at time until extracted times exist
      return createdOk
        ? `${formatDayMonthYear(parsed)} · ${formatClock(created)}`
        : formatDayMonthYear(parsed)
    }
  }

  if (!createdOk || created === null) return null

  return `${formatDayMonthYear(created)} · ${formatClock(created)}`
}

/** Date-only label preferring document_date over upload time. */
export function documentDateLabel(
  doc: Pick<DocumentItem, 'document_date' | 'created_at'> | null | undefined,
): string | null {
  if (!doc) return null

  if (doc.document_date) {
    const raw = String(doc.document_date).slice(0, 10)
    const parsed = new Date(`${raw}T00:00:00`)
    if (!Number.isNaN(parsed.getTime())) {
      return formatDayMonthYear(parsed)
    }
  }

  const created = doc.created_at ? new Date(doc.created_at) : null
  if (created !== null && !Number.isNaN(created.getTime())) {
    return formatDayMonthYear(created)
  }

  return null
}

/** Type · date only (amount shown separately in list rows). */
export function typeDateLine(
  doc: Pick<DocumentItem, 'document_type' | 'document_date'> | null | undefined,
): string | null {
  if (!doc) return null

  const parts: string[] = []
  if (doc.document_type?.trim()) {
    parts.push(doc.document_type.trim())
  }

  if (doc.document_date) {
    const raw = String(doc.document_date).slice(0, 10)
    const parsed = new Date(`${raw}T00:00:00`)
    if (!Number.isNaN(parsed.getTime())) {
      parts.push(formatDayMonthYear(parsed))
    }
  }

  return parts.length ? parts.join(' · ') : null
}

export function detailLine(
  doc: Pick<DocumentItem, 'reference_number' | 'display_secondary'> | null | undefined,
): string | null {
  const reference = doc?.reference_number?.trim()
  if (!reference) return null
  const secondaryText = doc?.display_secondary?.trim()
  if (secondaryText && secondaryText.includes(reference)) {
    return null
  }
  return reference
}

/** Compact metadata line: type · date · amount (only present fields). */
export function meta(
  doc: Pick<DocumentItem, 'document_type' | 'document_date' | 'amount' | 'currency'> | null | undefined,
): string | null {
  if (!doc) return null

  const parts: string[] = []
  const typeDate = typeDateLine(doc)
  if (typeDate) {
    parts.push(typeDate)
  }

  if (hasAmount(doc)) {
    parts.push(formatAmount(doc))
  }

  return parts.length ? parts.join(' · ') : null
}
