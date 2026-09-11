<?php

declare(strict_types=1);

namespace Drupal\canvas\Utility;

use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Resolves ISO date/time strings to locale-formatted strings.
 *
 * This is an opt-in utility for component creators. Props reach templates as
 * raw ISO strings by default. Use this service (or its Twig filter wrappers)
 * when you want to display a date in the active Drupal interface language's
 * locale format.
 *
 * @internal
 */
final class DateResolver {

  /**
   * Mirrors \IntlDateFormatter::NONE (-1).
   *
   * Defined locally so this class loads even when the intl extension is
   * absent; the fallback path does not call \IntlDateFormatter at all.
   */
  private const INTL_DATE_NONE = -1;

  /**
   * Mirrors \IntlDateFormatter::SHORT (3).
   *
   * Defined locally so this class loads even when the intl extension is
   * absent; the fallback path does not call \IntlDateFormatter at all.
   */
  private const INTL_DATE_SHORT = 3;

  /**
   * Locale fallback pattern groups for when intl is unavailable.
   *
   * This is not a complete list — only intl can provide that. This provides
   * format info based on the most common web traffic locales.
   *
   * The arrays are keyed by format pattern and list supported langcodes.
   * This keeps common patterns de-duplicated while still allowing explicit
   * regional overrides (e.g. en-gb vs en).
   *
   * @var array<string, array<string, list<string>>>
   */
  private const FALLBACK_PATTERN_GROUPS = [
    'date' => [
      'n/j/y' => ['en', 'en-us'],
      'j/n/Y' => ['en-za'],
      'd/m/Y' => [
        'en-gb',
        'en-in',
        'en-ng',
        'en-ke',
        'en-gh',
        'en-ug',
        'en-tz',
        'en-zw',
        'en-zm',
        'en-sg',
        'en-pk',
        'en-ie',
        'en-au',
        'en-nz',
        'fr',
        'fr-fr',
        'fr-ca',
        'fr-be',
        'fr-ch',
        'fr-dz',
        'fr-ma',
        'fr-tn',
        'fr-sn',
        'fr-ci',
        'fr-cm',
        'fr-cd',
        'es',
        'es-es',
        'es-mx',
        'it',
        'pt',
        'pt-br',
        'pt-pt',
        'pt-ao',
        'pt-mz',
        'ar',
        'ar-dz',
        'ar-ma',
        'ar-tn',
        'ar-eg',
        'he',
        'hi',
        'bn',
        'bn-bd',
        'ta',
        'te',
        'mr',
        'gu',
        'kn',
        'ml',
        'pa',
        'ur',
        'ms-sg',
        'zh-sg',
        'ta-sg',
        'af',
        'am',
        'sw',
        'yo',
        'ig',
        'ha',
        'zu',
        'xh',
        'st',
        'id',
        'ms',
        'th',
        'tr',
        'vi',
      ],
      'd.m.Y' => [
        'de',
        'de-de',
        'de-at',
        'de-ch',
        'cs',
        'sk',
        'pl',
        'ru',
        'uk',
        'bg',
        'sr',
        'hr',
        'sl',
      ],
      'd-m-Y' => ['nl', 'nl-nl', 'nl-be', 'da', 'nb', 'nn', 'sv', 'fi'],
      'Y/m/d' => ['ja', 'zh', 'zh-cn', 'zh-hk', 'zh-tw'],
      'Y.m.d' => ['ko'],
      'Y-m-d' => ['hu', 'lt', 'lv', 'et'],
    ],
    'date_time' => [
      'n/j/y, g:i A' => ['en', 'en-us'],
      'j/n/Y, H:i' => ['en-za'],
      'd/m/Y, H:i' => [
        'en-gb',
        'en-ie',
        'en-au',
        'en-nz',
        'en-ng',
        'en-ke',
        'en-gh',
        'en-ug',
        'en-tz',
        'en-zw',
        'en-zm',
        'en-sg',
        'en-pk',
      ],
      'd/m/Y, g:i A' => ['en-in'],
      'd/m/Y H:i' => [
        'fr',
        'fr-fr',
        'fr-ca',
        'fr-be',
        'fr-ch',
        'fr-dz',
        'fr-ma',
        'fr-tn',
        'fr-sn',
        'fr-ci',
        'fr-cm',
        'fr-cd',
        'es',
        'es-es',
        'es-mx',
        'it',
        'pt',
        'pt-br',
        'pt-pt',
        'pt-ao',
        'pt-mz',
        'ar',
        'ar-dz',
        'ar-ma',
        'ar-tn',
        'ar-eg',
        'he',
        'hi',
        'bn',
        'bn-bd',
        'ta',
        'te',
        'mr',
        'gu',
        'kn',
        'ml',
        'pa',
        'ur',
        'ms-sg',
        'zh-sg',
        'ta-sg',
        'af',
        'am',
        'sw',
        'yo',
        'ig',
        'ha',
        'zu',
        'xh',
        'st',
        'id',
        'ms',
        'th',
        'tr',
        'vi',
      ],
      'd.m.Y, H:i' => [
        'de',
        'de-de',
        'de-at',
        'de-ch',
        'cs',
        'sk',
        'pl',
        'ru',
        'uk',
        'bg',
        'sr',
        'hr',
        'sl',
      ],
      'd-m-Y H:i' => ['nl', 'nl-nl', 'nl-be', 'da', 'nb', 'nn', 'sv', 'fi'],
      'Y/m/d H:i' => ['ja', 'zh', 'zh-cn', 'zh-hk', 'zh-tw'],
      'Y.m.d H:i' => ['ko'],
      'Y-m-d H:i' => ['hu', 'lt', 'lv', 'et'],
    ],
    'time' => [
      'g:i A' => ['en', 'en-us'],
      'H:i' => [
        'en-gb',
        'en-in',
        'en-ng',
        'en-ke',
        'en-gh',
        'en-ug',
        'en-tz',
        'en-zw',
        'en-zm',
        'en-sg',
        'en-pk',
        'en-ie',
        'en-au',
        'en-nz',
        'en-za',
        'fr',
        'fr-fr',
        'fr-ca',
        'fr-be',
        'fr-ch',
        'de',
        'de-de',
        'de-at',
        'de-ch',
        'es',
        'es-es',
        'es-mx',
        'it',
        'pt',
        'pt-br',
        'pt-pt',
        'pt-ao',
        'pt-mz',
        'nl',
        'nl-nl',
        'nl-be',
        'da',
        'nb',
        'nn',
        'sv',
        'fi',
        'ja',
        'zh',
        'zh-cn',
        'zh-hk',
        'zh-tw',
        'ko',
        'cs',
        'sk',
        'pl',
        'ru',
        'uk',
        'bg',
        'sr',
        'hr',
        'sl',
        'hu',
        'lt',
        'lv',
        'et',
        'af',
        'am',
        'sw',
        'yo',
        'ig',
        'ha',
        'zu',
        'xh',
        'st',
        'ar',
        'ar-dz',
        'ar-ma',
        'ar-tn',
        'ar-eg',
        'he',
        'hi',
        'bn',
        'bn-bd',
        'ta',
        'te',
        'mr',
        'gu',
        'kn',
        'ml',
        'pa',
        'ur',
        'ms-sg',
        'zh-sg',
        'ta-sg',
        'id',
        'ms',
        'th',
        'tr',
        'vi',
      ],
    ],
  ];

