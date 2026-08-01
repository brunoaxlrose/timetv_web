import { StatusBar } from 'expo-status-bar';
import { useEffect, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { login, User } from './src/api/auth';
import { MainTabs } from './src/navigation/MainTabs';
import { clearSession, loadCredentials, saveCredentials, saveUser } from './src/storage/session';
import { colors } from './src/theme/colors';
import { LoginScreen } from './src/screens/LoginScreen';
import { ToastProvider } from './src/components/Toast';

export default function App() {
  const [user, setUser] = useState<User | null>(null);
  const [booting, setBooting] = useState(true);

  useEffect(() => {
    async function boot() {
      const credentials = await loadCredentials();

      if (credentials) {
        try {
          const response = await login(credentials.email, credentials.password);
          if (response.data) {
            await saveUser(response.data);
            setUser(response.data);
            return;
          }
        } catch {
          await clearSession();
        }
      }

      setBooting(false);
    }

    boot().finally(() => setBooting(false));
  }, []);

  async function handleAuthenticated(nextUser: User, remember?: { email: string; password: string }) {
    if (remember) {
      await saveUser(nextUser);
      await saveCredentials(remember);
    }
    setUser(nextUser);
  }

  async function handleLogout() {
    await clearSession();
    setUser(null);
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

const styles = StyleSheet.create({
  boot: {
    alignItems: 'center',
    backgroundColor: colors.background,
    flex: 1,
    justifyContent: 'center',
  },
});
