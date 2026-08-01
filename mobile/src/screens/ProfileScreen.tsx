import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Image, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { clearLibrary, deleteAccount, logout, updateProfile, User } from '../api/auth';
import { getProfile } from '../api/mobile';
import { ConfirmModal } from '../components/ConfirmModal';
import { PosterCard } from '../components/PosterCard';
import { PosterSkeletonRow, Skeleton } from '../components/Skeleton';
import { useToast } from '../components/Toast';
import { saveUser } from '../storage/session';
import { colors } from '../theme/colors';
import { Item } from '../types';

export function ProfileScreen({
  user,
  onOpenItem,
  onLogout,
  onUserUpdated,
}: {
  user: User;
  onOpenItem: (item: Item) => void;
  onLogout: () => void;
  onUserUpdated: (user: User) => void;
}) {
  const [profile, setProfile] = useState<Awaited<ReturnType<typeof getProfile>>['data'] | null>(null);
  const [loading, setLoading] = useState(true);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [showAllActivity, setShowAllActivity] = useState(false);
  const [selectedActivity, setSelectedActivity] = useState<ActivityItem | null>(null);
  const [activityJournalOpen, setActivityJournalOpen] = useState(false);
  const [timeInfoOpen, setTimeInfoOpen] = useState(false);

  useEffect(() => {
    getProfile().then((res) => setProfile(res.data)).finally(() => setLoading(false));
  }, []);

  const initials = `${user.nome?.[0] || ''}${user.sobrenome?.[0] || ''}` || 'TV';
  const activityItems = groupActivity(profile?.history || []);
  const visibleActivity = showAllActivity ? activityItems : activityItems.slice(0, 10);
  const reviews = profile?.reviews || [];

  return (
    <ScrollView style={styles.screen}>
      <View style={styles.header}>
        <View style={styles.avatar}><Text style={styles.avatarText}>{initials}</Text></View>
        <View style={{ flex: 1 }}>
          <Text style={styles.name}>{user.nome} {user.sobrenome}</Text>
          <Text style={styles.username}>@{user.username}</Text>
        </View>
        <Pressable onPress={() => setSettingsOpen(true)} style={styles.settingsButton}>
          <Text style={styles.settingsIcon}>#</Text>
        </Pressable>
      </View>

      {loading ? (
        <>
          <Skeleton height={92} />
          <View style={{ height: 12 }} />
          <PosterSkeletonRow />
        </>
      ) : (
        <>
          <Pressable onPress={() => setTimeInfoOpen(true)} style={styles.timeBox}>
            <View style={styles.timeHeader}>
              <View>
                <Text style={styles.timeText}>{profile?.time.days}d {profile?.time.hours}h {profile?.time.minutes}m</Text>
                <Text style={styles.count}>Tempo visto</Text>
              </View>
              <Text style={styles.timeInfoIcon}>!</Text>
            </View>
          </Pressable>

          <View style={styles.statsGrid}>
            <Stat label="Filmes" value={profile?.stats.moviesCount || 0} />
            <Stat label="Series" value={profile?.stats.seriesCount || 0} />
            <Stat label="Animes" value={profile?.stats.animeCount || 0} />
          </View>

          <Text style={styles.sectionTitle}>Favoritos</Text>
          <FlatList
            horizontal
            data={profile?.favorites || []}
            keyExtractor={(item, index) => String(item.id_item || index)}
            renderItem={({ item }) => <PosterCard item={item} onPress={onOpenItem} />}
            ItemSeparatorComponent={() => <View style={{ width: 12 }} />}
            showsHorizontalScrollIndicator={false}
            ListEmptyComponent={<EmptyCard title="Nenhum favorito ainda." body="Toque no coracão em qualquer titulo para guardar aqui." />}
          />

          <Text style={styles.sectionTitle}>Avaliações</Text>
          <FlatList
            horizontal
            data={reviews}
            keyExtractor={(item, index) => String(item.id_item || index)}
            renderItem={({ item }) => (
              <ReviewCard
                comment={item.comment}
                posterUrl={item.poster_url}
                rating={Number(item.rating || 0)}
                title={item.title}
                type={item.type}
                year={item.release_year}
                onPress={onOpenItem}
                item={item as unknown as Item}
              />
            )}
            ItemSeparatorComponent={() => <View style={{ width: 12 }} />}
            showsHorizontalScrollIndicator={false}
            ListEmptyComponent={<EmptyCard title="Sem avaliações ainda." body="Quando avaliares um filme, série ou anime, ele aparece aqui." />}
          />

          <View style={styles.sectionHeaderRow}>
            <Text style={styles.sectionTitle}>Atividade recente</Text>
            {activityItems.length > 0 ? (
              <Pressable onPress={() => setActivityJournalOpen(true)} style={styles.moreInlineButton}>
                <Text style={styles.moreInlineText}>Ver</Text>
              </Pressable>
            ) : null}
          </View>
          <FlatList
            horizontal
            data={visibleActivity}
            keyExtractor={(item, index) => String(item.id_item || `${item.title}-${index}`)}
            renderItem={({ item }) => <ActivityCard item={item} onPress={() => setSelectedActivity(item)} />}
            ItemSeparatorComponent={() => <View style={{ width: 12 }} />}
            showsHorizontalScrollIndicator={false}
            ListEmptyComponent={<EmptyCard title="Sem atividade recente." body="Quando assistires algo, as últimas ações aparecem aqui." />}
          />
        </>
      )}

      <SettingsModal
        user={user}
        visible={settingsOpen}
        onClose={() => setSettingsOpen(false)}
        onLogout={onLogout}
        onUserUpdated={onUserUpdated}
      />
      <ActivityModal
        item={selectedActivity}
        onClose={() => setSelectedActivity(null)}
        onOpenItem={onOpenItem}
      />
      <ActivityJournalModal
        items={activityItems}
        onClose={() => setActivityJournalOpen(false)}
        onOpenItem={onOpenItem}
        visible={activityJournalOpen}
      />
      <TimeInfoModal visible={timeInfoOpen} onClose={() => setTimeInfoOpen(false)} />
      <View style={{ height: 120 }} />
    </ScrollView>
  );
}

