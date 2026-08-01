import { useEffect, useState } from 'react';
import { ActivityIndicator, Image, Modal, Pressable, SafeAreaView, StyleSheet, Text, TextInput, View } from 'react-native';
import { logout, User } from '../api/auth';
import { sendFeedback } from '../api/feedback';
import { getNotifications, markNotificationsRead, NotificationItem } from '../api/notifications';
import { alpha, colors } from '../theme/colors';
import { useToast } from './Toast';

export function AppHeader({
  user,
  onProfile,
  onLogout,
  onCalendar,
}: {
  user: User;
  onProfile: () => void;
  onLogout: () => void;
  onCalendar: () => void;
}) {
  const { showToast } = useToast();
  const [notificationsOpen, setNotificationsOpen] = useState(false);
  const [feedbackOpen, setFeedbackOpen] = useState(false);
  const [userOpen, setUserOpen] = useState(false);
  const [notifications, setNotifications] = useState<NotificationItem[]>([]);
  const [count, setCount] = useState(0);
  const [feedback, setFeedback] = useState('');
  const [feedbackType, setFeedbackType] = useState<'bug' | 'suggest'>('bug');
  const [sendingFeedback, setSendingFeedback] = useState(false);
  const [loadingNotifications, setLoadingNotifications] = useState(false);
  const [logoutConfirmOpen, setLogoutConfirmOpen] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);

  async function loadNotifications() {
    setLoadingNotifications(true);
    try {
      const response = await getNotifications();
      setNotifications(response.notifications || []);
      setCount(response.count || 0);
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao carregar notificações.', 'error');
    } finally {
      setLoadingNotifications(false);
    }
  }

  useEffect(() => {
    loadNotifications();
    const timer = setInterval(loadNotifications, 600000);
    return () => clearInterval(timer);
  }, []);

  async function openNotifications() {
    setNotificationsOpen(true);
    await loadNotifications();
  }

  async function markAllRead() {
    try {
      const response = await markNotificationsRead();
      setNotifications([]);
      setCount(0);
      showToast(response.queued ? 'Leitura salva offline.' : 'Notificações marcadas como lidas.', response.queued ? 'info' : 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao atualizar notificações.', 'error');
    }
  }

  async function submitFeedback() {
    if (!feedback.trim() || sendingFeedback) return;
    setSendingFeedback(true);
    try {
      const response = await sendFeedback(feedbackType, feedback.trim());
      setFeedback('');
      setFeedbackOpen(false);
      showToast(response.queued ? 'Feedback salvo offline.' : 'Feedback enviado.', response.queued ? 'info' : 'success');
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao enviar feedback.', 'error');
    } finally {
      setSendingFeedback(false);
    }
  }

  async function doLogout() {
    setLoggingOut(true);
    try {
      await logout();
    } finally {
      onLogout();
      setLoggingOut(false);
    }
  }

  return (
    <View style={styles.header}>
      <View style={styles.brandRow}>
        <View style={styles.mark} />
        <Text style={styles.brand}>CineFio</Text>
      </View>
      <View style={styles.actions}>
        <Pressable onPress={onCalendar} style={styles.iconButton} accessibilityLabel="Calendário de lançamentos">
          <CalendarIcon />
        </Pressable>
        <Pressable onPress={() => setFeedbackOpen(true)} style={styles.iconButton}>
          <FeedbackIcon />
        </Pressable>
        <Pressable onPress={openNotifications} style={styles.iconButton}>
          <BellIcon />
          {count > 0 ? <View style={styles.badge}><Text style={styles.badgeText}>{count}</Text></View> : null}
        </Pressable>
        <Pressable accessibilityLabel="Abrir menu do perfil" hitSlop={8} onPress={() => setUserOpen(true)} style={styles.avatarButton}>
          {user.url_avatar ? <Image source={{ uri: user.url_avatar }} style={styles.avatarImage} /> : <Text style={styles.avatarText}>{user.nome?.[0] || 'U'}</Text>}
        </Pressable>
      </View>

      <Modal visible={userOpen} transparent animationType="fade" onRequestClose={() => setUserOpen(false)}>
        <View style={styles.userMenuModal}>
          <Pressable accessibilityLabel="Fechar menu do perfil" onPress={() => setUserOpen(false)} style={StyleSheet.absoluteFill} />
          <SafeAreaView pointerEvents="box-none" style={styles.userMenuSafeArea}>
            <View style={styles.userMenu}>
              <View style={styles.userMenuHeader}>
                <View style={styles.userMenuAvatar}>
                  {user.url_avatar ? <Image source={{ uri: user.url_avatar }} style={styles.userMenuAvatarImage} /> : <Text style={styles.userMenuAvatarText}>{user.nome?.[0] || 'U'}</Text>}
                </View>
                <View style={styles.userMenuIdentity}>
                  <Text numberOfLines={1} style={styles.userName}>{user.nome} {user.sobrenome}</Text>
                  <Text numberOfLines={1} style={styles.userEmail}>@{user.nome_usuario}</Text>
                </View>
              </View>
              <Pressable onPress={() => { setUserOpen(false); onProfile(); }} style={styles.profileMenuItem}>
                <Text style={styles.profileMenuIcon}>P</Text>
                <View>
                  <Text style={styles.profileMenuTitle}>Perfil</Text>
                  {/* <Text style={styles.profileMenuSubtitle}>Coleção</Text> */}
                </View>
              </Pressable>
              <Pressable onPress={() => { setUserOpen(false); setLogoutConfirmOpen(true); }} style={styles.logoutMenuItem}>
                <View style={styles.logoutMenuIcon}>
                  <View style={styles.logoutDoor} />
                  <View style={styles.logoutArrow} />
                </View>
                <View>
                  <Text style={styles.logoutMenuTitle}>Sair</Text>
                  {/* <Text style={styles.logoutMenuSubtitle}>Encerrar sessão neste dispositivo</Text> */}
                </View>
              </Pressable>
            </View>
          </SafeAreaView>
        </View>
      </Modal>

      <Modal visible={notificationsOpen} transparent animationType="fade" onRequestClose={() => setNotificationsOpen(false)}>
        <View style={styles.overlay}>
          <View style={styles.sheet}>
            <View style={styles.sheetHeader}>
              <Text style={styles.sheetTitle}>Notificações</Text>
              <Pressable onPress={() => setNotificationsOpen(false)} style={styles.closeButton}>
                <Text style={styles.close}>Fechar</Text>
              </Pressable>
            </View>

            <View style={styles.sheetSubHeader}>
              <Pressable onPress={markAllRead} style={styles.readButton}>
                <Text style={styles.readText}>Marcar todas como lidas</Text>
              </Pressable>
              <Text style={styles.readHint}>{notifications.length} pendentes</Text>
            </View>

            {loadingNotifications ? <ActivityIndicator color={colors.accent} style={{ padding: 24 }} /> : null}

            {!loadingNotifications && notifications.length === 0 ? (
              <View style={styles.emptyState}>
                <Text style={styles.emptyStateTitle}>Sem notificações no momento</Text>
                <Text style={styles.emptyStateText}>Quando houver novos episodios, estreias ou alertas do sistema, eles aparecem aqui.</Text>
              </View>
            ) : null}

            {sortNotifications(notifications).map((item, index) => (
              <View key={String(item.id_notificacao || index)} style={styles.notificationCard}>
                <View style={styles.notificationIcon}>
                  <Text style={styles.notificationIconText}>{notificationGlyph(item.tipo)}</Text>
                </View>
                <View style={styles.notificationBody}>
                  <Text style={styles.notificationTitle}>{formatNotificationTitle(item.titulo || item.title)}</Text>
                  <Text style={styles.notificationText}>{formatNotificationMessage(item.mensagem || item.message || item.content)}</Text>
                  <Text style={styles.notificationTime}>{formatNotificationTime(item.mensagem || item.message || item.content, item.ts_criacao || item.ts_inclusao || item.created_at)}</Text>
                </View>
              </View>
            ))}
          </View>
        </View>
      </Modal>

      <Modal visible={feedbackOpen} transparent animationType="fade" onRequestClose={() => setFeedbackOpen(false)}>
        <View style={styles.overlay}>
          <View style={styles.sheet}>
            <View style={styles.sheetHeader}>
              <Text style={styles.sheetTitle}>Enviar feedback</Text>
              <Pressable onPress={() => setFeedbackOpen(false)} style={styles.closeButton}>
                <Text style={styles.close}>Fechar</Text>
              </Pressable>
            </View>
            <View style={styles.feedbackTypes}>
              <Pressable onPress={() => setFeedbackType('bug')} style={[styles.feedbackTypeButton, feedbackType === 'bug' && styles.feedbackTypeActive]}>
                <Text style={[styles.feedbackTypeText, feedbackType === 'bug' && styles.feedbackTypeTextActive]}>Bug</Text>
              </Pressable>
              <Pressable onPress={() => setFeedbackType('suggest')} style={[styles.feedbackTypeButton, feedbackType === 'suggest' && styles.feedbackTypeActive]}>
                <Text style={[styles.feedbackTypeText, feedbackType === 'suggest' && styles.feedbackTypeTextActive]}>Sugestao</Text>
              </Pressable>
            </View>
            <TextInput
              multiline
              onChangeText={setFeedback}
              placeholder={feedbackType === 'bug' ? 'Descreva o bug encontrado...' : 'Conte sua sugestao para melhorar o app...'}
              placeholderTextColor={colors.muted}
              style={styles.feedbackInput}
              value={feedback}
            />
            <Pressable disabled={sendingFeedback || !feedback.trim()} onPress={submitFeedback} style={[styles.sendButton, (sendingFeedback || !feedback.trim()) && styles.sendButtonDisabled]}>
              {sendingFeedback ? <ActivityIndicator color={colors.text} /> : <Text style={styles.sendText}>Enviar</Text>}
            </Pressable>
          </View>
        </View>
      </Modal>

      <ConfirmLogoutModal
        visible={logoutConfirmOpen}
        loading={loggingOut}
        onCancel={() => !loggingOut && setLogoutConfirmOpen(false)}
        onConfirm={doLogout}
      />
    </View>
  );
}

