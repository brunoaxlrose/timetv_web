import { User } from '../api/auth';
import { CREDENTIALS_KEY, getSecureValue, removeSecureValues, setSecureValue, USER_KEY } from './secure';

export type SavedCredentials = {
  email: string;
  password: string;
};

export async function saveUser(user: User) {
  await setSecureValue(USER_KEY, JSON.stringify(toPersistedUser(user)));
}

export async function loadSavedUser() {
  const raw = await getSecureValue(USER_KEY);
  return raw ? JSON.parse(raw) as User : null;
}

export async function saveCredentials(credentials: SavedCredentials) {
  await setSecureValue(CREDENTIALS_KEY, JSON.stringify(credentials));
}

export async function loadCredentials() {
  const raw = await getSecureValue(CREDENTIALS_KEY);
  return raw ? JSON.parse(raw) as SavedCredentials : null;
}

export async function clearSession() {
  await removeSecureValues([USER_KEY, CREDENTIALS_KEY]);
}

function toPersistedUser(user: User): User {
  return {
    id: user.id,
    nome_usuario: user.nome_usuario,
    email: user.email,
    nome: user.nome,
    sobrenome: user.sobrenome,
    token_api: user.token_api,
    // Evita salvar base64 gigante no SecureStore do iPhone.
    url_avatar: normalizeAvatar(user.url_avatar),
  };
}

function normalizeAvatar(urlAvatar?: string) {
  if (!urlAvatar) return undefined;
  if (urlAvatar.startsWith('data:image/')) return undefined;
  return urlAvatar.length <= 2048 ? urlAvatar : undefined;
}
