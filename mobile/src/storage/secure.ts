import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';

export const USER_KEY = 'timeview:user';
export const CREDENTIALS_KEY = 'timeview:credentials';

export async function getSecureValue(key: string) {
  const secureValue = await SecureStore.getItemAsync(key);
  if (secureValue != null) return secureValue;

  const legacyValue = await AsyncStorage.getItem(key);
  if (legacyValue != null) {
    await SecureStore.setItemAsync(key, legacyValue);
    await AsyncStorage.removeItem(key);
  }
  return legacyValue;
}

export async function setSecureValue(key: string, value: string) {
  await SecureStore.setItemAsync(key, value);
  await AsyncStorage.removeItem(key);
}

export async function removeSecureValues(keys: string[]) {
  await Promise.all(keys.map((key) => SecureStore.deleteItemAsync(key)));
  await AsyncStorage.multiRemove(keys);
}
