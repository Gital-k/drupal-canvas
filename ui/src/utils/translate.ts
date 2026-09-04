import { getDrupal } from '@/utils/drupal-globals';

type TranslationArgs = Record<string, string | number>;
type TranslationOptions = {
  context?: string;
};

export const translate = (
  source: string,
  args?: TranslationArgs,
  options?: TranslationOptions,
): string => {
  const drupal = getDrupal();
  if (typeof drupal?.t === 'function') {
    return drupal.t(source, args, options);
  }
  return source;
};

export const translatePlural = (
  count: number,
  singular: string,
  plural: string,
  args?: TranslationArgs,
  options?: TranslationOptions,
): string => {
  const drupal = getDrupal();
  if (typeof drupal?.formatPlural === 'function') {
    return drupal.formatPlural(count, singular, plural, args, options);
  }
  return count === 1 ? singular : plural;
};
