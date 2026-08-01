import { useSyncExternalStore } from 'react';
import { Pressable, StyleSheet, Text, View } from 'react-native';
import { flushOfflineQueue, getOfflineSnapshot, retryFailedMutations, subscribeOfflineState } from '../offline/manager';
import { colors } from '../theme/colors';

export function OfflineBanner() {
  const state = useSyncExternalStore(subscribeOfflineState, getOfflineSnapshot, getOfflineSnapshot);
  if (state.online && !state.syncing && !state.pending && !state.failed) return null;

  const message = state.syncing
    ? `Sincronizando ${state.pending} alteracao${state.pending === 1 ? '' : 'es'}...`
    : !state.online
      ? state.pending
        ? `Offline - ${state.pending} alteracao${state.pending === 1 ? '' : 'es'} salva${state.pending === 1 ? '' : 's'} no aparelho`
        : 'Offline - mostrando os ultimos dados salvos'
      : state.failed
        ? `${state.failed} alteracao${state.failed === 1 ? '' : 'es'} precisa${state.failed === 1 ? '' : 'm'} de atencao`
        : `${state.pending} alteracao${state.pending === 1 ? '' : 'es'} aguardando sincronizacao`;

  return (
    <Pressable disabled={!state.online || state.syncing || (!state.pending && !state.failed)} onPress={() => state.failed ? retryFailedMutations() : flushOfflineQueue()} style={[styles.banner, state.failed ? styles.failed : null]}>
      <View style={[styles.dot, state.online ? styles.dotOnline : null]} />
      <Text numberOfLines={2} style={styles.text}>{message}</Text>
      {state.online && (state.pending || state.failed) && !state.syncing ? <Text style={styles.action}>Tentar agora</Text> : null}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  banner: {
    alignItems: 'center',
    backgroundColor: '#4a3717',
    borderBottomColor: '#d99951',
    borderBottomWidth: 1,
    flexDirection: 'row',
    gap: 8,
    minHeight: 36,
    paddingHorizontal: 14,
    paddingVertical: 8,
  },
  failed: { backgroundColor: '#4a2424', borderBottomColor: '#d77a68' },
  dot: { backgroundColor: '#d99951', borderRadius: 5, height: 9, width: 9 },
  dotOnline: { backgroundColor: '#65c98b' },
  text: { color: colors.text, flex: 1, fontSize: 12, fontWeight: '800' },
  action: { color: '#f2c277', fontSize: 11, fontWeight: '900' },
});