  /**
   * Constructs a DateResolver.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager service, used to read the active interface
   *   language.
   * @param bool|null $intlAvailable
   *   For testing only: TRUE forces the intl path, FALSE forces the fallback
   *   path. NULL (default) auto-detects via extension_loaded('intl').
   */
  public function __construct(
    private readonly LanguageManagerInterface $languageManager,
    private readonly ?bool $intlAvailable = NULL,
  ) {}

  /**
   * Formats an ISO date string using the active locale's short date format.
   *
   * @param string $iso
   *   ISO date string, e.g. '2026-01-15'.
   *
   * @return string
   *   Formatted date, e.g. '1/15/26' (en) or '15/01/2026' (fr).
   *   Returns $iso unchanged if it cannot be parsed.
   */
  public function resolveDate(string $iso): string {
    return $this->resolveLocalizedDateTime(
      $iso,
      self::INTL_DATE_SHORT,
      self::INTL_DATE_NONE,
    );
  }

  /**
   * Formats an ISO datetime string using the active locale's short format.
   *
   * @param string $iso
   *   ISO datetime string, e.g. '2026-01-15T14:30:00Z'.
   *
   * @return string
   *   Formatted date+time string. Returns $iso unchanged if unparseable.
   */
  public function resolveDateTime(string $iso): string {
    return $this->resolveLocalizedDateTime(
      $iso,
      self::INTL_DATE_SHORT,
      self::INTL_DATE_SHORT,
    );
  }

