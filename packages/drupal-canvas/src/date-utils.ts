/**
 * Locale-aware date formatting utilities for Canvas Code Components.
 *
 * These functions format ISO date/time strings using the Drupal-selected
 * locale from `drupalSettings.langcode`. They are opt-in: Canvas passes date
 * props to templates as raw ISO strings by default. Use these utilities when
 * you want to display a date in the user's locale.
 *
 * For custom date styles beyond 'short', use `Intl.DateTimeFormat` directly.
 *
 * **Cache context**: On multilingual sites, render arrays that use these
 * utilities must include the `languages:language_interface` cache context
 * to ensure correct cache invalidation when the language changes.
 *
 * @example Twig: `{{ my_date_prop|canvasFormatDate }}`
 * @example JSX: `canvasFormatDate(myDateProp)`
 */

/** Reads the Drupal-selected locale from drupalSettings, falling back to 'en'. */
const getDrupalLocale = (): string =>
  (globalThis as { drupalSettings?: { langcode?: string } }).drupalSettings
    ?.langcode ?? 'en';

/**
 * Creates an `Intl.DateTimeFormat` instance, falling back to 'en' locale if
 * the Drupal locale string is not a valid BCP 47 tag.
 *
 * Drupal langcodes like `zh-hans` or `pt-br` use lowercase language tag
 * components which may throw `RangeError` in strict BCP 47 environments.
 * The try/catch ensures graceful degradation.
 */
const createFormatter = (
  locale: string,
  options: Intl.DateTimeFormatOptions,
): Intl.DateTimeFormat => {
  try {
    return new Intl.DateTimeFormat(locale, options);
  } catch {
    return new Intl.DateTimeFormat('en', options);
  }
};

/** Parses an ISO string to a Date object, returning null on failure. */
const parseIso = (iso: string): Date | null => {
  if (!iso) return null;
  const d = new Date(iso);
  return isNaN(d.getTime()) ? null : d;
};

/**
 * Formats an ISO date string using the active Drupal locale's short date format.
 *
 * Returns the original `iso` string unchanged if it cannot be parsed.
 *
 * @param iso
 *   ISO date string, e.g. '2026-01-15'.
 *
 * @return
 *   Locale-formatted date string, e.g. '1/15/26' (en) or '15/01/2026' (fr).
 */
export function canvasFormatDate(iso: string): string {
  const date = parseIso(iso);
  if (!date) return iso;
  return createFormatter(getDrupalLocale(), { dateStyle: 'short' }).format(
    date,
  );
}

/**
 * Formats an ISO datetime string using the active Drupal locale's short date+time format.
 *
 * Returns the original `iso` string unchanged if it cannot be parsed.
 *
 * @param iso
 *   ISO datetime string, e.g. '2026-01-15T14:30:00Z'.
 *
 * @return
 *   Locale-formatted datetime string.
 */
export function canvasFormatDateTime(iso: string): string {
  const date = parseIso(iso);
  if (!date) return iso;
  return createFormatter(getDrupalLocale(), {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(date);
}

/**
 * Formats an ISO time or datetime string using the active Drupal locale's short time format.
 *
 * Returns the original `iso` string unchanged if it cannot be parsed.
 *
 * @param iso
 *   ISO time or datetime string, e.g. '14:30:00' or '2026-01-15T14:30:00Z'.
 *
 * @return
 *   Locale-formatted time string.
 */
export function canvasFormatTime(iso: string): string {
  // For bare time strings like 'HH:MM:SS', prefix a dummy date so Date() can parse them.
  const normalized = /^\d{2}:\d{2}/.test(iso) ? `1970-01-01T${iso}` : iso;
  const date = parseIso(normalized);
  if (!date) return iso;
  return createFormatter(getDrupalLocale(), { timeStyle: 'short' }).format(
    date,
  );
}

/**
 * Formats the `from` and `to` fields of a Canvas date-range prop using the active Drupal locale.
 *
 * @param range
 *   Canvas date-range object, e.g. `{ from: '2026-01-15', to: '2026-03-20' }`.
 *
 * @return
 *   A new object with the same keys; each string date value is replaced with a
 *   formatted string.
 */
export function canvasFormatDateRange<
  T extends { from?: string | null; to?: string | null },
>(range: T): T {
  const result = { ...range };
  if (typeof result.from === 'string') {
    result.from = canvasFormatDate(result.from) as T['from'];
  }
  if (typeof result.to === 'string') {
    result.to = canvasFormatDate(result.to) as T['to'];
  }
  return result;
}
