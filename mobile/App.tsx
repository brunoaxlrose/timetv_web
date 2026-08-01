import { StatusBar } from 'expo-status-bar';
import { lazy, Suspense, useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { getCurrentUser, login, User } from './src/api/auth';
import { initializeApiOfflineSupport, isOfflineError } from './src/api/client';
import { clearSession, loadCredentials, loadSavedUser, saveCredentials, saveUser } from './src/storage/session';
import { colors, hydratePalette } from './src/theme/colors';

const MainTabs = lazy(() => import('./src/navigation/MainTabs').then((module) => ({ default: module.MainTabs })));
const LoginScreen = lazy(() => import('./src/screens/LoginScreen').then((module) => ({ default: module.LoginScreen })));
const ToastProvider = lazy(() => import('./src/components/Toast').then((module) => ({ default: module.ToastProvider })));

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
            await saveUser(restoredUser);
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
            await saveUser(response.data);
            setUser(response.data);
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
    await saveUser(nextUser);
    if (remember) {
      await saveCredentials(remember);
    }
    setUser(nextUser);
  }

  async function handleLogout() {
    await clearSession();
    setUser(null);
  }

  if (!themeReady) {
    return <View style={[styles.boot, { backgroundColor: colors.background }]}><ActivityIndicator color={colors.accent} /></View>;
  }

  return (
    <Suspense fallback={<View style={[styles.boot, { backgroundColor: colors.background }]}><ActivityIndicator color={colors.accent} /></View>}>
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
    </Suspense>
  );
}

const styles = StyleSheet.create({
  boot: {
    alignItems: 'center',
    backgroundColor: colors.background,
    flex: 1,
    justifyContent: 'center',
  },
});
