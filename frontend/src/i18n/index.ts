import { createI18n } from 'vue-i18n'
import en from './locales/en'
import id from './locales/id'
import ms from './locales/ms'
import zh from './locales/zh'

export const LOCALE_STORAGE_KEY = 'app_locale_code'

export const supportedLocales = ['en', 'ms', 'zh', 'id'] as const

export type SupportedLocale = (typeof supportedLocales)[number]

export type MessageSchema = typeof en

/** Native labels stay fixed — never translated with the UI locale. */
export const languageOptions: { code: SupportedLocale; label: string }[] = [
  { code: 'en', label: 'English' },
  { code: 'ms', label: 'Bahasa Melayu' },
  { code: 'zh', label: '中文' },
  { code: 'id', label: 'Bahasa Indonesia' },
]

function isSupportedLocale(value: string | null | undefined): value is SupportedLocale {
  return Boolean(value && (supportedLocales as readonly string[]).includes(value))
}

export function getStoredLocale(): SupportedLocale {
  try {
    const stored = localStorage.getItem(LOCALE_STORAGE_KEY)
    if (isSupportedLocale(stored)) {
      return stored
    }
  } catch {
    // ignore storage access errors (private mode, etc.)
  }
  return 'en'
}

export function applyDocumentLang(code: SupportedLocale = getStoredLocale()): void {
  if (typeof document !== 'undefined') {
    document.documentElement.lang = code
  }
}

export const i18n = createI18n<[MessageSchema], SupportedLocale>({
  legacy: false,
  locale: getStoredLocale(),
  fallbackLocale: {
    id: ['ms', 'en'],
    default: ['en'],
  },
  messages: {
    en,
    ms,
    zh,
    id,
  },
})

export function setLocale(code: string): SupportedLocale {
  const next: SupportedLocale = isSupportedLocale(code) ? code : 'en'
  try {
    localStorage.setItem(LOCALE_STORAGE_KEY, next)
  } catch {
    // ignore
  }
  i18n.global.locale.value = next
  applyDocumentLang(next)
  return next
}

applyDocumentLang(i18n.global.locale.value)

export default i18n