function EmptyCard({ title, body }: { title: string; body: string }) {
  return (
    <View style={styles.emptyCard}>
      <Text style={styles.emptyTitle}>{title}</Text>
      <Text style={styles.emptyBody}>{body}</Text>
    </View>
  );
}

function ReviewCard({
  title,
  year,
  rating,
  comment,
  posterUrl,
  type,
  onPress,
  item,
}: {
  title: string;
  year?: number;
  rating: number;
  comment: string;
  posterUrl: string;
  type: Item['type'];
  onPress: (item: Item) => void;
  item: Item;
}) {
  return (
    <Pressable onPress={() => onPress(item)} style={styles.reviewCard}>
      <Image source={{ uri: posterUrl }} style={styles.reviewPoster} />
      <View style={styles.reviewCopy}>
        <Text numberOfLines={1} style={styles.reviewTitleItem}>{title}</Text>
        <Text style={styles.reviewMeta}>{year ? `${year} - ${labelType(type)}` : labelType(type)}</Text>
        <Text style={styles.reviewStars}>{'★'.repeat(Math.max(1, Math.round(rating)))} {rating.toFixed(1)}</Text>
        <Text numberOfLines={3} style={styles.reviewComment}>{comment}</Text>
      </View>
    </Pressable>
  );
}