  /**
   * Formats an ISO time/datetime string using the active locale's time format.
   *
   * @param string $iso
   *   ISO time or datetime string, e.g. '14:30:00' or
   *   '2026-01-15T14:30:00Z'.
   *
   * @return string
   *   Formatted time string. Returns $iso unchanged if it cannot be parsed.
   */
  public function resolveTime(string $iso): string {
    return $this->resolveLocalizedDateTime(
      $iso,
      self::INTL_DATE_NONE,
      self::INTL_DATE_SHORT,
    );
  }

  /**
   * Formats a date-range array ({from, to}) with locale-formatted dates.
   *
   * @param array{from?: string|null, to?: string|null} $value
   *   The date-range array with optional 'from' and 'to' keys.
   *
   * @return array{from?: string|null, to?: string|null}
   *   The same array with date values formatted in the active locale.
   */
  public function resolveDateRange(array $value): array {
    $result = $value;
    if (isset($result['from']) && \is_string($result['from'])) {
      $result['from'] = $this->resolveDate($result['from']);
    }
    if (isset($result['to']) && \is_string($result['to'])) {
      $result['to'] = $this->resolveDate($result['to']);
    }
    return $result;
  }

  /**
   * Tries intl formatting first; falls back to the static pattern map.
   *
   * Both paths produce a locale-appropriate short format. The intl path
   * delegates to ICU data (\IntlDateFormatter), which gives accurate results
   * for every locale. The fallback path uses a built-in PHP date() pattern map
   * covering 90%+ of web traffic locales, for environments (e.g. Drupal CI)
   * where ext-intl is not installed.
   *
   * @param string $iso
   *   ISO date/time string.
   * @param int $date_type
   *   \IntlDateFormatter date type constant (NONE or SHORT).
   * @param int $time_type
   *   \IntlDateFormatter time type constant (NONE or SHORT).
   *
   * @return string
   *   Formatted string, or $iso unchanged if parsing or formatting fails.
   */
  private function resolveLocalizedDateTime(
    string $iso,
    int $date_type,
    int $time_type,
  ): string {
    if ($iso === '') {
      return $iso;
    }
    try {
      $date_time = new \DateTime($iso);
    }
    catch (\Exception) {
      return $iso;
    }

    $langcode = $this->languageManager->getCurrentLanguage()->getId();

    if ($this->isIntlAvailable()) {
      foreach (self::buildLocaleCandidates($langcode) as $locale) {
        try {
          $formatter = \IntlDateFormatter::create(
            $locale,
            $date_type,
            $time_type,
          );
        }
        catch (\ValueError) {
          continue;
        }
        if (!$formatter instanceof \IntlDateFormatter) {
          continue;
        }
        try {
          $formatted = $formatter->format($date_time);
        }
        catch (\Error) {
          // Some ICU setups return a not-constructed formatter for unsupported
          // locales. Try the next candidate.
          continue;
        }
        if (\is_string($formatted)) {
          return $formatted;
        }
      }
    }

    $fallback = self::formatWithFallback(
      $date_time,
      $date_type,
      $time_type,
      $langcode,
    );
    return \is_string($fallback) ? $fallback : $iso;
  }

