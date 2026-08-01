import { apiRequest } from './client';

export type User = {
  id: number;
  nome_usuario: string;
  email: string;
  nome: string;
  sobrenome: string;
  url_avatar?: string;
  token_api?: string;
};

export function login(email: string, senha: string) {
  return apiRequest<User>('/api/v1/auth/login', {
    method: 'POST',
    body: JSON.stringify({ email, senha }),
  });
}

export function register(payload: {
  nome_usuario: string;
  nome: string;
  sobrenome: string;
  email: string;
  senha: string;
  confirmacao_senha: string;
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
  nome_usuario: string;
  nome: string;
  sobrenome: string;
  url_avatar?: string;
  senha_atual?: string;
  nova_senha?: string;
  confirmacao_nova_senha?: string;
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
