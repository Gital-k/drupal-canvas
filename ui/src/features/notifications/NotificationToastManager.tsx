import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';

import { useAppDispatch, useAppSelector } from '@/app/hooks';
import {
  markConflictNotificationRead,
  selectConflictNotificationRead,
} from '@/features/conflict/conflictUiSlice';
import {
  buildConflictNotification,
  CONFLICT_NOTIFICATION_HREF,
  getCurrentConflictContextKey,
  getConflictNotificationFromPendingChanges,
  getConflictNotificationId,
  isConflictUxEnabled,
  isConflictNotificationId,
} from '@/features/conflict/conflictUtils';
import { selectPageOpenedAt } from '@/features/notifications/notificationsSlice';
import {
  useGetNotificationsQuery,
  useMarkNotificationsReadMutation,
} from '@/services/notificationsApi';
import { useGetAllPendingChangesQuery } from '@/services/pendingChangesApi';

import { TOAST_DURATION } from './constants';
import NotificationToast from './NotificationToast';

import type { Notification } from '@/services/notificationsApi';

import styles from './NotificationToastManager.module.css';

const NotificationToastManager = () => {
  const dispatch = useAppDispatch();
  const navigate = useNavigate();
  const conflictUxEnabled = isConflictUxEnabled();
  const contextKey = getCurrentConflictContextKey();
  const { data } = useGetNotificationsQuery();
  const { data: pendingChanges } = useGetAllPendingChangesQuery(undefined, {
    pollingInterval: 10000,
    skipPollingIfUnfocused: true,
  });
  const [markRead] = useMarkNotificationsReadMutation();
  const pageOpenedAt = useAppSelector(selectPageOpenedAt);
  const currentNotificationId = contextKey
    ? getConflictNotificationId(contextKey)
    : undefined;
  const hasReadConflictNotification = useAppSelector((state) =>
    currentNotificationId
      ? selectConflictNotificationRead(
          state,
          currentNotificationId,
          contextKey,
        )
      : false,
  );
  const shownIds = useRef(new Set<string>());
  const [visibleToasts, setVisibleToasts] = useState<Notification[]>([]);
  const timers = useRef(new Map<string, ReturnType<typeof setTimeout>>());

  const dismissToast = useCallback((id: string) => {
    const timer = timers.current.get(id);
    if (timer) {
      clearTimeout(timer);
      timers.current.delete(id);
    }
    setVisibleToasts((prev) => prev.filter((n) => n.id !== id));
  }, []);

  const handleDismiss = useCallback(
    (id: string) => {
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
      dismissToast(id);
    },
    [contextKey, dispatch, dismissToast, markRead],
  );

  const handleAction = useCallback(
    (id: string, href: string) => {
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
      dismissToast(id);
      if (href === CONFLICT_NOTIFICATION_HREF) {
        navigate('/conflict');
        return;
      }
      window.open(href, '_blank', 'noopener,noreferrer');
    },
    [contextKey, dispatch, dismissToast, markRead, navigate],
  );

  useEffect(() => {
    const conflictNotificationData = conflictUxEnabled
      ? getConflictNotificationFromPendingChanges(pendingChanges, contextKey)
      : undefined;
    const notifications = [
      ...(data?.data.notifications ?? []),
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

    const newToasts: Notification[] = [];

    for (const notification of notifications) {
      if (notification.timestamp <= pageOpenedAt) continue;
      if (shownIds.current.has(notification.id)) continue;

      shownIds.current.add(notification.id);
      newToasts.push(notification);

      const timer = setTimeout(() => {
        timers.current.delete(notification.id);
        setVisibleToasts((prev) =>
          prev.filter((n) => n.id !== notification.id),
        );
      }, TOAST_DURATION);
      timers.current.set(notification.id, timer);
    }

    if (newToasts.length > 0) {
      setVisibleToasts((prev) => [...newToasts, ...prev]);
    }
  }, [
    data?.data.notifications,
    hasReadConflictNotification,
    conflictUxEnabled,
    pendingChanges,
    contextKey,
    pageOpenedAt,
  ]);

  useEffect(() => {
    const currentTimers = timers.current;
    return () => {
      for (const timer of currentTimers.values()) {
        clearTimeout(timer);
      }
    };
  }, []);

  if (visibleToasts.length === 0) return null;

  return (
    <div className={styles.container}>
      {visibleToasts.map((notification) => (
        <NotificationToast
          key={notification.id}
          notification={notification}
          onDismiss={handleDismiss}
          onAction={handleAction}
        />
      ))}
    </div>
  );
};

export default NotificationToastManager;
