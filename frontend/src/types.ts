export type DocumentStatus =
  | 'processing'
  | 'filed'
  | 'needs_review'
  | 'uncategorised'
  | 'processing_failed'

export interface Category {
  id: number
  profile_id: number
  name: string
  documents_count?: number
}

export interface Profile {
  id: number
  user_id: number
  name: string
  is_default: boolean
  base_currency: string
  categories?: Category[]
}

export interface User {
  id: number
  name: string
  email: string
  ai_enabled: boolean
  is_admin: boolean
  active_profile_id: number | null
  active_profile?: Profile | null
  profiles?: Profile[]
}

export interface DocumentAmountFact {
  value: string
  currency?: string | null
  role: string
  payment_status?: string | null
  related_date?: string | null
  uncertain?: boolean
}

export interface DocumentDateFact {
  value: string
  role: string
  uncertain?: boolean
}

export interface DocumentDisplayFact {
  label: string
  value: string
}

export interface DocumentCapabilities {
  has_expiry: boolean
  has_money: boolean
  expense_eligible: boolean
  has_coverage: boolean
  has_balance: boolean
  expiry_date: string | null
  expiry_role: string | null
}

export interface DocumentItem {
  id: number
  profile_id: number
  category_id: number | null
  status: DocumentStatus
  document_type: string | null
  title: string | null
  display_primary?: string
  display_secondary?: string | null
  display_facts?: DocumentDisplayFact[]
  display_amount?: string | null
  display_amount_label?: string | null
  capabilities?: DocumentCapabilities
  document_date: string | null
  merchant: string | null
  reference_number: string | null
  amount: string | null
  currency: string | null
  suggested_category_name: string | null
  suggests_new_category: boolean
  original_filename: string
  mime_type?: string | null
  ai_error: string | null
  created_at: string
  extracted_data?: {
    amounts?: DocumentAmountFact[]
    dates?: DocumentDateFact[]
    interpretation?: {
      has_structured_money_dates?: boolean
      expense_eligible?: boolean
      primary_amount_role?: string | null
      primary_date_role?: string | null
    }
    [key: string]: unknown
  } | null
  category?: Category | null
}

export interface HomeCategorySummary {
  id: number
  name: string
  documents_count: number
  display_amount?: string | null
}

export interface HomeData {
  year: number
  years: number[]
  totals: {
    categories: number
    documents: number
  }
  categories: HomeCategorySummary[]
  needs_review: number
}
