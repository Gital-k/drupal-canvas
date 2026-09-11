import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import {
  canvasFormatDate,
  canvasFormatDateRange,
  canvasFormatDateTime,
  canvasFormatTime,
} from './date-utils';

/** Helper to set drupalSettings.langcode for the duration of a test. */
function setLocale(langcode: string): void {
  (globalThis as { drupalSettings?: { langcode?: string } }).drupalSettings = {
    langcode,
  };
}

/** Helper to clear drupalSettings after each test. */
function clearLocale(): void {
  delete (globalThis as { drupalSettings?: { langcode?: string } })
    .drupalSettings;
}

describe('canvasFormatDate', () => {
  beforeEach(() => setLocale('en'));
  afterEach(() => clearLocale());

  it('returns a formatted date string for a valid ISO date (en)', () => {
    const result = canvasFormatDate('2026-01-15');
    // Short date format contains digits and a separator — not the raw ISO.
    expect(result).toMatch(/\d+[/\-.]\d+/);
    expect(result).not.toBe('2026-01-15');
  });

  it('returns the original string for an empty input', () => {
    expect(canvasFormatDate('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(canvasFormatDate('not-a-date')).toBe('not-a-date');
  });

  it('does not shift a date-only value to the previous day', () => {
    // ECMAScript parses date-only strings (YYYY-MM-DD) as UTC midnight.
    // Without timeZone: 'UTC', Intl.DateTimeFormat renders in local time,
    // which shifts midnight UTC to the previous evening in negative-offset
    // timezones (e.g. Americas), producing the wrong date.
    const result = canvasFormatDate('2026-09-08');
    expect(result).toMatch(/9\/8/);
    expect(result).not.toMatch(/9\/7/);
  });

  it('formats differently for fr locale', () => {
    setLocale('fr');
    const result = canvasFormatDate('2026-01-15');
    expect(result).not.toBe('2026-01-15');
    expect(result).not.toBe('');
  });

  it('formats for ja locale without throwing', () => {
    setLocale('ja');
    const result = canvasFormatDate('2026-01-15');
    expect(result).not.toBe('2026-01-15');
    expect(result).not.toBe('');
  });

  it('falls back gracefully for an invalid locale string', () => {
    setLocale('invalid-LOCALE-999');
    // Should not throw; falls back to 'en' internally.
    const result = canvasFormatDate('2026-01-15');
    expect(typeof result).toBe('string');
    expect(result).not.toBe('');
  });

  it('uses "en" fallback when drupalSettings is absent', () => {
    clearLocale();
    const result = canvasFormatDate('2026-01-15');
    expect(result).toMatch(/\d+[/\-.]\d+/);
    expect(result).not.toBe('2026-01-15');
  });
});

describe('canvasFormatDateTime', () => {
  beforeEach(() => setLocale('en'));
  afterEach(() => clearLocale());

  it('returns a formatted datetime string for a valid ISO datetime', () => {
    const result = canvasFormatDateTime('2026-01-15T14:30:00Z');
    expect(result).not.toBe('2026-01-15T14:30:00Z');
    expect(result).not.toBe('');
  });

  it('does not shift the time by the local UTC offset', () => {
    // Canvas datetime props are stored in UTC without timezone awareness.
    // Formatting must not apply a local-timezone offset — e.g. a value stored
    // as 23:00 UTC must never appear as 03:00 the next day in an ET browser.
    // timeZone: 'UTC' in the formatter guarantees this.
    const result = canvasFormatDateTime('2026-09-08T23:00:00Z');
    // The date portion must always be Sept 8, never Sept 9.
    expect(result).toMatch(/9\/8/);
    expect(result).not.toMatch(/9\/9/);
  });

  it('returns the original string for an empty input', () => {
    expect(canvasFormatDateTime('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(canvasFormatDateTime('not-a-date')).toBe('not-a-date');
  });
});

describe('canvasFormatTime', () => {
  beforeEach(() => setLocale('en'));
  afterEach(() => clearLocale());

  it('returns a formatted time string for a bare HH:MM:SS time', () => {
    const result = canvasFormatTime('14:30:00');
    expect(result).not.toBe('14:30:00');
    expect(result).not.toBe('');
    // Short time format contains digits and a colon.
    expect(result).toMatch(/\d+:\d+/);
  });

  it('returns a formatted time for a full ISO datetime string', () => {
    const result = canvasFormatTime('2026-01-15T14:30:00Z');
    expect(result).not.toBe('2026-01-15T14:30:00Z');
    expect(result).not.toBe('');
  });

  it('returns the original string for an empty input', () => {
    expect(canvasFormatTime('')).toBe('');
  });

  it('returns the original string for an invalid ISO input', () => {
    expect(canvasFormatTime('not-a-time')).toBe('not-a-time');
  });
});

describe('canvasFormatDateRange', () => {
  beforeEach(() => setLocale('en'));
  afterEach(() => clearLocale());

  it('formats both from and to fields', () => {
    const result = canvasFormatDateRange({
      from: '2026-01-15',
      to: '2026-03-20',
    });
    expect(result.from).not.toBe('2026-01-15');
    expect(result.to).not.toBe('2026-03-20');
    expect(typeof result.from).toBe('string');
    expect(typeof result.to).toBe('string');
  });

  it('passes null fields through unchanged', () => {
    const result = canvasFormatDateRange({ from: '2026-01-15', to: null });
    expect(result.from).not.toBe('2026-01-15');
    expect(result.to).toBeNull();
  });

  it('handles absent fields without error', () => {
    const result = canvasFormatDateRange<{
      from?: string | null;
      to?: string | null;
    }>({ from: '2026-01-15' });
    expect(result.from).not.toBe('2026-01-15');
    expect(result.to).toBeUndefined();
  });

  it('returns a new object (does not mutate the input)', () => {
    const input = { from: '2026-01-15', to: '2026-03-20' };
    canvasFormatDateRange(input);
    expect(input.from).toBe('2026-01-15');
    expect(input.to).toBe('2026-03-20');
  });

  it('passes through empty strings unchanged', () => {
    const result = canvasFormatDateRange({ from: '', to: '' });
    expect(result.from).toBe('');
    expect(result.to).toBe('');
  });
});