function ConfirmLogoutModal({
  visible,
  loading,
  onCancel,
  onConfirm,
}: {
  visible: boolean;
  loading: boolean;
  onCancel: () => void;
  onConfirm: () => void | Promise<void>;
}) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onCancel}>
      <View style={styles.logoutOverlay}>
        <View style={styles.logoutBox}>
          <Text style={styles.logoutTitle}>Deslogar do app?</Text>
          <View style={styles.logoutActions}>
            <Pressable disabled={loading} onPress={onCancel} style={[styles.logoutCancel, loading && styles.logoutDisabled]}><Text style={styles.logoutCancelText}>Cancelar</Text></Pressable>
            <Pressable disabled={loading} onPress={onConfirm} style={[styles.logoutConfirm, loading && styles.logoutDisabled]}>
              {loading ? (
                <View style={styles.logoutLoadingRow}>
                  <ActivityIndicator color={colors.text} />
                  <Text style={styles.logoutConfirmText}>Deslogando...</Text>
                </View>
              ) : <Text style={styles.logoutConfirmText}>Deslogar</Text>}
            </Pressable>
          </View>
        </View>
      </View>
    </Modal>
  );
}

function BellIcon() {
  return (
    <View style={styles.drawIcon}>
      <View style={styles.bellBody} />
      <View style={styles.bellClapper} />
    </View>
  );
}