function SettingsModal({
  user,
  visible,
  onClose,
  onLogout,
  onUserUpdated,
}: {
  user: User;
  visible: boolean;
  onClose: () => void;
  onLogout: () => void;
  onUserUpdated: (user: User) => void;
}) {
  const [nome, setNome] = useState(user.nome);
  const [sobrenome, setSobrenome] = useState(user.sobrenome);
  const [username, setUsername] = useState(user.username);
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [confirmAction, setConfirmAction] = useState<'clear' | 'delete' | null>(null);
  const { showToast } = useToast();

  async function saveProfile() {
    if (saving) return;
    setSaving(true);
    try {
      const response = await updateProfile({
        username,
        nome,
        sobrenome,
        current_password: currentPassword,
        new_password: newPassword,
        confirm_new_password: confirmPassword,
      });
      if (response.data) {
        await saveUser(response.data);
        onUserUpdated(response.data);
      }
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      showToast('Perfil atualizado.', 'success');
      onClose();
    } catch (error) {
      showToast(error instanceof Error ? error.message : 'Erro ao salvar perfil.', 'error');
    } finally {
      setSaving(false);
    }
  }

  async function doClearLibrary() {
    await clearLibrary();
    setConfirmAction(null);
    showToast('Biblioteca limpa.', 'success');
  }

  async function doDeleteAccount() {
    await deleteAccount();
    setConfirmAction(null);
    onLogout();
  }

  async function doLogout() {
    if (loggingOut) return;
    setLoggingOut(true);
    try {
      await logout();
    } finally {
      onLogout();
      setLoggingOut(false);
    }
  }

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={onClose}>
      <View style={styles.modalOverlay}>
        <ScrollView style={styles.modalSheet} contentContainerStyle={{ paddingBottom: 28 }}>
          <View style={styles.modalHeader}>
            <Text style={styles.modalTitle}>Configuracoes</Text>
            <Pressable onPress={onClose}><Text style={styles.close}>Fechar</Text></Pressable>
          </View>

          <View style={styles.twoColumns}>
            <Field label="Nome" value={nome} onChangeText={setNome} />
            <Field label="Sobrenome" value={sobrenome} onChangeText={setSobrenome} />
          </View>
          <Field label="Nome de usuario" value={username} onChangeText={setUsername} />

          <Pressable onPress={() => setPasswordOpen((value) => !value)} style={styles.collapseHeader}>
            <Text style={styles.groupTitle}>Trocar senha</Text>
            <Text style={styles.collapseText}>{passwordOpen ? 'Fechar' : 'Abrir'}</Text>
          </Pressable>
          {passwordOpen ? (
            <>
              <Field label="Senha atual" value={currentPassword} onChangeText={setCurrentPassword} secure />
              <Field label="Nova senha" value={newPassword} onChangeText={setNewPassword} secure />
              <Field label="Confirmar nova senha" value={confirmPassword} onChangeText={setConfirmPassword} secure />
            </>
          ) : null}

          <Pressable disabled={saving} onPress={saveProfile} style={styles.primaryButton}>
            {saving ? <ActivityIndicator color={colors.text} /> : <Text style={styles.primaryButtonText}>Salvar</Text>}
          </Pressable>

          <Text style={styles.dangerTitle}>Zona perigosa</Text>
          <Pressable onPress={() => setConfirmAction('clear')} style={styles.dangerButton}><Text style={styles.dangerText}>Limpar biblioteca</Text></Pressable>
          <Pressable onPress={() => setConfirmAction('delete')} style={styles.dangerButton}><Text style={styles.dangerText}>Excluir conta</Text></Pressable>
          <Pressable disabled={loggingOut} onPress={doLogout} style={[styles.logoutButton, loggingOut && styles.logoutButtonDisabled]}>
            {loggingOut ? (
              <View style={styles.logoutLoadingRow}>
                <ActivityIndicator color={colors.text} />
                <Text style={styles.logoutText}>Deslogando...</Text>
              </View>
            ) : <Text style={styles.logoutText}>Sair</Text>}
          </Pressable>

          <ConfirmModal
            visible={confirmAction === 'clear'}
            title="Limpar biblioteca"
            message="Todos os acompanhamentos serao removidos. Deseja continuar?"
            confirmLabel="Limpar"
            destructive
            onCancel={() => setConfirmAction(null)}
            onConfirm={doClearLibrary}
          />
          <ConfirmModal
            visible={confirmAction === 'delete'}
            title="Excluir conta"
            message="Essa acao nao deve ser desfeita. Deseja excluir sua conta?"
            confirmLabel="Excluir"
            destructive
            onCancel={() => setConfirmAction(null)}
            onConfirm={doDeleteAccount}
          />
        </ScrollView>
      </View>
    </Modal>
  );
}

function Field({ label, value, onChangeText, secure }: { label: string; value: string; onChangeText: (value: string) => void; secure?: boolean }) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        onChangeText={onChangeText}
        placeholderTextColor={colors.muted}
        secureTextEntry={secure}
        style={styles.input}
        value={value}
      />
    </View>
  );
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <View style={styles.stat}>
      <Text style={styles.statValue}>{value}</Text>
      <Text style={styles.count}>{label}</Text>
    </View>
  );
}

type ActivityItem = Item & {
  episodesLabel?: string;
  watchedAt?: string;
};

