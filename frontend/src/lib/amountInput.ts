/** Cash-register amount: digits are cents; display has commas + 2 decimals. */

export function amountToCentsDigits(value: string | number | null | undefined): string {
  if (value == null || String(value).trim() === '') return ''
  const n = Number(String(value).replace(/,/g, ''))
  if (!Number.isFinite(n)) return ''
  return String(Math.round(n * 100))
}

export function formatCentsDigits(digits: string): string {
  const cents = Number(digits || '0')
  if (!Number.isFinite(cents)) return '0.00'
  return (cents / 100).toLocaleString('en-MY', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
}

/** Plain numeric string for the API, e.g. "1000.00". */
export function centsDigitsToApiAmount(digits: string): string | null {
  if (!digits) return null
  const cents = Number(digits)
  if (!Number.isFinite(cents)) return null
  return (cents / 100).toFixed(2)
}

/** Keep only digit keystrokes / paste as a cents buffer. */
export function applyAmountDigits(nextRaw: string): string {
  const digits = nextRaw.replace(/\D/g, '')
  if (!digits || /^0+$/.test(digits)) return ''
  return digits.replace(/^0+/, '').slice(0, 15)
}

if (import.meta.env.DEV) {
  console.assert(formatCentsDigits('99') === '0.99', 'amountInput: 99 → 0.99')
  console.assert(formatCentsDigits('991') === '9.91', 'amountInput: 991 → 9.91')
  console.assert(formatCentsDigits('100000') === '1,000.00', 'amountInput: 100000 → 1,000.00')
  console.assert(centsDigitsToApiAmount('100000') === '1000.00', 'amountInput: api strip commas')
  console.assert(amountToCentsDigits('1,000.00') === '100000', 'amountInput: parse commas')
}