function CalendarIcon() {
  return <View style={styles.calendarIcon}><View style={styles.calendarTop} /><View style={styles.calendarGrid}><View style={styles.calendarDot} /><View style={styles.calendarDot} /><View style={styles.calendarDot} /><View style={styles.calendarDot} /></View></View>;
}

function FeedbackIcon() {
  return (
    <View style={styles.drawIcon}>
      <View style={styles.feedbackBubble} />
      <View style={styles.feedbackTail} />
      <View style={styles.feedbackDot} />
    </View>
  );
}

function formatNotificationTitle(title?: string) {
  if (!title) return 'CineFio';
  return title
    .replace(/^Novo episódio:\s*/i, 'Novo episódio: ')
    .replace(/^Nova estreia:\s*/i, 'Nova estreia: ')
    .replace(/^Nova atualização:\s*/i, 'Nova atualização: ');
}

function formatNotificationMessage(message?: string) {
  if (!message) return 'Nova atualização disponível.';
  return message
    .replace(/\s+—\s+/g, ' - ')
    .replace(/\s+\|\s+/g, ' · ')
    .replace(/\s+\((ontem|há \d+ dias|\d+ dias atrás)\)$/i, '')
    .trim();
}

function formatNotificationTime(message?: string, fallback?: string) {
  const extracted = extractNotificationDate(message);
  const date = extracted || (fallback ? new Date(fallback) : null);
  if (!date) return '';
  if (Number.isNaN(date.getTime())) return '';
  const dayName = new Intl.DateTimeFormat('pt-BR', { weekday: 'long' }).format(date);
  const day = new Intl.DateTimeFormat('pt-BR', { day: '2-digit' }).format(date);
  const month = new Intl.DateTimeFormat('pt-BR', { month: 'short' }).format(date).replace('.', '');
  const year = new Intl.DateTimeFormat('pt-BR', { year: 'numeric' }).format(date);
  const time = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit', hour12: false }).format(date);
  return `${capitalize(dayName)}, ${day} de ${month} de ${year} · ${time}`;
}

