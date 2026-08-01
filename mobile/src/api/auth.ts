import { apiRequest } from './client';

export type User = {
  id: number;
  username: string;
  email: string;
  nome: string;
  sobrenome: string;
  api_token?: string;
};

export function login(email: string, password: string) {
  return apiRequest<User>('/api/v1/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, password }),
  });
}

export function register(payload: {
  user_name: string;
  nome: string;
  sobrenome: string;
  email: string;
  password: string;
  password_confirm: string;
}) {
  return apiRequest<User>('/api/v1/auth/register', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function getCurrentUser() {
  return apiRequest<User>('/api/v1/auth/me');
}

export function logout() {
  return apiRequest<null>('/api/v1/auth/logout', { method: 'POST' });
}

export function updateProfile(payload: {
  username: string;
  nome: string;
  sobrenome: string;
  current_password?: string;
  new_password?: string;
  confirm_new_password?: string;
}) {
  return apiRequest<User>('/api/v1/auth/profile', {
    method: 'POST',
    body: JSON.stringify(payload),
  });
}

export function clearLibrary() {
  return apiRequest<null>('/api/v1/auth/clear-library', { method: 'POST' });
}

export function deleteAccount() {
  return apiRequest<null>('/api/v1/auth/delete-account', { method: 'POST' });
}
