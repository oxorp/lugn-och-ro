/**
 * Format a number with locale-aware thousands/decimal separators.
 * Swedish: 287 000   English: 287,000
 */
export function formatNumber(value: number, locale: string, options?: Intl.NumberFormatOptions): string {
    return new Intl.NumberFormat(locale === 'sv' ? 'sv-SE' : 'en', options).format(value);
}

/**
 * Format currency (SEK).
 * Swedish: 287 000 kr   English: SEK 287,000.00
 */
export function formatCurrency(value: number, locale: string): string {
    return new Intl.NumberFormat(locale === 'sv' ? 'sv-SE' : 'en', {
        style: 'currency',
        currency: 'SEK',
        maximumFractionDigits: 0,
    }).format(value);
}

/**
 * Format a percentage.
 * Swedish: 78,3 %   English: 78.3%
 */
export function formatPercent(value: number, locale: string, decimals = 1): string {
    return new Intl.NumberFormat(locale === 'sv' ? 'sv-SE' : 'en', {
        style: 'percent',
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(value / 100);
}

/**
 * Format a date.
 * Swedish: 2026-02-07   English: February 7, 2026
 */
export function formatDate(date: Date | string, locale: string, options?: Intl.DateTimeFormatOptions): string {
    const d = typeof date === 'string' ? new Date(date) : date;
    const defaultOptions: Intl.DateTimeFormatOptions = locale === 'sv'
        ? { year: 'numeric', month: '2-digit', day: '2-digit' }
        : { year: 'numeric', month: 'long', day: 'numeric' };
    return new Intl.DateTimeFormat(locale === 'sv' ? 'sv-SE' : 'en', options ?? defaultOptions).format(d);
}