function extractNotificationDate(message?: string) {
  if (!message) return null;

  const brDate = message.match(/\b(\d{2}\/\d{2}\/\d{4})\b/);
  if (brDate?.[1]) {
    const [day, month, year] = brDate[1].split('/').map(Number);
    return new Date(year, month - 1, day);
  }

  const isoDate = message.match(/\b(\d{4}-\d{2}-\d{2})\b/);
  if (isoDate?.[1]) {
    return new Date(`${isoDate[1]}T00:00:00`);
  }

  return null;
}

function sortNotifications(items: NotificationItem[]) {
  return [...items].sort((a, b) => {
    const aEpisode = extractEpisodeNumber(a.mensagem || a.message || a.content);
    const bEpisode = extractEpisodeNumber(b.mensagem || b.message || b.content);
    if (aEpisode !== bEpisode) return bEpisode - aEpisode;

    const aDate = extractNotificationDate(a.mensagem || a.message || a.content)?.getTime() || 0;
    const bDate = extractNotificationDate(b.mensagem || b.message || b.content)?.getTime() || 0;
    return bDate - aDate;
  });
}

function extractEpisodeNumber(message?: string) {
  const match = message?.match(/\bS\d+E(\d+)\b/i);
  return match?.[1] ? parseInt(match[1], 10) : 0;
}

function notificationGlyph(type?: string) {
  switch (type) {
    case 'new_episode':
      return 'EP';
    case 'release_date':
      return 'LAN';
    default:
      return 'i';
  }
}

function capitalize(value: string) {
  return value.charAt(0).toUpperCase() + value.slice(1);
}

