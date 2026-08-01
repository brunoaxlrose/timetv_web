import { API_BASE_URL } from './client';

export type NotificationItem = {
  id_notificacao?: number;
  tipo?: string;
  titulo?: string;
  mensagem?: string;
  title?: string;
  message?: string;
  content?: string;
  ts_criacao?: string;
  created_at?: string;
};

export function getNotifications() {
  return requestNotifications('/api/notifications');
}

export function markNotificationsRead() {
  return requestNotifications('/api/notifications/read', { method: 'POST' });
}

async function requestNotifications(path: string, options: RequestInit = {}) {
  const response = await fetch(`${API_BASE_URL}${path}`, {
    credentials: 'include',
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.headers || {}),
    },
  });

  const body = await response.json();
  if (!response.ok || !body.success) {
    throw new Error(body.message || 'Erro ao carregar notificacoes.');
  }
  return body as { success: boolean; count?: number; notifications?: NotificationItem[] };
}