  /**
   * Returns whether the intl PHP extension is available.
   */
  private function isIntlAvailable(): bool {
    return $this->intlAvailable ?? \extension_loaded('intl');
  }

  /**
   * Formats a DateTime using the built-in fallback locale map.
   *
   * Used when the intl extension is unavailable. Covers languages used by
   * 90%+ of web traffic; falls back to the raw ISO string for other locales.
   *
   * @param \DateTimeInterface $date_time
   *   The date/time to format.
   * @param int $date_type
   *   IntlDateFormatter date type constant (NONE or SHORT).
   * @param int $time_type
   *   IntlDateFormatter time type constant (NONE or SHORT).
   * @param string $langcode
   *   The Drupal langcode.
   *
   * @return string|null
   *   Formatted string, or NULL if no pattern found for this locale.
   */
  private static function formatWithFallback(
    \DateTimeInterface $date_time,
    int $date_type,
    int $time_type,
    string $langcode,
  ): ?string {
    $format_key = match ([$date_type, $time_type]) {
      [self::INTL_DATE_SHORT, self::INTL_DATE_NONE] => 'date',
      [self::INTL_DATE_SHORT, self::INTL_DATE_SHORT] => 'date_time',
      [self::INTL_DATE_NONE, self::INTL_DATE_SHORT] => 'time',
      default => NULL,
    };
    if (!\is_string($format_key)) {
      return NULL;
    }

    $normalized = \strtolower($langcode);
    $base = \str_contains($normalized, '-')
      ? \explode('-', $normalized, 2)[0]
      : $normalized;

    $locale_map = self::buildFallbackLocaleMap($format_key);
    $pattern = $locale_map[$normalized] ?? $locale_map[$base] ?? NULL;
    return \is_string($pattern) ? $date_time->format($pattern) : NULL;
  }

  /**
   * Builds and caches the inverted fallback locale-to-pattern map.
   *
   * @param string $format_key
   *   Key into FALLBACK_PATTERN_GROUPS ('date', 'date_time', or 'time').
   *
   * @return array<string, string>
   *   Flat map of langcode to PHP date() format string.
   */
  private static function buildFallbackLocaleMap(string $format_key): array {
    static $cache = [];
    if (isset($cache[$format_key])) {
      return $cache[$format_key];
    }
    $map = [];
    foreach (
      self::FALLBACK_PATTERN_GROUPS[$format_key] ?? [] as $pattern => $codes
    ) {
      foreach ($codes as $lc) {
        $map[$lc] = $pattern;
      }
    }
    $cache[$format_key] = $map;
    return $map;
  }

  /**
   * Builds a list of BCP 47 locale candidates from a Drupal langcode.
   *
   * Tries region-qualified, canonical (via Locale::canonicalize), and base
   * forms to maximize IntlDateFormatter compatibility.
   *
   * @param string $langcode
   *   Drupal langcode, e.g. 'en', 'fr', 'en-gb', 'zh-hans'.
   *
   * @return list<string>
   *   Ordered list of locale candidates to try.
   */
  private static function buildLocaleCandidates(string $langcode): array {
    $candidates = [];
    $add = static function (string $c) use (&$candidates): void {
      if ($c !== '' && !\in_array($c, $candidates, TRUE)) {
        $candidates[] = $c;
      }
    };
    $language = $langcode;
    $region = '';
    if (\str_contains($langcode, '-')) {
      [$language, $region] = \explode('-', $langcode, 2);
    }
    if ($region !== '') {
      $add($language . '_' . \strtoupper($region));
    }
    if (\class_exists(\Locale::class)) {
      $canonical = \Locale::canonicalize($langcode);
      if (\is_string($canonical)) {
        $add($canonical);
      }
    }
    $add($langcode);
    $add($language);
    return $candidates;
  }

}
