import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';

export const USER_KEY = 'timeview.user';
export const CREDENTIALS_KEY = 'timeview.credentials';

const LEGACY_USER_KEY = 'timeview:user';
const LEGACY_CREDENTIALS_KEY = 'timeview:credentials';

export async function getSecureValue(key: string) {
  const secureValue = await SecureStore.getItemAsync(key);
  if (secureValue != null) return secureValue;

  const legacyValue = await getLegacyStorageValue(key);
  if (legacyValue != null) {
    await SecureStore.setItemAsync(key, legacyValue);
    await removeLegacyStorageValue(key);
  }
  return legacyValue;
}

export async function setSecureValue(key: string, value: string) {
  await SecureStore.setItemAsync(key, value);
  await removeLegacyStorageValue(key);
}

export async function removeSecureValues(keys: string[]) {
  await Promise.all(keys.map((key) => SecureStore.deleteItemAsync(key)));
  const legacyKeys = keys.flatMap((key) => [key, legacyKeyFor(key)]).filter((value, index, all) => all.indexOf(value) === index);
  await AsyncStorage.multiRemove(legacyKeys);
}

async function getLegacyStorageValue(key: string) {
  const currentValue = await AsyncStorage.getItem(key);
  if (currentValue != null) return currentValue;
  return AsyncStorage.getItem(legacyKeyFor(key));
}

async function removeLegacyStorageValue(key: string) {
  await AsyncStorage.multiRemove([key, legacyKeyFor(key)]);
}

function legacyKeyFor(key: string) {
  if (key === USER_KEY) return LEGACY_USER_KEY;
  if (key === CREDENTIALS_KEY) return LEGACY_CREDENTIALS_KEY;
  return key;
}
