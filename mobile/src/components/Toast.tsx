import { createContext, ReactNode, useContext, useMemo, useState } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { colors } from '../theme/colors';

type ToastKind = 'success' | 'error' | 'info';

type ToastState = {
  message: string;
  kind: ToastKind;
} | null;

type ToastContextValue = {
  showToast: (message: string, kind?: ToastKind) => void;
};

const ToastContext = createContext<ToastContextValue | null>(null);

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toast, setToast] = useState<ToastState>(null);

  const value = useMemo(() => ({
    showToast(message: string, kind: ToastKind = 'info') {
      setToast({ message, kind });
      setTimeout(() => setToast(null), 2600);
    },
  }), []);

  return (
    <ToastContext.Provider value={value}>
      {children}
      {toast ? (
        <Pressable onPress={() => setToast(null)} style={[styles.toast, styles[toast.kind]]}>
          <Text style={styles.toastText}>{toast.message}</Text>
        </Pressable>
      ) : null}
    </ToastContext.Provider>
  );
}

export function useToast() {
  const value = useContext(ToastContext);
  if (!value) {
    throw new Error('useToast must be used inside ToastProvider');
  }
  return value;
}

const styles = StyleSheet.create({
  toast: {
    borderColor: 'rgba(255,255,255,0.12)',
    borderRadius: 16,
    borderWidth: 1,
    bottom: 96,
    left: 18,
    paddingHorizontal: 16,
    paddingVertical: 13,
    position: 'absolute',
    right: 18,
    shadowColor: '#000',
    shadowOpacity: 0.35,
    shadowRadius: 18,
  },
  success: { backgroundColor: '#123221' },
  error: { backgroundColor: '#35151d' },
  info: { backgroundColor: colors.surface },
  toastText: { color: colors.text, fontSize: 13, fontWeight: '900', textAlign: 'center' },
});
