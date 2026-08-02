import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { getCurrentUser, login, User } from './src/api/auth';
import { initializeApiOfflineSupport, isOfflineError } from './src/api/client';
import { ToastProvider } from './src/components/Toast';
import { MainTabs } from './src/navigation/MainTabs';
import { LoginScreen } from './src/screens/LoginScreen';
import { clearSession, loadCredentials, loadSavedUser, saveCredentials, saveUser } from './src/storage/session';
import { colors, hydratePalette } from './src/theme/colors';

export default function App() {
  const [user, setUser] = useState<User | null>(null);
  const [booting, setBooting] = useState(true);
  const [themeReady, setThemeReady] = useState(false);

  useEffect(() => {
    const stopOfflineSupport = initializeApiOfflineSupport();
    async function boot() {
      await hydratePalette();
      setThemeReady(true);
      const savedUser = await loadSavedUser();
      if (savedUser?.token_api) {
        try {
          const response = await getCurrentUser();
          if (response.data) {
            const restoredUser = { ...response.data, token_api: savedUser.token_api };
            await persistUserSafely(restoredUser);
            setUser(restoredUser);
            return;
          }
        } catch (error) {
          if (isOfflineError(error)) {
            setUser(savedUser);
            return;
          }
          // Fall back to remembered credentials below when the token has expired.
        }
      }
      const credentials = await loadCredentials();

      if (credentials) {
        try {
          const response = await login(credentials.email, credentials.password);
          if (response.data) {
            setUser(response.data);
            await persistUserSafely(response.data);
            return;
          }
        } catch (error) {
          if (savedUser && isOfflineError(error)) {
            setUser(savedUser);
            return;
          }
          if (!isOfflineError(error)) await clearSession();
        }
      }

      setBooting(false);
    }

    boot().finally(() => setBooting(false));
    return stopOfflineSupport;
  }, []);

  async function handleAuthenticated(nextUser: User, remember?: { email: string; password: string }) {
    setUser(nextUser);
    await persistUserSafely(nextUser);
    if (remember) {
      await saveCredentials(remember);
    }
  }

  async function handleLogout() {
    await clearSession();
    setUser(null);
  }

  if (!themeReady) {
    return <View style={[styles.boot, { backgroundColor: colors.background }]}><ActivityIndicator color={colors.accent} /></View>;
  }

  return (
    <ToastProvider>
      <StatusBar style="light" />
      {booting ? (
        <View style={styles.boot}>
          <ActivityIndicator color={colors.accent} />
        </View>
      ) : user ? (
        <MainTabs user={user} onLogout={handleLogout} onUserUpdated={setUser} />
      ) : (
        <LoginScreen onAuthenticated={handleAuthenticated} />
      )}
    </ToastProvider>
  );
}

async function persistUserSafely(user: User) {
  try {
    await saveUser(user);
  } catch (error) {
    console.warn('Nao foi possivel persistir a sessao localmente.', error);
  }
}

const styles = StyleSheet.create({
  boot: {
    alignItems: 'center',
    backgroundColor: colors.background,
    flex: 1,
    justifyContent: 'center',
  },
});
