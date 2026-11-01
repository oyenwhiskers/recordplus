export interface CurrencyOption {
  code: string
  country: string
}

export const currencyOptions: CurrencyOption[] = [
  { code: 'MYR', country: 'Malaysia' },
  { code: 'SGD', country: 'Singapore' },
  { code: 'USD', country: 'United States' },
  { code: 'EUR', country: 'European Union' },
  { code: 'GBP', country: 'United Kingdom' },
  { code: 'JPY', country: 'Japan' },
  { code: 'AUD', country: 'Australia' },
  { code: 'CNY', country: 'China' },
  { code: 'THB', country: 'Thailand' },
  { code: 'IDR', country: 'Indonesia' },
]

export function currencyLabel(code: string): string {
  const option = currencyOptions.find((c) => c.code === code)
  return option ? `${option.code} - ${option.country}` : code
}
