import { Modal, Pressable, StyleSheet, Text, View } from 'react-native';
import { colors } from '../theme/colors';

export function ConfirmModal({
  visible,
  title,
  message,
  confirmLabel = 'Confirmar',
  destructive,
  onCancel,
  onConfirm,
}: {
  visible: boolean;
  title: string;
  message: string;
  confirmLabel?: string;
  destructive?: boolean;
  onCancel: () => void;
  onConfirm: () => void;
}) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onCancel}>
      <View style={styles.overlay}>
        <View style={styles.card}>
          <Text style={styles.title}>{title}</Text>
          <Text style={styles.message}>{message}</Text>
          <View style={styles.actions}>
            <Pressable onPress={onCancel} style={styles.cancelButton}>
              <Text style={styles.cancelText}>Cancelar</Text>
            </Pressable>
            <Pressable onPress={onConfirm} style={[styles.confirmButton, destructive && styles.confirmDanger]}>
              <Text style={styles.confirmText}>{confirmLabel}</Text>
            </Pressable>
          </View>
        </View>
      </View>
    </Modal>
  );
}

const styles = StyleSheet.create({
  overlay: { alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.72)', flex: 1, justifyContent: 'center', padding: 22 },
  card: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 22, borderWidth: 1, padding: 18, width: '100%' },
  title: { color: colors.text, fontSize: 20, fontWeight: '900' },
  message: { color: colors.muted, fontSize: 14, lineHeight: 21, marginTop: 10 },
  actions: { flexDirection: 'row', gap: 10, marginTop: 18 },
  cancelButton: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderRadius: 16, flex: 1, padding: 14 },
  cancelText: { color: colors.text, fontWeight: '900' },
  confirmButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, flex: 1, padding: 14 },
  confirmDanger: { backgroundColor: colors.danger },
  confirmText: { color: colors.text, fontWeight: '900' },
});
