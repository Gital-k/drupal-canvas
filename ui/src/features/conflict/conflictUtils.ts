import { getCanvasSettings } from '@/utils/drupal-globals';

import type { Notification } from '@/services/notificationsApi';
import type { PendingChanges } from '@/services/pendingChangesApi';
import type { UnpublishedChange } from '@/types/Review';

export interface ConflictResolverItem {
  pointer: string;
  contextKey: string;
  label: string;
  entityType: string;
  entityId: string | number;
  updated: number;
}

export const GLOBAL_CONFLICT_CONTEXT_KEY = 'global';
export const CONFLICT_NOTIFICATION_ID_PREFIX = 'canvas_conflict_detected';
export const CONFLICT_NOTIFICATION_HREF = '/canvas/conflict';

export const getConflictRouteForChange = (
  change: Pick<UnpublishedChange, 'entity_type' | 'entity_id'>,
): string =>
  `/conflict/${change.entity_type}/${encodeURIComponent(String(change.entity_id))}`;

export const getConflictContextKey = (
  entityType: string,
  entityId: string | number,
): string => `${entityType}:${String(entityId)}`;

export const getCurrentConflictContextKey = (
  pathname = typeof window === 'undefined' ? '' : window.location.pathname,
): string | undefined => {
  const match = pathname.match(/\/editor\/([^/]+)\/([^/?#]+)/);
  if (!match) {
    return undefined;
  }
  return getConflictContextKey(match[1], decodeURIComponent(match[2]));
};

export const getConflictNotificationId = (contextKey: string): string =>
  `${CONFLICT_NOTIFICATION_ID_PREFIX}:${contextKey}`;

export const isConflictNotificationId = (
  id: string,
  contextKey?: string,
): boolean => {
  if (!id.startsWith(CONFLICT_NOTIFICATION_ID_PREFIX)) {
    return false;
  }
  if (!contextKey) {
    return true;
  }
  return id === getConflictNotificationId(contextKey);
};

const normalizeTimestamp = (timestamp?: number): number => {
  if (typeof timestamp !== 'number') {
    return Date.now();
  }
  return timestamp < 1_000_000_000_000 ? timestamp * 1000 : timestamp;
};

export const buildConflictNotification = ({
  contextKey,
  hasRead,
  timestamp,
  conflictCount,
}: {
  contextKey: string;
  hasRead: boolean;
  timestamp: number;
  conflictCount: number;
}): Notification => ({
  id: getConflictNotificationId(contextKey),
  type: 'warning',
  key: getConflictNotificationId(contextKey),
  title: conflictCount === 1 ? 'Conflict detected' : 'Conflicts detected',
  message:
    conflictCount === 1
      ? 'A pending change has a conflict and requires attention.'
      : `${conflictCount} pending changes have conflicts and require attention.`,
  timestamp,
  hasRead,
  actions: [
    {
      label: 'Resolve conflicts',
      href: CONFLICT_NOTIFICATION_HREF,
    },
  ],
});

export const getConflictedUnpublishedChangesForContext = (
  changes: UnpublishedChange[],
  contextKey?: string,
): UnpublishedChange[] =>
  changes.filter(
    (change) =>
      change.hasConflict &&
      (!contextKey ||
        getConflictContextKey(change.entity_type, change.entity_id) ===
          contextKey),
  );

export const toConflictResolverItems = (
  changes: UnpublishedChange[],
): ConflictResolverItem[] =>
  changes.map((change) => ({
    pointer: change.pointer,
    contextKey: getConflictContextKey(change.entity_type, change.entity_id),
    label: change.label,
    entityType: change.entity_type,
    entityId: change.entity_id,
    updated: change.updated,
  }));

export const getConflictNotificationFromPendingChanges = (
  pendingChanges: PendingChanges | undefined,
  contextKey?: string,
): { count: number; timestamp: number } | undefined => {
  if (!pendingChanges || !contextKey) {
    return undefined;
  }

  const entries = Object.entries(pendingChanges).filter(
    ([, change]) =>
      change.hasConflict &&
      getConflictContextKey(change.entity_type, change.entity_id) ===
        contextKey,
  );

  if (!entries.length) {
    return undefined;
  }

  const latestTimestamp = Math.max(
    ...entries.map(([, change]) => normalizeTimestamp(change.updated)),
  );

  return {
    count: entries.length,
    timestamp: latestTimestamp,
  };
};

export const findStartPointerByEntity = (
  changes: PendingChanges | undefined,
  entityType: string,
  entityId: string,
): string | undefined => {
  if (!changes) {
    return undefined;
  }

  const match = Object.entries(changes).find(
    ([, change]) =>
      change.hasConflict &&
      change.entity_type === entityType &&
      String(change.entity_id) === entityId,
  );

  return match?.[0];
};

export const isConflictUxEnabled = (): boolean =>
  getCanvasSettings()?.devMode === true;
