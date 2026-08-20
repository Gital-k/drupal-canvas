/**
 * Provides utility functions for the PublishReview components
 */

import { differenceInMonths, format, formatDistanceToNow } from 'date-fns';
import { kebabCase } from 'lodash';

import { FallbackColor } from '@/types/Review';

import type { Owner } from '@/services/pendingChangesApi';
import type { UnpublishedChange } from '@/types/Review';

// @todo https://www.drupal.org/i/3501449 - this color assignment should be replaced with a proper solution
const colors = Object.values(FallbackColor);

/**
 * Hashes a string to a 32-bit unsigned integer, using the FNV-1a algorithm.
 *
 * @param value
 */
function hash(value: string): number {
  let result = 0x811c9dc5;
  for (let i = 0; i < value.length; i++) {
    result ^= value.charCodeAt(i);
    // Multiply by the FNV prime (16777619) without overflowing to a float.
    result = Math.imul(result, 0x01000193);
  }
  return result >>> 0;
}

/**
 * Function to get a consistent color for a given user.
 *
 * The color is derived by hashing the owner, so a given user always gets the
 * same color: across page loads, across browsers, and regardless of the order
 * in which users are encountered.
 *
 * @param owner
 */
export function getAvatarInitialColor(owner: Owner): FallbackColor {
  // Hash the fields in a fixed order, so that the result never depends on
  // the key order of the decoded JSON. Newlines cannot occur in any of the
  // fields, so distinct owners cannot produce the same string.
  const identity = `${owner.id}\n${owner.name}\n${owner.uri}\n${owner.avatar ?? ''}`;
  return colors[hash(identity) % colors.length];
}

/**
 * Returns a human-readable label for a given entity type.
 *
 * Maps known entity type strings to their corresponding group labels.
 * If the entity type is not recognized, returns a kebab-case version of the input.
 *
 * @param entityType - The type of the entity to get the label for.
 * @returns The group label corresponding to the entity type.
 */
export function getGroupLabel(entityType: string): string {
  switch (entityType) {
    case 'node':
      return 'Content';
    case 'canvas_page':
      return 'Pages';
    case 'js_component':
      return 'Components';
    case 'asset_library':
      return 'Assets';
    case 'brand_kit':
      return 'Brand kit';
    case 'page_region':
      return 'Regions';
    case 'page_variant':
      return 'Page templates';
    case 'staged_config_update':
      return 'Configuration updates';
    case 'content_template':
      return 'Content templates';
    case 'pattern':
      return 'Patterns';
    default:
      return kebabCase(entityType);
  }
}

export function getReviewGroupKey(entityType: string): string {
  switch (entityType) {
    case 'brand_kit':
      return 'asset_library';
    default:
      return entityType;
  }
}

export function getChangeLabel(
  change: Pick<UnpublishedChange, 'entity_type' | 'label'>,
): string {
  switch (change.entity_type) {
    case 'brand_kit':
      return 'Brand kit';
    default:
      return change.label;
  }
}

/**
 * Returns a human-readable string representing the time elapsed since the given timestamp.
 *
 * - If the timestamp is older than one month, the date is formatted as "d MMM" (e.g., "5 Jan").
 * - For more recent dates, a relative time string is returned (e.g., "2h ago", "5m ago"),
 *   with units abbreviated according to a custom mapping.
 *
 * @param timestamp - The UNIX timestamp (in seconds) to calculate the elapsed time from.
 * @returns A formatted string indicating how long ago the timestamp occurred.
 */
export const getTimeAgo = (timestamp: number) => {
  const dateInMilliseconds = timestamp * 1000;
  const inputDate = new Date(dateInMilliseconds);

  // Calculate the difference in months
  const monthsDifference = differenceInMonths(new Date(), inputDate);

  // If the date is older than 1 month, use "dd MMM" format
  if (monthsDifference >= 1) {
    // @todo Implement Drupal-Specific Date Formatting(https://www.drupal.org/project/canvas/issues/3493779)
    return format(inputDate, 'd MMM');
  }

  const timeAgo = formatDistanceToNow(inputDate, { addSuffix: true });

  // Define a mapping for units
  const unitMappings: Record<string, string> = {
    'less than a minute': 'a moment',
    ' seconds': 's',
    ' second': 's',
    ' minutes': 'm',
    ' minute': 'm',
    ' hours': 'h',
    ' hour': 'h',
    ' days': 'd',
    ' day': 'd',
    ' month': 'mo',
    'about ': '',
  };

  return timeAgo.replace(
    new RegExp(Object.keys(unitMappings).join('|'), 'g'),
    (matched) => unitMappings[matched],
  );
};
