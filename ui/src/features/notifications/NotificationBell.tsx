import { useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import BellIcon from '@assets/icons/bell.svg?react';
import { Popover } from '@radix-ui/themes';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  markAllConflictNotificationsRead,
  markConflictNotificationRead,
  selectConflictNotificationRead,
} from '@/features/conflict/conflictUiSlice';
import {
  buildConflictNotification,
  CONFLICT_NOTIFICATION_HREF,
  getConflictNotificationFromPendingChanges,
  getConflictNotificationId,
  getCurrentConflictContextKey,
  isConflictNotificationId,
  isConflictUxEnabled,
} from '@/features/conflict/conflictUtils';
import {
  selectActivityCenterOpen,
  setActivityCenterOpen,
} from '@/features/notifications/notificationsSlice';
import { computeBadgeCount } from '@/features/notifications/selectBadgeCount';
import { useDocumentVisibility } from '@/hooks/useDocumentVisibility';
import {
  useGetNotificationsQuery,
  useMarkNotificationsReadMutation,
} from '@/services/notificationsApi';
import { useGetAllPendingChangesQuery } from '@/services/pendingChangesApi';

import ActivityCenter from './ActivityCenter';
import {
  AUTO_READ_TYPES,
  POLLING_BACKGROUND,
  POLLING_FAST,
  POLLING_NORMAL,
} from './constants';

import styles from './NotificationBell.module.css';

const NotificationBell = () => {
  const devMode = isConflictUxEnabled();
  const contextKey = getCurrentConflictContextKey();
  const dispatch = useAppDispatch();
  const navigate = useNavigate();
  const isOpen = useAppSelector(selectActivityCenterOpen);
  const currentNotificationId = contextKey
    ? getConflictNotificationId(contextKey)
    : undefined;
  const hasReadConflictNotification = useAppSelector((state) =>
    currentNotificationId
      ? selectConflictNotificationRead(state, currentNotificationId, contextKey)
      : false,
  );
  const isTabFocused = useDocumentVisibility();
  const [markRead] = useMarkNotificationsReadMutation();
  const hasProcessingRef = useRef(false);

  const { data } = useGetNotificationsQuery(undefined, {
    pollingInterval: getPollingInterval(isTabFocused, hasProcessingRef.current),
    skip: !devMode,
  });
  const { data: pendingChanges } = useGetAllPendingChangesQuery(undefined, {
    pollingInterval: 10000,
    skipPollingIfUnfocused: true,
    skip: !devMode,
  });

  const conflictNotificationData = getConflictNotificationFromPendingChanges(
    pendingChanges,
    contextKey,
  );

  const apiNotifications = data?.data.notifications ?? [];
  const notifications = [
    ...apiNotifications,
    ...(conflictNotificationData && contextKey
      ? [
          buildConflictNotification({
            contextKey,
            hasRead: hasReadConflictNotification,
            timestamp: conflictNotificationData.timestamp,
            conflictCount: conflictNotificationData.count,
          }),
        ]
      : []),
  ];
  hasProcessingRef.current = notifications.some((n) => n.type === 'processing');
  const badgeCount = computeBadgeCount(notifications);

  const handleOpenChange = (open: boolean) => {
    dispatch(setActivityCenterOpen(open));
    if (open) {
      const autoReadIds = notifications
        .filter(
          (n) =>
            AUTO_READ_TYPES.has(n.type) &&
            !n.hasRead &&
            !isConflictNotificationId(n.id, contextKey),
        )
        .map((n) => n.id);
      if (autoReadIds.length > 0) {
        markRead({ ids: autoReadIds });
      }
    }
  };

  const handleMarkAllRead = () => {
    const unreadIds = notifications
      .filter((n) => !n.hasRead && !isConflictNotificationId(n.id, contextKey))
      .map((n) => n.id);
    if (unreadIds.length > 0) {
      markRead({ ids: unreadIds });
    }
    const unreadConflictIds = notifications
      .filter((n) => isConflictNotificationId(n.id, contextKey) && !n.hasRead)
      .map((n) => n.id);
    if (unreadConflictIds.length > 0 && contextKey) {
      dispatch(
        markAllConflictNotificationsRead({
          contextKey,
          ids: unreadConflictIds,
        }),
      );
    }
  };

  const handleMarkRead = (id: string) => {
    if (contextKey && isConflictNotificationId(id, contextKey)) {
      dispatch(
        markConflictNotificationRead({
          contextKey,
          id,
        }),
      );
      return;
    }
    markRead({ ids: [id] });
  };

  const handleAction = (id: string, href: string) => {
    if (contextKey && isConflictNotificationId(id, contextKey)) {
      dispatch(
        markConflictNotificationRead({
          contextKey,
          id,
        }),
      );
    } else {
      markRead({ ids: [id] });
    }

    if (href === CONFLICT_NOTIFICATION_HREF) {
      dispatch(setActivityCenterOpen(false));
      navigate('/conflict');
      return;
    }

    window.open(href, '_blank', 'noopener,noreferrer');
  };

  if (!devMode) return null;

  return (
    <Popover.Root open={isOpen} onOpenChange={handleOpenChange}>
      <Popover.Trigger>
        <button className={styles.bellButton} aria-label="Notifications">
          <BellIcon width="16" height="16" />
          {badgeCount > 0 && (
            <span className={styles.badge}>
              {badgeCount > 9 ? '9+' : badgeCount}
            </span>
          )}
        </button>
      </Popover.Trigger>
      <Popover.Content size="1" align="end" style={{ padding: 0 }}>
        <ActivityCenter
          notifications={notifications}
          onClose={() => handleOpenChange(false)}
          onMarkAllRead={handleMarkAllRead}
          onMarkRead={handleMarkRead}
          onAction={handleAction}
        />
      </Popover.Content>
    </Popover.Root>
  );
};

function getPollingInterval(
  isTabFocused: boolean,
  hasProcessing: boolean,
): number {
  if (!isTabFocused) return POLLING_BACKGROUND;
  if (hasProcessing) return POLLING_FAST;
  return POLLING_NORMAL;
}

export default NotificationBell;
