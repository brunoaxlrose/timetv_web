import {
  cacheApiResponse,
  enqueueOfflineMutation,
  getCachedApiResponse,
  getOfflineSnapshot,
  initializeOfflineManager,
  OfflineQueueEntry,
} from '../offline/manager';
import { OfflineMutationKind } from '../offline/projection';
import { getSecureValue, USER_KEY } from '../storage/secure';

const LOCAL_API_URL = process.env.EXPO_PUBLIC_LOCAL_API_URL || 'http://192.168.0.18:8080';
const PRODUCTION_API_URL = process.env.EXPO_PUBLIC_PRODUCTION_API_URL || 'https://cinefio-api.onrender.com';
const API_ENV = process.env.EXPO_PUBLIC_API_ENV || 'production';
const REQUEST_TIMEOUT_MS = 20000;

export const API_BASE_URL = process.env.EXPO_PUBLIC_API_URL
  || (API_ENV === 'local' ? LOCAL_API_URL : PRODUCTION_API_URL);

export type ApiResponse<T> = {
  success: boolean;
  data: T | null;
  message: string;
  offline?: boolean;
  queued?: boolean;
  cached_at?: string;
};

type OfflineMutationConfig<T> = {
  kind: OfflineMutationKind;
  optimisticData: T | null;
  dedupeKey?: string;
  tempListId?: number;
};

type ApiRequestConfig<T> = {
  cache?: boolean;
  offlineMutation?: OfflineMutationConfig<T>;
};

type StoredAuth = {
  userId: number;
  token?: string;
};

export class ApiOfflineError extends Error {
  readonly code = 'OFFLINE';
}

export class ApiHttpError extends Error {
  constructor(message: string, readonly status: number) {
    super(message);
  }
}

export function isOfflineError(error: unknown): error is ApiOfflineError {
  return error instanceof ApiOfflineError || (
    error instanceof Error && 'code' in error && (error as Error & { code?: string }).code === 'OFFLINE'
  );
}

export function initializeApiOfflineSupport() {
  return initializeOfflineManager(
    async (entry, body) => {
      const auth = await getStoredAuth();
      return performRequest(entry.path, {
        method: entry.method,
        body: JSON.stringify(body),
      }, auth.token, entry.id);
    },
    async () => (await getStoredAuth()).userId,
  );
}

function getUnauthorizedMessage(path: string, serverMessage?: string) {
  if (serverMessage && serverMessage !== '401 Unauthorized') {
    return serverMessage;
  }

  if (path.includes('/auth/login')) {
    return 'Email ou senha incorretos. Confira os dados ou crie uma conta.';
  }

  return 'Sua sessao expirou. Entre novamente para continuar.';
}

export async function apiRequest<T>(
  path: string,
  options: RequestInit = {},
  config: ApiRequestConfig<T> = {},
): Promise<ApiResponse<T>> {
  const auth = await getStoredAuth();
  const method = String(options.method || 'GET').toUpperCase();
  const shouldCache = config.cache !== false && method === 'GET' && auth.userId > 0;
  const mutation = config.offlineMutation;
  const mutationId = mutation ? createMutationId() : undefined;

  if (!getOfflineSnapshot().online) {
    if (shouldCache) return cachedOrThrow<T>(auth.userId, path);
    if (mutation) return queueMutation(path, options, auth.userId, mutation, mutationId);
    throw new ApiOfflineError('Sem internet. Esta operacao precisa de conexao.');
  }

  try {
    const response = await performRequest<T>(path, options, auth.token, mutationId);
    if (shouldCache) await cacheApiResponse(auth.userId, path, response);
    return response;
  } catch (error) {
    if (!isOfflineError(error)) throw error;
    if (shouldCache) return cachedOrThrow<T>(auth.userId, path);
    if (mutation) return queueMutation(path, options, auth.userId, mutation, mutationId);
    throw error;
  }
}

async function queueMutation<T>(
  path: string,
  options: RequestInit,
  userId: number,
  mutation: OfflineMutationConfig<T>,
  mutationId?: string,
): Promise<ApiResponse<T>> {
  if (userId <= 0) throw new ApiOfflineError('Entre na sua conta online antes de usar o modo offline.');
  const body = parseBody(options.body);
  await enqueueOfflineMutation({
    userId,
    path,
    method: String(options.method || 'POST').toUpperCase(),
    body,
    kind: mutation.kind,
    dedupeKey: mutation.dedupeKey,
    tempListId: mutation.tempListId,
    id: mutationId,
  });
  return {
    success: true,
    data: mutation.optimisticData,
    message: 'Alteracao salva no aparelho e aguardando sincronizacao.',
    offline: true,
    queued: true,
  };
}

async function cachedOrThrow<T>(userId: number, path: string) {
  const cached = await getCachedApiResponse<T>(userId, path);
  if (cached) return cached;
  throw new ApiOfflineError('Sem internet e sem dados salvos para esta tela.');
}

async function performRequest<T>(
  path: string,
  options: RequestInit,
  token?: string,
  mutationId?: string,
): Promise<ApiResponse<T>> {
  let response: Response;
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), REQUEST_TIMEOUT_MS);

  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...options,
      signal: controller.signal,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(mutationId ? { 'X-Idempotency-Key': mutationId } : {}),
        ...(options.headers || {}),
      },
    });
  } catch {
    throw new ApiOfflineError('Sem conexao com o servidor. A alteracao sera sincronizada depois.');
  } finally {
    clearTimeout(timeout);
  }

  const raw = await response.text();
  let body: ApiResponse<T> | null = null;
  if (raw) {
    try {
      body = JSON.parse(raw) as ApiResponse<T>;
    } catch {
      if (response.status === 401) throw new ApiHttpError(getUnauthorizedMessage(path), 401);
      throw new ApiHttpError('O servidor retornou uma resposta invalida.', response.status);
    }
  }

  if (!response.ok || !body || !body.success) {
    if (body?.message === '401 Unauthorized' || response.status === 401) {
      throw new ApiHttpError(getUnauthorizedMessage(path, body?.message), 401);
    }
    throw new ApiHttpError(body?.message || 'Ocorreu um erro com o servidor.', response.status);
  }
  return body;
}

async function getStoredAuth(): Promise<StoredAuth> {
  try {
    const rawUser = await getSecureValue(USER_KEY);
    if (!rawUser) return { userId: 0 };
    const user = JSON.parse(rawUser) as { id?: number; token_api?: string };
    return { userId: Number(user.id || 0), token: user.token_api };
  } catch {
    return { userId: 0 };
  }
}

function parseBody(body: BodyInit | null | undefined): Record<string, unknown> {
  if (typeof body !== 'string' || !body) return {};
  try {
    const parsed = JSON.parse(body);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
  } catch {
    return {};
  }
}

function createMutationId() {
  return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}

export type { OfflineQueueEntry };
