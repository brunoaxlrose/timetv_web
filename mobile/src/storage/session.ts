import { User } from '../api/auth';
import { CREDENTIALS_KEY, getSecureValue, removeSecureValues, setSecureValue, USER_KEY } from './secure';

export type SavedCredentials = {
  email: string;
  password: string;
};

export async function saveUser(user: User) {
  await setSecureValue(USER_KEY, JSON.stringify(user));
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
