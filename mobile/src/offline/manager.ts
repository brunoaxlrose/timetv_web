import AsyncStorage from '@react-native-async-storage/async-storage';
import NetInfo, { NetInfoState } from '@react-native-community/netinfo';
import type { ApiResponse } from '../api/client';
import { OfflineMutationKind, projectOfflineMutations } from './projection';

const QUEUE_PREFIX = 'cinefio:offline:queue:v1:';
const CACHE_PREFIX = 'cinefio:offline:cache:v1:';
const CACHE_INDEX_PREFIX = 'cinefio:offline:cache-index:v1:';
const LIST_MAP_PREFIX = 'cinefio:offline:list-map:v1:';
const MAX_QUEUE_SIZE = 500;
const MAX_CACHE_ENTRIES = 80;

export type OfflineQueueEntry = {
  id: string;
  userId: number;
  path: string;
  method: string;
  body: Record<string, unknown>;
  kind: OfflineMutationKind;
  createdAt: string;
  attempts: number;
  dedupeKey?: string;
  tempListId?: number;
  status?: 'pending' | 'failed';
  lastError?: string;
};

type CacheIndexEntry = { key: string; path: string; savedAt: string };
type CacheEnvelope<T> = { path: string; savedAt: string; response: ApiResponse<T> };
type QueueExecutor = (entry: OfflineQueueEntry, body: Record<string, unknown>) => Promise<ApiResponse<unknown>>;

export type OfflineSnapshot = {
  online: boolean;
  syncing: boolean;
  pending: number;
  failed: number;
  lastSyncedAt?: string;
};

let snapshot: OfflineSnapshot = { online: true, syncing: false, pending: 0, failed: 0 };
let executor: QueueExecutor | null = null;
let currentUserId: (() => Promise<number>) | null = null;
let unsubscribeNetInfo: (() => void) | null = null;
let flushPromise: Promise<void> | null = null;
const listeners = new Set<() => void>();

export function initializeOfflineManager(nextExecutor: QueueExecutor, resolveUserId: () => Promise<number>) {
  executor = nextExecutor;
  currentUserId = resolveUserId;
  if (unsubscribeNetInfo) return unsubscribeNetInfo;

  unsubscribeNetInfo = NetInfo.addEventListener((state) => {
    const online = isReachable(state);
    updateSnapshot({ online });
    refreshQueueStats().catch(() => undefined);
    if (online) flushOfflineQueue().catch(() => undefined);
  });
  NetInfo.fetch().then((state) => {
    updateSnapshot({ online: isReachable(state) });
    return refreshQueueStats();
  }).then(() => {
    if (snapshot.online) return flushOfflineQueue();
  }).catch(() => undefined);

  return () => {
    unsubscribeNetInfo?.();
    unsubscribeNetInfo = null;
  };
}

export function subscribeOfflineState(listener: () => void) {
  listeners.add(listener);
  return () => listeners.delete(listener);
}

export function getOfflineSnapshot() {
  return snapshot;
}

export async function cacheApiResponse<T>(userId: number, path: string, response: ApiResponse<T>) {
  if (userId <= 0 || !response.success || response.data == null) return;
  const key = cacheKey(userId, path);
  const savedAt = new Date().toISOString();
  const envelope: CacheEnvelope<T> = { path, savedAt, response };
  await AsyncStorage.setItem(key, JSON.stringify(envelope));

  const indexKey = `${CACHE_INDEX_PREFIX}${userId}`;
  const index = await readJson<CacheIndexEntry[]>(indexKey, []);
  const next = [{ key, path, savedAt }, ...index.filter((entry) => entry.key !== key)];
  const removed = next.splice(MAX_CACHE_ENTRIES);
  await AsyncStorage.setItem(indexKey, JSON.stringify(next));
  if (removed.length) await AsyncStorage.multiRemove(removed.map((entry) => entry.key));
}

export async function getCachedApiResponse<T>(userId: number, path: string) {
  if (userId <= 0) return null;
  const envelope = await readJson<CacheEnvelope<T> | null>(cacheKey(userId, path), null);
  if (!envelope?.response) return null;
  const queue = await readQueue(userId);
  return {
    ...projectOfflineMutations(path, envelope.response, queue),
    offline: true,
    cached_at: envelope.savedAt,
  } as ApiResponse<T>;
}

export async function enqueueOfflineMutation(
  input: Omit<OfflineQueueEntry, 'id' | 'createdAt' | 'attempts' | 'status'> & { id?: string },
) {
  const entry: OfflineQueueEntry = {
    ...input,
    id: input.id || mutationId(),
    createdAt: new Date().toISOString(),
    attempts: 0,
    status: 'pending',
  };
  const queue = await readQueue(input.userId);

  if (entry.dedupeKey) {
    const duplicateIndex = queue.findIndex((queued) => queued.dedupeKey === entry.dedupeKey && queued.status !== 'failed');
    if (duplicateIndex >= 0) {
      entry.id = queue[duplicateIndex].id;
      entry.createdAt = queue[duplicateIndex].createdAt;
      queue[duplicateIndex] = entry;
    } else {
      queue.push(entry);
    }
  } else {
    queue.push(entry);
  }

  if (queue.length > MAX_QUEUE_SIZE) {
    throw new Error('O limite de alteracoes offline foi atingido. Conecte-se para sincronizar.');
  }
  await writeQueue(input.userId, queue);
  await refreshQueueStats(input.userId);
  return entry;
}