function ActivityCard({ item, onPress }: { item: ActivityItem; onPress?: (item: Item) => void }) {
  return (
    <Pressable disabled={!onPress} onPress={() => onPress?.(item)} style={styles.activityCard}>
      <PosterCard item={item} />
      {item.episodesLabel ? <Text numberOfLines={2} style={styles.activityLabel}>{item.episodesLabel}</Text> : null}
    </Pressable>
  );
}

function ActivityModal({
  item,
  onClose,
  onOpenItem,
}: {
  item: ActivityItem | null;
  onClose: () => void;
  onOpenItem: (item: Item) => void;
}) {
  if (!item) return null;

  return (
    <Modal visible={!!item} transparent animationType="fade" onRequestClose={onClose}>
      <View style={styles.activityModalOverlay}>
        <View style={styles.activityModal}>
          <View style={styles.activityModalHeader}>
            <Text style={styles.activityModalTitle}>Atividade recente</Text>
            <Pressable onPress={onClose}><Text style={styles.close}>Fechar</Text></Pressable>
          </View>
          <PosterCard item={item} />
          <Text style={styles.activityModalName}>{item.title}</Text>
          {item.episodesLabel ? <Text style={styles.activityModalMeta}>{item.episodesLabel}</Text> : null}
          <Text style={styles.activityModalDate}>{formatLongDate(item.watchedAt)}</Text>
          <Pressable onPress={() => onOpenItem(item)} style={styles.activityModalButton}>
            <Text style={styles.activityModalButtonText}>Abrir titulo</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

function ActivityJournalModal({
  visible,
  items,
  onClose,
  onOpenItem,
}: {
  visible: boolean;
  items: ActivityItem[];
  onClose: () => void;
  onOpenItem: (item: Item) => void;
}) {
  const journal = buildJournal(items);

  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <View style={styles.journalOverlay}>
        <View style={styles.journalBox}>
          <View style={styles.journalHeader}>
            <Text style={styles.journalTitle}>Journal</Text>
            <Pressable onPress={onClose}><Text style={styles.close}>Fechar</Text></Pressable>
          </View>
          <ScrollView showsVerticalScrollIndicator={false}>
            {journal.map((group) => (
              <View key={group.label} style={styles.journalGroup}>
                <Text style={styles.journalDate}>{group.label}</Text>
                {group.items.map((item, index) => (
                  <Pressable key={`${item.title}-${index}`} onPress={() => onOpenItem(item)} style={styles.journalRow}>
                    <PosterCard item={item} />
                    <View style={styles.journalCopy}>
                      <Text style={styles.journalName}>{item.title}</Text>
                      <Text style={styles.journalMeta}>{activityLabel(item)}</Text>
                    </View>
                  </Pressable>
                ))}
              </View>
            ))}
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

function TimeInfoModal({ visible, onClose }: { visible: boolean; onClose: () => void }) {
  return (
    <Modal visible={visible} transparent animationType="fade" onRequestClose={onClose}>
      <View style={styles.infoOverlay}>
        <View style={styles.infoBox}>
          <Text style={styles.infoTitle}>Sobre o tempo de visualização</Text>
          <Text style={styles.infoText}>
            O teu tempo de visualização é estimado a partir da tua biblioteca rastreada. Usa durações por episódio quando disponíveis,
            depois medianas de género, depois valores predefinidos (24 min anime / 45 min TV). Reimportar ou atualizar a biblioteca recalibra automaticamente.
          </Text>
          <Pressable onPress={onClose} style={styles.infoButton}>
            <Text style={styles.infoButtonText}>Fechar</Text>
          </Pressable>
        </View>
      </View>
    </Modal>
  );
}

function groupActivity(history: Array<Record<string, unknown>>): ActivityItem[] {
  const grouped = new Map<string, ActivityItem & { episodes: string[] }>();

  history.forEach((entry) => {
    const id = String(entry.id_item || entry.show_title || Math.random());
    const current = grouped.get(id) || {
      id_item: Number(entry.id_item || 0) || null,
      title: String(entry.show_title || ''),
      type: String(entry.type || 'series'),
      poster_url: String(entry.poster_url || ''),
      release_year: undefined,
      watchedAt: String(entry.watched_at || ''),
      episodes: [],
    };

    if (entry.media_type === 'episode') {
      current.episodes.push(`T${entry.season_number}E${entry.episode_number}`);
    }

    grouped.set(id, current);
  });

  return Array.from(grouped.values()).map((item) => ({
    ...item,
    episodesLabel: item.episodes.length ? `${item.episodes.slice(0, 4).join(', ')}${item.episodes.length > 4 ? ` +${item.episodes.length - 4}` : ''}` : undefined,
  }));
}

function formatLongDate(value?: string) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const weekday = new Intl.DateTimeFormat('pt-BR', { weekday: 'long' }).format(date);
  const longDate = new Intl.DateTimeFormat('pt-BR', { day: '2-digit', month: 'long', year: 'numeric' }).format(date);
  return `${weekday}, ${longDate}`;
}

function buildJournal(items: ActivityItem[]) {
  const groups = new Map<string, ActivityItem[]>();
  items.forEach((item) => {
    const key = item.watchedAt ? new Intl.DateTimeFormat('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' }).format(new Date(item.watchedAt)) : 'Sem data';
    groups.set(key, [...(groups.get(key) || []), item]);
  });
  return Array.from(groups.entries()).map(([label, groupItems]) => ({ label, items: groupItems }));
}

function activityLabel(item: ActivityItem) {
  if (item.episodesLabel) return item.episodesLabel;
  return item.release_year ? String(item.release_year) : 'Atividade';
}

function labelType(type?: string) {
  if (type === 'movie') return 'Filme';
  if (type === 'anime') return 'Anime';
  return 'Série'
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1, padding: 16 },
  header: { alignItems: 'center', flexDirection: 'row', gap: 14, marginBottom: 22 },
  avatar: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 34, height: 68, justifyContent: 'center', width: 68 },
  avatarText: { color: colors.text, fontSize: 22, fontWeight: '900' },
  name: { color: colors.text, fontSize: 20, fontWeight: '900' },
  username: { color: colors.muted, marginTop: 4 },
  settingsButton: { alignItems: 'center', backgroundColor: colors.surface, borderRadius: 18, height: 44, justifyContent: 'center', width: 44 },
  settingsIcon: { color: colors.text, fontSize: 20, fontWeight: '900' },
  timeBox: { backgroundColor: colors.surface, borderRadius: 18, marginBottom: 14, padding: 18 },
  timeText: { color: colors.text, fontSize: 28, fontWeight: '900' },
  count: { color: colors.muted, fontSize: 12, marginTop: 4 },
  statsGrid: { flexDirection: 'row', gap: 10, marginBottom: 22 },
  stat: { backgroundColor: colors.surface, borderRadius: 16, flex: 1, padding: 14 },
  statValue: { color: colors.text, fontSize: 22, fontWeight: '900' },
  sectionTitle: { color: colors.text, fontSize: 20, fontWeight: '900', marginBottom: 12, marginTop: 8 },
  sectionHeaderRow: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginTop: 8, marginBottom: 12 },
  emptyCard: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 18, borderWidth: 1, marginBottom: 18, padding: 16 },
  emptyTitle: { color: colors.text, fontSize: 15, fontWeight: '900' },
  emptyBody: { color: colors.muted, marginTop: 6 },
  activityCard: { width: 104 },
  activityLabel: { color: colors.info, fontSize: 10, fontWeight: '900', marginTop: 4 },
  activityModalOverlay: { alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.75)', flex: 1, justifyContent: 'center', padding: 20 },
  activityModal: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 24, borderWidth: 1, padding: 18, width: '100%' },
  activityModalHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 14 },
  activityModalTitle: { color: colors.text, fontSize: 18, fontWeight: '900' },
  activityModalName: { color: colors.text, fontSize: 16, fontWeight: '900', marginTop: 12 },
  activityModalMeta: { color: colors.muted, fontSize: 12, fontWeight: '800', marginTop: 4 },
  activityModalDate: { color: colors.info, fontSize: 13, fontWeight: '900', marginTop: 10 },
  activityModalButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, marginTop: 16, padding: 14 },
  activityModalButtonText: { color: colors.text, fontWeight: '900' },
  journalOverlay: { backgroundColor: 'rgba(0,0,0,0.8)', flex: 1, justifyContent: 'center', padding: 16 },
  journalBox: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 24, borderWidth: 1, maxHeight: '90%', padding: 16 },
  journalHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 14 },
  journalTitle: { color: colors.text, fontSize: 20, fontWeight: '900' },
  journalGroup: { borderTopColor: colors.surfaceRaised, borderTopWidth: 1, paddingTop: 14, marginTop: 10 },
  journalDate: { color: colors.muted, fontSize: 13, fontWeight: '900', marginBottom: 10, textTransform: 'lowercase' },
  journalRow: { alignItems: 'center', flexDirection: 'row', gap: 12, marginBottom: 14 },
  journalCopy: { flex: 1 },
  journalName: { color: colors.text, fontSize: 16, fontWeight: '900' },
  journalMeta: { color: colors.info, fontSize: 12, fontWeight: '800', marginTop: 4 },
  infoOverlay: { alignItems: 'center', backgroundColor: 'rgba(0,0,0,0.78)', flex: 1, justifyContent: 'center', padding: 18 },
  infoBox: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 22, borderWidth: 1, padding: 18, width: '100%' },
  infoTitle: { color: colors.text, fontSize: 18, fontWeight: '900', marginBottom: 12 },
  infoText: { color: colors.text, fontSize: 14, lineHeight: 22 },
  infoButton: { alignSelf: 'flex-end', marginTop: 18 },
  infoButtonText: { color: colors.accent, fontSize: 14, fontWeight: '900' },
  timeHeader: { alignItems: 'flex-start', flexDirection: 'row', justifyContent: 'space-between' },
  timeInfoIcon: { color: colors.muted, fontSize: 28, fontWeight: '900', lineHeight: 30, paddingLeft: 10 },
  moreInlineButton: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 999, borderWidth: 1, paddingHorizontal: 12, paddingVertical: 7 },
  moreInlineText: { color: colors.accent, fontSize: 11, fontWeight: '900' },
  modalOverlay: { backgroundColor: 'rgba(0,0,0,0.72)', flex: 1, justifyContent: 'flex-end' },
  modalSheet: { backgroundColor: colors.surface, borderTopLeftRadius: 26, borderTopRightRadius: 26, maxHeight: '92%', padding: 18 },
  modalHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 18 },
  modalTitle: { color: colors.text, fontSize: 22, fontWeight: '900' },
  close: { color: colors.muted, fontWeight: '900' },
  twoColumns: { flexDirection: 'row', gap: 10 },
  field: { flex: 1, marginBottom: 12 },
  label: { color: colors.muted, fontSize: 12, fontWeight: '800', marginBottom: 6 },
  input: { backgroundColor: colors.background, borderColor: colors.surfaceRaised, borderRadius: 16, borderWidth: 1, color: colors.text, minHeight: 52, paddingHorizontal: 14 },
  groupTitle: { color: colors.text, fontSize: 16, fontWeight: '900', marginBottom: 10, marginTop: 12 },
  collapseHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginTop: 12 },
  collapseText: { color: colors.accent, fontSize: 12, fontWeight: '900' },
  primaryButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, justifyContent: 'center', minHeight: 52 },
  primaryButtonText: { color: colors.text, fontWeight: '900' },
  dangerTitle: { color: colors.danger, fontSize: 14, fontWeight: '900', marginBottom: 10, marginTop: 20, textTransform: 'uppercase' },
  dangerButton: { borderColor: colors.danger, borderRadius: 16, borderWidth: 1, marginBottom: 10, padding: 14 },
  dangerText: { color: colors.danger, fontWeight: '900' },
  logoutButton: { backgroundColor: colors.background, borderRadius: 16, padding: 14 },
  logoutButtonDisabled: { opacity: 0.75 },
  logoutLoadingRow: { alignItems: 'center', flexDirection: 'row', gap: 8, justifyContent: 'center' },
  logoutText: { color: colors.text, fontWeight: '900', textAlign: 'center' },
  reviewCard: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 18, borderWidth: 1, flexDirection: 'row', gap: 12, padding: 12, width: 280 },
  reviewPoster: { borderRadius: 12, height: 96, width: 68 },
  reviewCopy: { flex: 1, justifyContent: 'center' },
  reviewTitleItem: { color: colors.text, fontSize: 15, fontWeight: '900' },
  reviewMeta: { color: colors.muted, fontSize: 11, fontWeight: '800', marginTop: 4 },
  reviewStars: { color: '#f6c45f', fontSize: 12, fontWeight: '900', marginTop: 8 },
  reviewComment: { color: colors.text, fontSize: 12, lineHeight: 18, marginTop: 6 },
});
