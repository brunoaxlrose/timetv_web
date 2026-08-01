import AsyncStorage from '@react-native-async-storage/async-storage';

const DEFAULT_API_URL = 'http://localhost:8080';
const USER_KEY = 'timeview:user';

export const API_BASE_URL = process.env.EXPO_PUBLIC_API_URL || DEFAULT_API_URL;

export type ApiResponse<T> = {
  success: boolean;
  data: T | null;
  message: string;
};

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
): Promise<ApiResponse<T>> {
  let response: Response;
  let token: string | undefined;

  try {
    const rawUser = await AsyncStorage.getItem(USER_KEY);
    if (rawUser) {
      token = JSON.parse(rawUser)?.token_api;
    }
  } catch {
    token = undefined;
  }

  try {
    response = await fetch(`${API_BASE_URL}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
        ...(options.headers || {}),
      },
    });
  } catch {
    throw new Error('Ocorreu um erro com o servidor.');
  }

  const raw = await response.text();
  let body: ApiResponse<T> | null = null;

  if (raw) {
    try {
      body = JSON.parse(raw) as ApiResponse<T>;
    } catch {
      if (response.status === 401) {
        throw new Error(getUnauthorizedMessage(path));
      }

      throw new Error('Ocorreu um erro com o servidor.');
    }
  }

  if (!response.ok || !body || !body.success) {
    if (body?.message === '401 Unauthorized' || response.status === 401) {
      throw new Error(getUnauthorizedMessage(path, body?.message));
    }

    throw new Error(body?.message || 'Ocorreu um erro com o servidor.');
  }

  return body;
}
