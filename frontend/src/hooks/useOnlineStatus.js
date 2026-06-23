import { useState, useEffect, useCallback } from 'react';

export default function useOnlineStatus() {
  const [isOnline, setIsOnline] = useState(navigator.onLine);

  useEffect(() => {
    const handleOnline = () => setIsOnline(true);
    const handleOffline = () => setIsOnline(false);

    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);

    return () => {
      window.removeEventListener('online', handleOnline);
      window.removeEventListener('offline', handleOffline);
    };
  }, []);

  return isOnline;
}

const QUEUE_KEY = 'offline_orders_queue';

export function getOfflineQueue() {
  try {
    const raw = localStorage.getItem(QUEUE_KEY);
    return raw ? JSON.parse(raw) : [];
  } catch {
    return [];
  }
}

export function addToOfflineQueue(orderPayload) {
  const queue = getOfflineQueue();
  queue.push({
    ...orderPayload,
    _offline_id: crypto.randomUUID(),
    _queued_at: new Date().toISOString(),
  });
  localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
  return queue.length;
}

export function clearOfflineQueue() {
  localStorage.removeItem(QUEUE_KEY);
}

export function removeFromOfflineQueue(offlineId) {
  const queue = getOfflineQueue();
  const filtered = queue.filter(o => o._offline_id !== offlineId);
  localStorage.setItem(QUEUE_KEY, JSON.stringify(filtered));
}