const styles = StyleSheet.create({
  header: {
    backgroundColor: colors.background,
    borderBottomColor: colors.surfaceRaised,
    borderBottomWidth: 1,
    flexDirection: 'row',
    justifyContent: 'space-between',
    paddingHorizontal: 16,
    paddingBottom: 12,
    paddingTop: 16,
    zIndex: 20,
  },
  brandRow: { alignItems: 'center', flexDirection: 'row', gap: 10 },
  mark: {
    borderBottomColor: colors.accent,
    borderBottomWidth: 18,
    borderLeftColor: 'transparent',
    borderLeftWidth: 10,
    borderRightColor: 'transparent',
    borderRightWidth: 10,
    height: 0,
    width: 0,
  },
  brand: { color: colors.text, fontSize: 18, fontWeight: '900' },
  actions: { alignItems: 'center', flexDirection: 'row', gap: 10 },
  iconButton: { alignItems: 'center', height: 34, justifyContent: 'center', width: 34 },
  drawIcon: { height: 24, position: 'relative', width: 24 },
  bellBody: { backgroundColor: colors.muted, borderTopLeftRadius: 10, borderTopRightRadius: 10, height: 15, left: 6, position: 'absolute', top: 4, width: 12 },
  bellClapper: { backgroundColor: colors.muted, borderRadius: 3, bottom: 2, height: 5, left: 10, position: 'absolute', width: 5 },
  feedbackBubble: { borderColor: colors.muted, borderRadius: 6, borderWidth: 2, height: 16, left: 3, position: 'absolute', top: 4, width: 18 },
  feedbackTail: { backgroundColor: colors.muted, bottom: 3, height: 6, left: 8, position: 'absolute', transform: [{ rotate: '45deg' }], width: 6 },
  feedbackDot: { backgroundColor: colors.muted, borderRadius: 2, height: 4, left: 10, position: 'absolute', top: 10, width: 4 },
  calendarIcon: { borderColor: colors.accent, borderRadius: 5, borderWidth: 2, height: 21, overflow: 'hidden', width: 22 },
  calendarTop: { backgroundColor: colors.accent, height: 4, width: '100%' },
  calendarGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 3, padding: 4 },
  calendarDot: { backgroundColor: colors.accent, borderRadius: 1, height: 3, width: 4 },
  badge: { alignItems: 'center', backgroundColor: colors.danger, borderRadius: 999, minWidth: 16, paddingHorizontal: 4, position: 'absolute', right: 1, top: 1 },
  badgeText: { color: colors.text, fontSize: 9, fontWeight: '900' },
  avatarButton: { alignItems: 'center', borderColor: colors.muted, borderRadius: 18, borderWidth: 1, height: 34, justifyContent: 'center', width: 34 },
  avatarImage: { borderRadius: 17, height: 32, width: 32 },
  avatarText: { color: colors.text, fontSize: 13, fontWeight: '900' },
  userMenuModal: { flex: 1 },
  userMenuSafeArea: { alignItems: 'flex-end', flex: 1, paddingRight: 12, paddingTop: 54 },
  userMenu: {
    backgroundColor: colors.surface,
    borderColor: 'rgba(255,255,255,0.08)',
    borderRadius: 20,
    borderWidth: 1,
    padding: 14,
    width: 252,
    zIndex: 30,
    shadowColor: '#000',
    shadowOpacity: 0.38,
    shadowRadius: 18,
    elevation: 10,
  },
  userMenuHeader: { alignItems: 'center', flexDirection: 'row', gap: 10 },
  userMenuAvatar: { alignItems: 'center', backgroundColor: alpha(colors.accent, 0.18), borderColor: alpha(colors.accent, 0.48), borderRadius: 18, borderWidth: 1, height: 36, justifyContent: 'center', width: 36 },
  userMenuAvatarImage: { borderRadius: 17, height: 34, width: 34 },
  userMenuAvatarText: { color: colors.text, fontSize: 13, fontWeight: '900' },
  userMenuIdentity: { flex: 1 },
  userName: { color: colors.text, fontWeight: '900' },
  userEmail: { color: colors.muted, fontSize: 12, marginTop: 3 },
  profileMenuItem: { alignItems: 'center', backgroundColor: colors.background, borderColor: colors.surfaceRaised, borderRadius: 16, borderWidth: 1, flexDirection: 'row', gap: 12, marginTop: 14, padding: 12 },
  profileMenuIcon: { color: colors.accent, fontSize: 15, fontWeight: '900', textAlign: 'center', width: 34 },
  profileMenuTitle: { color: colors.text, fontSize: 13, fontWeight: '900' },
  profileMenuSubtitle: { color: colors.muted, fontSize: 10, fontWeight: '700', marginTop: 3 },
  logoutMenuItem: { alignItems: 'center', backgroundColor: '#09090f', borderColor: 'rgba(255,95,135,0.22)', borderRadius: 16, borderWidth: 1, flexDirection: 'row', gap: 12, marginTop: 14, padding: 12 },
  logoutMenuIcon: { alignItems: 'center', backgroundColor: 'rgba(255,95,135,0.12)', borderRadius: 12, height: 34, justifyContent: 'center', position: 'relative', width: 34 },
  logoutDoor: { borderColor: colors.danger, borderRadius: 3, borderWidth: 2, height: 16, width: 12 },
  logoutArrow: { borderRightColor: colors.danger, borderRightWidth: 2, borderTopColor: colors.danger, borderTopWidth: 2, height: 8, position: 'absolute', right: 9, transform: [{ rotate: '45deg' }], width: 8 },
  logoutMenuTitle: { color: colors.text, fontSize: 13, fontWeight: '900' },
  logoutMenuSubtitle: { color: colors.muted, fontSize: 10, fontWeight: '700', marginTop: 3 },
  overlay: { backgroundColor: 'rgba(0,0,0,0.72)', flex: 1, justifyContent: 'flex-start', padding: 16, paddingTop: 70 },
  sheet: {
    backgroundColor: '#171722',
    borderColor: 'rgba(255,255,255,0.06)',
    borderRadius: 24,
    borderWidth: 1,
    padding: 16,
    shadowColor: '#000',
    shadowOpacity: 0.35,
    shadowRadius: 20,
    elevation: 8,
  },
  sheetHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  sheetTitle: { color: colors.text, fontSize: 20, fontWeight: '900' },
  closeButton: {
    backgroundColor: 'rgba(255,255,255,0.05)',
    borderRadius: 999,
    paddingHorizontal: 12,
    paddingVertical: 8,
  },
  close: { color: '#c9bfdc', fontWeight: '900' },
  sheetSubHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginTop: 10 },
  readButton: { alignSelf: 'flex-start' },
  readText: { color: colors.accent, fontSize: 12, fontWeight: '900' },
  readHint: { color: colors.muted, fontSize: 11, fontWeight: '700' },
  emptyState: {
    alignItems: 'center',
    backgroundColor: colors.background,
    borderColor: colors.surfaceRaised,
    borderRadius: 16,
    borderWidth: 1,
    marginTop: 16,
    padding: 16,
  },
  emptyStateTitle: { color: colors.text, fontSize: 14, fontWeight: '900' },
  emptyStateText: { color: colors.muted, fontSize: 12, lineHeight: 18, marginTop: 6, textAlign: 'center' },
  notificationCard: {
    alignItems: 'flex-start',
    backgroundColor: '#0d0d14',
    borderColor: alpha(colors.accent, 0.12),
    borderRadius: 18,
    borderWidth: 1,
    flexDirection: 'row',
    gap: 12,
    marginTop: 12,
    padding: 14,
  },
  notificationIcon: {
    alignItems: 'center',
    backgroundColor: alpha(colors.accent, 0.20),
    borderColor: alpha(colors.accent, 0.45),
    borderRadius: 13,
    borderWidth: 1,
    height: 34,
    justifyContent: 'center',
    width: 34,
  },
  notificationIconText: { color: '#d9d1ff', fontSize: 11, fontWeight: '900' },
  notificationBody: { flex: 1 },
  notificationTitle: { color: colors.text, fontSize: 14, fontWeight: '900', letterSpacing: 0.1 },
  notificationText: { color: '#a7a0bc', fontSize: 12, lineHeight: 18, marginTop: 4 },
  notificationTime: { color: '#8b84a1', fontSize: 10, fontWeight: '700', marginTop: 8 },
  feedbackInput: { backgroundColor: colors.background, borderColor: colors.surfaceRaised, borderRadius: 16, borderWidth: 1, color: colors.text, marginTop: 16, minHeight: 110, padding: 12, textAlignVertical: 'top' },
  feedbackTypes: { flexDirection: 'row', gap: 10, marginTop: 12 },
  feedbackTypeButton: { alignItems: 'center', backgroundColor: colors.background, borderColor: colors.surfaceRaised, borderRadius: 999, borderWidth: 1, flex: 1, paddingVertical: 11 },
  feedbackTypeActive: { backgroundColor: colors.accent, borderColor: colors.accent },
  feedbackTypeText: { color: colors.muted, fontWeight: '900' },
  feedbackTypeTextActive: { color: colors.text },
  sendButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, marginTop: 12, padding: 14 },
  sendButtonDisabled: { opacity: 0.55 },
  sendText: { color: colors.text, fontWeight: '900' },
  logoutOverlay: { alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.72)', flex: 1, justifyContent: 'center', padding: 18 },
  logoutBox: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 22, borderWidth: 1, padding: 18, width: '100%' },
  logoutTitle: { color: colors.text, fontSize: 18, fontWeight: '900' },
  logoutMessage: { color: colors.muted, fontSize: 14, lineHeight: 20, marginTop: 10 },
  logoutActions: { flexDirection: 'row', gap: 10, marginTop: 18 },
  logoutCancel: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderRadius: 16, flex: 1, padding: 14 },
  logoutCancelText: { color: colors.text, fontWeight: '900' },
  logoutConfirm: { alignItems: 'center', backgroundColor: colors.danger, borderRadius: 16, flex: 1, padding: 14 },
  logoutConfirmText: { color: colors.text, fontWeight: '900' },
  logoutLoadingRow: { alignItems: 'center', flexDirection: 'row', gap: 8, justifyContent: 'center' },
  logoutDisabled: { opacity: 0.7 },
});
