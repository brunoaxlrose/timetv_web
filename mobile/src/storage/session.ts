import AsyncStorage from '@react-native-async-storage/async-storage';
import { User } from '../api/auth';

const USER_KEY = 'timeview:user';
const CREDENTIALS_KEY = 'timeview:credentials';

export type SavedCredentials = {
  email: string;
  password: string;
};

export async function saveUser(user: User) {
  await AsyncStorage.setItem(USER_KEY, JSON.stringify(user));
}

export async function loadSavedUser() {
  const raw = await AsyncStorage.getItem(USER_KEY);
  return raw ? JSON.parse(raw) as User : null;
}

export async function saveCredentials(credentials: SavedCredentials) {
  await AsyncStorage.setItem(CREDENTIALS_KEY, JSON.stringify(credentials));
}

export async function loadCredentials() {
  const raw = await AsyncStorage.getItem(CREDENTIALS_KEY);
  return raw ? JSON.parse(raw) as SavedCredentials : null;
}

export async function clearSession() {
  await AsyncStorage.multiRemove([USER_KEY, CREDENTIALS_KEY]);
}