export async function flushOfflineQueue() {
  if (flushPromise) return flushPromise;
  flushPromise = runFlush().finally(() => {
    flushPromise = null;
  });
  return flushPromise;
}

export async function retryFailedMutations() {
  const userId = await currentUserId?.() ?? 0;
  if (userId <= 0) return;
  const queue = await readQueue(userId);
  queue.forEach((entry) => {
    if (entry.status === 'failed') {
      entry.status = 'pending';
      entry.lastError = undefined;
    }
  });
  await writeQueue(userId, queue);
  await refreshQueueStats(userId);
  if (snapshot.online) await flushOfflineQueue();
}

async function runFlush() {
  if (!snapshot.online || !executor || !currentUserId) return;
  const userId = await currentUserId();
  if (userId <= 0) return;
  let queue = await readQueue(userId);
  if (!queue.some((entry) => entry.status !== 'failed')) {
    await refreshQueueStats(userId);
    return;
  }

  updateSnapshot({ syncing: true });
  const listMap = await readJson<Record<string, number>>(`${LIST_MAP_PREFIX}${userId}`, {});
  try {
    for (const entry of [...queue]) {
      if (entry.status === 'failed' || !snapshot.online) continue;
      const body: Record<string, unknown> = { ...entry.body, id_mutacao_cliente: entry.id };
      if (Number(body.list_id) < 0) {
        const mappedId = listMap[String(body.list_id)];
        if (!mappedId) break;
        body.list_id = mappedId;
      }

      try {
        const response = await executor(entry, body);
        if (entry.tempListId && Number(response.data && (response.data as Record<string, unknown>).list_id) > 0) {
          listMap[String(entry.tempListId)] = Number((response.data as Record<string, unknown>).list_id);
          await AsyncStorage.setItem(`${LIST_MAP_PREFIX}${userId}`, JSON.stringify(listMap));
        }
        queue = queue.filter((queued) => queued.id !== entry.id);
        await writeQueue(userId, queue);
      } catch (error) {
        const status = getErrorStatus(error);
        const current = queue.find((queued) => queued.id === entry.id);
        if (current) {
          current.attempts += 1;
          current.lastError = error instanceof Error ? error.message : 'Falha ao sincronizar.';
          if (status >= 400 && status < 500 && status !== 408 && status !== 429) current.status = 'failed';
          await writeQueue(userId, queue);
        }
        if (!status || status >= 500 || status === 408 || status === 429) {
          updateSnapshot({ online: status ? snapshot.online : false });
          break;
        }
      }
    }
  } finally {
    updateSnapshot({ syncing: false, lastSyncedAt: queue.length ? snapshot.lastSyncedAt : new Date().toISOString() });
    await refreshQueueStats(userId);
  }
}

async function refreshQueueStats(explicitUserId?: number) {
  const userId = explicitUserId ?? await currentUserId?.() ?? 0;
  if (userId <= 0) {
    updateSnapshot({ pending: 0, failed: 0 });
    return;
  }
  const queue = await readQueue(userId);
  updateSnapshot({
    pending: queue.filter((entry) => entry.status !== 'failed').length,
    failed: queue.filter((entry) => entry.status === 'failed').length,
  });
}

function updateSnapshot(patch: Partial<OfflineSnapshot>) {
  const next = { ...snapshot, ...patch };
  if (JSON.stringify(next) === JSON.stringify(snapshot)) return;
  snapshot = next;
  listeners.forEach((listener) => listener());
}

function isReachable(state: NetInfoState) {
  return state.isConnected !== false && state.isInternetReachable !== false;
}

function cacheKey(userId: number, path: string) {
  return `${CACHE_PREFIX}${userId}:${hash(path)}`;
}

function queueKey(userId: number) {
  return `${QUEUE_PREFIX}${userId}`;
}

function readQueue(userId: number) {
  return readJson<OfflineQueueEntry[]>(queueKey(userId), []);
}

function writeQueue(userId: number, queue: OfflineQueueEntry[]) {
  return AsyncStorage.setItem(queueKey(userId), JSON.stringify(queue));
}

async function readJson<T>(key: string, fallback: T): Promise<T> {
  try {
    const value = await AsyncStorage.getItem(key);
    return value ? JSON.parse(value) as T : fallback;
  } catch {
    return fallback;
  }
}

function getErrorStatus(error: unknown) {
  return typeof error === 'object' && error && 'status' in error ? Number((error as { status: number }).status) : 0;
}

function mutationId() {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

function hash(value: string) {
  let result = 2166136261;
  for (let index = 0; index < value.length; index += 1) {
    result ^= value.charCodeAt(index);
    result = Math.imul(result, 16777619);
  }
  return (result >>> 0).toString(36);
}
