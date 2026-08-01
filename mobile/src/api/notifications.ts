import { apiRequest } from './client';

export type NotificationItem = {
  id_notificacao?: number;
  tipo?: string;
  titulo?: string;
  mensagem?: string;
  title?: string;
  message?: string;
  content?: string;
  ts_criacao?: string;
  ts_inclusao?: string;
  created_at?: string;
};

export async function getNotifications() {
  const response = await apiRequest<{ count: number; notifications: NotificationItem[] }>('/api/notifications');
  return {
    success: response.success,
    count: response.data?.count || 0,
    notifications: response.data?.notifications || [],
    offline: response.offline,
  };
}

export async function markNotificationsRead() {
  const response = await apiRequest<null>('/api/notifications/read', { method: 'POST' }, {
    offlineMutation: {
      kind: 'notification_read',
      optimisticData: null,
      dedupeKey: 'notifications-read',
    },
  });
  return response;
}
