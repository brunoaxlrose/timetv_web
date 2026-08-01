import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Image, Modal, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { reloadAppAsync } from 'expo';
import * as ImageManipulator from 'expo-image-manipulator';
import * as ImagePicker from 'expo-image-picker';
import { clearLibrary, deleteAccount, logout, updateProfile, User } from '../api/auth';
import { getProfile } from '../api/mobile';
import { ConfirmModal } from '../components/ConfirmModal';
import { Marquee } from '../components/Marquee';
import { PosterCard } from '../components/PosterCard';
import { PosterSkeletonRow, Skeleton } from '../components/Skeleton';
import { useToast } from '../components/Toast';
import { saveUser } from '../storage/session';
import { alpha, colors, getActivePaletteKey, PaletteKey, palettes, savePalette } from '../theme/colors';
import { Item } from '../types';

export function ProfileScreen({
  user,
  onOpenItem,
  onLogout,
  onUserUpdated,
  refreshKey = 0,
}: {
  user: User;
  onOpenItem: (item: Item) => void;
  onLogout: () => void;
  onUserUpdated: (user: User) => void;
  refreshKey?: number;
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
  }, [refreshKey]);

  const initials = `${user.nome?.[0] || ''}${user.sobrenome?.[0] || ''}` || 'TV';
  const activityItems = groupActivity(profile?.history || []);
  const visibleActivity = showAllActivity ? activityItems : activityItems.slice(0, 10);
  const reviews = profile?.reviews || [];

  return (
    <ScrollView style={styles.screen}>
      <View style={styles.header}>
        <View style={styles.avatar}>{user.url_avatar ? <Image source={{ uri: user.url_avatar }} style={styles.avatarImage} /> : <Text style={styles.avatarText}>{initials}</Text>}</View>
        <View style={{ flex: 1 }}>
          <Text style={styles.name}>{user.nome} {user.sobrenome}</Text>
          <Text style={styles.username}>@{user.nome_usuario}</Text>
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
            <Stat label="Avaliações" value={profile?.stats.evaluationCount || 0} />
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
          {reviews.length ? <Marquee
            data={reviews}
            keyExtractor={(item, index) => String(item.id_item || index)}
            renderItem={(item) => <ReviewCard comment={item.comentario} posterUrl={item.url_poster} rating={Number(item.nota || 0)} title={item.titulo} type={item.tipo} year={item.ano_lancamento} onPress={onOpenItem} item={item as unknown as Item} />}
          /> : <EmptyCard title="Sem avaliações ainda." body="Quando avaliares um filme, série ou anime, ele aparece aqui." />}

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
            keyExtractor={(item, index) => String(item.id_item || `${item.titulo}-${index}`)}
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
  type: Item['tipo'];
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
  const [username, setUsername] = useState(user.nome_usuario);
  const [avatarUrl, setAvatarUrl] = useState(user.url_avatar || '');
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [saving, setSaving] = useState(false);
  const [loggingOut, setLoggingOut] = useState(false);
  const [settingsSection, setSettingsSection] = useState<'menu' | 'profile' | 'security' | 'data'>('menu');
  const [selectedPalette, setSelectedPalette] = useState<PaletteKey>(getActivePaletteKey());
  const [changingPalette, setChangingPalette] = useState<PaletteKey | null>(null);
  const [confirmAction, setConfirmAction] = useState<'clear' | 'delete' | null>(null);
  const { showToast } = useToast();

  useEffect(() => {
    if (!visible) return;
    setNome(user.nome);
    setSobrenome(user.sobrenome);
    setUsername(user.nome_usuario);
    setAvatarUrl(user.url_avatar || '');
    setSelectedPalette(getActivePaletteKey());
  }, [user, visible]);

  async function pickAvatar() {
    const permission = await ImagePicker.requestMediaLibraryPermissionsAsync();
    if (!permission.granted) {
      showToast('Permita o acesso às fotos para escolher um avatar.', 'error');
      return;
    }

    const result = await ImagePicker.launchImageLibraryAsync({
      allowsEditing: true,
      aspect: [1, 1],
      mediaTypes: ['images'],
      quality: 0.8,
    });
    if (result.canceled || !result.assets[0]) return;

    const resized = await ImageManipulator.manipulateAsync(
      result.assets[0].uri,
      [{ resize: { width: 512, height: 512 } }],
      { base64: true, compress: 0.68, format: ImageManipulator.SaveFormat.JPEG },
    );
    if (!resized.base64) {
      showToast('Não foi possível processar esta imagem.', 'error');
      return;
    }
    setAvatarUrl(`data:image/jpeg;base64,${resized.base64}`);
  }

  async function changePalette(key: PaletteKey) {
    if (key === selectedPalette || changingPalette) return;
    setChangingPalette(key);
    try {
      await savePalette(key);
      setSelectedPalette(key);
      await saveUser(user);
      await reloadAppAsync('Paleta do CineFio alterada');
    } catch {
      setChangingPalette(null);
      showToast('Não foi possível aplicar a paleta.', 'error');
    }
  }

  async function saveProfile() {
    if (saving) return;
    setSaving(true);
    try {
      const response = await updateProfile({
        nome_usuario: username,
        nome,
        sobrenome,
        url_avatar: avatarUrl,
        senha_atual: currentPassword,
        nova_senha: newPassword,
        confirmacao_nova_senha: confirmPassword,
      });
      if (response.data) {
        await saveUser(response.data);
        onUserUpdated(response.data);
      }
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      showToast('Perfil atualizado.', 'success');
      setSettingsSection('menu');
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

  function closeSettings() {
    setSettingsSection('menu');
    onClose();
  }

  return (
    <Modal visible={visible} animationType="slide" transparent onRequestClose={closeSettings}>
      <View style={styles.modalOverlay}>
        <ScrollView style={styles.modalSheet} contentContainerStyle={{ paddingBottom: 28 }}>
          <View style={styles.modalHeader}>
            {settingsSection !== 'menu' ? <Pressable onPress={() => setSettingsSection('menu')} style={styles.settingsBack}><Text style={styles.settingsBackText}>‹</Text></Pressable> : null}
            <Text style={styles.modalTitle}>{settingsSection === 'menu' ? 'Configurações' : settingsSection === 'profile' ? 'Editar perfil' : settingsSection === 'security' ? 'Segurança' : 'Dados e privacidade'}</Text>
            <Pressable onPress={closeSettings}><Text style={styles.close}>Fechar</Text></Pressable>
          </View>

          {settingsSection === 'menu' ? <>
            <View style={styles.settingsIdentity}><View style={styles.settingsAvatar}>{avatarUrl ? <Image source={{ uri: avatarUrl }} style={styles.settingsAvatarImage} /> : <Text style={styles.settingsAvatarText}>{`${nome[0] || ''}${sobrenome[0] || ''}`}</Text>}</View><View style={styles.settingsIdentityCopy}><Text style={styles.settingsName}>{nome} {sobrenome}</Text><Text style={styles.settingsUsername}>@{username}</Text></View><View style={styles.brandPill}><Text style={styles.brandPillText}>CineFio</Text></View></View>

            <Text style={styles.settingsSectionLabel}>CONTA</Text>
            <View style={styles.settingsGroup}>
              <SettingsRow icon="P" title="Editar perfil" subtitle="Foto, email e informações pessoais" onPress={() => setSettingsSection('profile')} />
              <View style={styles.settingsDivider} />
              <SettingsRow icon="S" title="Segurança" subtitle="Atualize a senha da sua conta" onPress={() => setSettingsSection('security')} />
            </View>

            <Text style={styles.settingsSectionLabel}>APARÊNCIA</Text>
            <View style={styles.paletteList}>{palettes.map((palette) => {
              const active = palette.key === selectedPalette;
              return <Pressable key={palette.key} disabled={!!changingPalette} onPress={() => changePalette(palette.key)} style={[styles.paletteCard, active && styles.paletteCardActive]}><View style={styles.paletteCopy}><View style={styles.paletteTitleRow}><Text style={styles.paletteTitle}>{palette.name}</Text>{active ? <Text style={styles.paletteActiveLabel}>ATIVA</Text> : null}</View><Text style={styles.paletteSubtitle}>{palette.description}</Text><View style={styles.paletteSwatches}>{palette.swatches.map((color) => <View key={color} style={[styles.paletteSwatch, { backgroundColor: color }]} />)}</View></View>{changingPalette === palette.key ? <ActivityIndicator color={colors.accent} /> : <View style={[styles.paletteRadio, active && styles.paletteRadioActive]}>{active ? <View style={styles.paletteRadioDot} /> : null}</View>}</Pressable>;
            })}</View>

            <Text style={styles.settingsSectionLabel}>GERAL</Text>
            <View style={styles.settingsGroup}><SettingsRow icon="D" title="Dados e privacidade" subtitle="Biblioteca, exportação e conta" onPress={() => setSettingsSection('data')} /></View>

            <Text style={styles.settingsSectionLabel}>SESSÃO</Text>
            <Pressable disabled={loggingOut} onPress={doLogout} style={[styles.settingsLogout, loggingOut && styles.logoutButtonDisabled]}>{loggingOut ? <ActivityIndicator color={colors.text} /> : <><Text style={styles.settingsLogoutIcon}>↪</Text><View style={{ flex: 1 }}><Text style={styles.settingsLogoutTitle}>Sair do CineFio</Text><Text style={styles.settingsLogoutSubtitle}>Encerrar sessão neste aparelho</Text></View></>}</Pressable>
          </> : settingsSection === 'profile' ? <>
            <Text style={styles.settingsIntro}>Mantenha as informações que aparecem no seu perfil atualizadas.</Text>
            <View style={styles.avatarEditor}><View style={styles.avatarEditorPreview}>{avatarUrl ? <Image source={{ uri: avatarUrl }} style={styles.avatarEditorImage} /> : <Text style={styles.avatarEditorInitials}>{`${nome[0] || ''}${sobrenome[0] || ''}`}</Text>}</View><View style={styles.avatarEditorCopy}><Text style={styles.avatarEditorTitle}>Foto de perfil</Text><Text style={styles.avatarEditorHint}>JPG ou PNG, recortada automaticamente.</Text><View style={styles.avatarEditorActions}><Pressable onPress={pickAvatar} style={styles.avatarAction}><Text style={styles.avatarActionText}>{avatarUrl ? 'Trocar foto' : 'Escolher foto'}</Text></Pressable>{avatarUrl ? <Pressable onPress={() => setAvatarUrl('')}><Text style={styles.avatarRemoveText}>Remover</Text></Pressable> : null}</View></View></View>
            <View style={styles.formCard}><View style={styles.twoColumns}><Field label="Nome" value={nome} onChangeText={setNome} /><Field label="Sobrenome" value={sobrenome} onChangeText={setSobrenome} /></View><Field label="Nome de usuário" value={username} onChangeText={setUsername} /><Field editable={false} label="Email cadastrado" value={user.email} onChangeText={() => undefined} /></View>
            <Pressable disabled={saving} onPress={saveProfile} style={styles.primaryButton}>{saving ? <ActivityIndicator color={colors.background} /> : <Text style={styles.primaryButtonText}>Salvar alterações</Text>}</Pressable>
          </> : settingsSection === 'security' ? <>
            <Text style={styles.settingsIntro}>Use uma senha forte e diferente das utilizadas em outros serviços.</Text>
            <View style={styles.formCard}>
              <Field label="Senha atual" value={currentPassword} onChangeText={setCurrentPassword} secure />
              <Field label="Nova senha" value={newPassword} onChangeText={setNewPassword} secure />
              <Field label="Confirmar nova senha" value={confirmPassword} onChangeText={setConfirmPassword} secure />
            </View>
            <Pressable disabled={saving || !currentPassword || !newPassword || !confirmPassword} onPress={saveProfile} style={[styles.primaryButton, (!currentPassword || !newPassword || !confirmPassword) && styles.logoutButtonDisabled]}>{saving ? <ActivityIndicator color={colors.background} /> : <Text style={styles.primaryButtonText}>Atualizar senha</Text>}</Pressable>
          </> : <>
            <Text style={styles.settingsIntro}>Controle os dados guardados na sua conta. Estas ações exigem confirmação.</Text>
            <View style={styles.dataInfoCard}><Text style={styles.dataInfoTitle}>A sua biblioteca</Text><Text style={styles.dataInfoText}>Inclui acompanhamentos, episódios assistidos, favoritos, avaliações e listas.</Text></View>
            <Text style={styles.dangerTitle}>ZONA DE RISCO</Text>
            <Pressable onPress={() => setConfirmAction('clear')} style={styles.dangerButton}><View><Text style={styles.dangerText}>Limpar biblioteca</Text><Text style={styles.dangerSubtitle}>Remove o histórico, mas mantém a conta</Text></View><Text style={styles.dangerArrow}>›</Text></Pressable>
            <Pressable onPress={() => setConfirmAction('delete')} style={styles.dangerButton}><View><Text style={styles.dangerText}>Excluir conta</Text><Text style={styles.dangerSubtitle}>Remove permanentemente o seu acesso</Text></View><Text style={styles.dangerArrow}>›</Text></Pressable>
          </>}

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

function SettingsRow({ icon, title, subtitle, onPress }: { icon: string; title: string; subtitle: string; onPress: () => void }) {
  return <Pressable onPress={onPress} style={styles.settingsRow}><View style={styles.settingsMenuIcon}><Text style={styles.settingsMenuIconText}>{icon}</Text></View><View style={styles.settingsRowCopy}><Text style={styles.settingsRowTitle}>{title}</Text><Text style={styles.settingsRowSubtitle}>{subtitle}</Text></View><Text style={styles.settingsChevron}>›</Text></Pressable>;
}

function Field({ label, value, onChangeText, secure, editable = true }: { label: string; value: string; onChangeText: (value: string) => void; secure?: boolean; editable?: boolean }) {
  return (
    <View style={styles.field}>
      <Text style={styles.label}>{label}</Text>
      <TextInput
        editable={editable}
        onChangeText={onChangeText}
        placeholderTextColor={colors.muted}
        secureTextEntry={secure}
        style={[styles.input, !editable && styles.inputDisabled]}
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
  episodesDetail?: string;
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
          <Text style={styles.activityModalName}>{item.titulo}</Text>
          {item.episodesLabel ? <Text style={styles.activityModalMeta}>{item.episodesLabel}</Text> : null}
          {item.episodesDetail ? <Text style={styles.activityModalEpisodes}>{item.episodesDetail}</Text> : null}
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
                  <Pressable key={`${item.titulo}-${index}`} onPress={() => onOpenItem(item)} style={styles.journalRow}>
                    <PosterCard item={item} />
                    <View style={styles.journalCopy}>
                      <Text style={styles.journalName}>{item.titulo}</Text>
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
  const grouped = new Map<string, ActivityItem & { episodes: string[]; watchedTotal: number }>();

  history.forEach((entry) => {
    const id = String(entry.id_item || entry.show_title || Math.random());
    const current = grouped.get(id) || {
      id_item: Number(entry.id_item || 0) || null,
      titulo: String(entry.show_title || ''),
      tipo: String(entry.tipo || 'series'),
      url_poster: String(entry.url_poster || ''),
      ano_lancamento: undefined,
      watchedAt: String(entry.watched_at || ''),
      episodes: [],
      watchedTotal: 0,
    };

    if (entry.media_type === 'episode') {
      current.episodes.push(`T${entry.numero_temporada}E${entry.numero_episodio}`);
      current.watchedTotal = Math.max(current.watchedTotal, Number(entry.total_episodios_assistidos || 0));
    }

    grouped.set(id, current);
  });

  return Array.from(grouped.values()).map((item) => ({
    ...item,
    episodesLabel: item.watchedTotal === 1 ? `${item.episodes[0]} assistido` : item.watchedTotal > 1 ? `${item.watchedTotal} episódios assistidos` : undefined,
    episodesDetail: item.episodes.length > 1 ? `${item.episodes.slice(0, 6).join(' · ')}${item.episodes.length > 6 ? ` · +${item.episodes.length - 6}` : ''}` : undefined,
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
  return item.ano_lancamento ? String(item.ano_lancamento) : 'Atividade';
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
  avatarImage: { borderRadius: 34, height: 68, width: 68 },
  avatarText: { color: colors.text, fontSize: 22, fontWeight: '900' },
  name: { color: colors.text, fontSize: 20, fontWeight: '900' },
  username: { color: colors.muted, marginTop: 4 },
  settingsButton: { alignItems: 'center', backgroundColor: colors.surface, borderRadius: 18, height: 44, justifyContent: 'center', width: 44 },
  settingsIcon: { color: colors.text, fontSize: 20, fontWeight: '900' },
  timeBox: { backgroundColor: colors.surface, borderRadius: 18, marginBottom: 14, padding: 18 },
  timeText: { color: colors.text, fontSize: 28, fontWeight: '900' },
  count: { color: colors.muted, fontSize: 12, marginTop: 4 },
  statsGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 10, marginBottom: 22 },
  stat: { backgroundColor: colors.surface, borderRadius: 16, flexGrow: 1, minWidth: '46%', padding: 14 },
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
  activityModalEpisodes: { color: colors.info, fontSize: 11, marginTop: 6 },
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
  modalOverlay: { backgroundColor: alpha(colors.background, 0.88), flex: 1, justifyContent: 'flex-end' },
  modalSheet: { backgroundColor: colors.background, borderTopLeftRadius: 28, borderTopRightRadius: 28, maxHeight: '94%', padding: 18 },
  modalHeader: { alignItems: 'center', flexDirection: 'row', gap: 12, marginBottom: 22 },
  modalTitle: { color: colors.text, flex: 1, fontSize: 23, fontWeight: '900' },
  close: { color: colors.muted, fontWeight: '900' },
  settingsBack: { alignItems: 'center', backgroundColor: colors.surface, borderRadius: 18, height: 36, justifyContent: 'center', width: 36 },
  settingsBackText: { color: colors.text, fontSize: 30, lineHeight: 32 },
  settingsIdentity: { alignItems: 'center', backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 22, borderWidth: 1, flexDirection: 'row', padding: 15 },
  settingsAvatar: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 24, height: 48, justifyContent: 'center', width: 48 },
  settingsAvatarImage: { borderRadius: 24, height: 48, width: 48 },
  settingsAvatarText: { color: colors.background, fontSize: 15, fontWeight: '900' },
  settingsIdentityCopy: { flex: 1, paddingHorizontal: 12 },
  settingsName: { color: colors.text, fontSize: 16, fontWeight: '900' },
  settingsUsername: { color: colors.muted, fontSize: 12, marginTop: 3 },
  brandPill: { backgroundColor: alpha(colors.accent, 0.16), borderColor: colors.accent, borderRadius: 999, borderWidth: 1, paddingHorizontal: 10, paddingVertical: 6 },
  brandPillText: { color: colors.accent, fontSize: 10, fontWeight: '900' },
  settingsSectionLabel: { color: colors.muted, fontSize: 11, fontWeight: '900', letterSpacing: 1.4, marginBottom: 9, marginLeft: 4, marginTop: 24 },
  settingsGroup: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 20, borderWidth: 1, overflow: 'hidden' },
  settingsRow: { alignItems: 'center', flexDirection: 'row', minHeight: 76, paddingHorizontal: 14, paddingVertical: 12 },
  settingsMenuIcon: { alignItems: 'center', backgroundColor: alpha(colors.accent, 0.16), borderRadius: 13, height: 42, justifyContent: 'center', width: 42 },
  settingsMenuIconText: { color: colors.accent, fontSize: 14, fontWeight: '900' },
  settingsRowCopy: { flex: 1, paddingHorizontal: 12 },
  settingsRowTitle: { color: colors.text, fontSize: 15, fontWeight: '900' },
  settingsRowSubtitle: { color: colors.muted, fontSize: 11, marginTop: 4 },
  settingsChevron: { color: colors.muted, fontSize: 28 },
  settingsDivider: { backgroundColor: colors.surfaceRaised, height: 1, marginLeft: 68 },
  paletteList: { gap: 10 },
  paletteCard: { alignItems: 'center', backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 20, borderWidth: 1, flexDirection: 'row', justifyContent: 'space-between', padding: 16 },
  paletteCardActive: { borderColor: colors.accent, borderWidth: 2 },
  paletteCopy: { flex: 1 },
  paletteTitleRow: { alignItems: 'center', flexDirection: 'row', gap: 8 },
  paletteTitle: { color: colors.text, fontSize: 15, fontWeight: '900' },
  paletteActiveLabel: { backgroundColor: colors.accent, borderRadius: 999, color: colors.background, fontSize: 9, fontWeight: '900', overflow: 'hidden', paddingHorizontal: 7, paddingVertical: 3 },
  paletteSubtitle: { color: colors.muted, fontSize: 11, marginTop: 4 },
  paletteSwatches: { flexDirection: 'row', marginLeft: 5, marginTop: 12 },
  paletteSwatch: { borderColor: colors.background, borderRadius: 9, borderWidth: 2, height: 28, marginLeft: -5, width: 28 },
  paletteRadio: { alignItems: 'center', borderColor: colors.muted, borderRadius: 11, borderWidth: 2, height: 22, justifyContent: 'center', marginLeft: 14, width: 22 },
  paletteRadioActive: { borderColor: colors.accent },
  paletteRadioDot: { backgroundColor: colors.accent, borderRadius: 6, height: 12, width: 12 },
  settingsLogout: { alignItems: 'center', backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 20, borderWidth: 1, flexDirection: 'row', minHeight: 72, padding: 14 },
  settingsLogoutIcon: { color: colors.accent, fontSize: 23, marginRight: 14 },
  settingsLogoutTitle: { color: colors.text, fontSize: 15, fontWeight: '900' },
  settingsLogoutSubtitle: { color: colors.muted, fontSize: 11, marginTop: 4 },
  settingsIntro: { color: colors.muted, lineHeight: 20, marginBottom: 16 },
  formCard: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 20, borderWidth: 1, marginBottom: 14, padding: 14 },
  avatarEditor: { alignItems: 'center', backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 20, borderWidth: 1, flexDirection: 'row', gap: 14, marginBottom: 14, padding: 14 },
  avatarEditorPreview: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 36, height: 72, justifyContent: 'center', width: 72 },
  avatarEditorImage: { borderRadius: 36, height: 72, width: 72 },
  avatarEditorInitials: { color: colors.background, fontSize: 20, fontWeight: '900' },
  avatarEditorCopy: { flex: 1 },
  avatarEditorTitle: { color: colors.text, fontSize: 15, fontWeight: '900' },
  avatarEditorHint: { color: colors.muted, fontSize: 11, marginTop: 3 },
  avatarEditorActions: { alignItems: 'center', flexDirection: 'row', gap: 14, marginTop: 10 },
  avatarAction: { backgroundColor: colors.accent, borderRadius: 999, paddingHorizontal: 13, paddingVertical: 8 },
  avatarActionText: { color: colors.background, fontSize: 11, fontWeight: '900' },
  avatarRemoveText: { color: colors.danger, fontSize: 11, fontWeight: '900' },
  dataInfoCard: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 20, borderWidth: 1, padding: 16 },
  dataInfoTitle: { color: colors.text, fontSize: 16, fontWeight: '900' },
  dataInfoText: { color: colors.muted, fontSize: 12, lineHeight: 18, marginTop: 6 },
  twoColumns: { flexDirection: 'row', gap: 10 },
  field: { flex: 1, marginBottom: 12 },
  label: { color: colors.muted, fontSize: 12, fontWeight: '800', marginBottom: 6 },
  input: { backgroundColor: colors.background, borderColor: colors.surfaceRaised, borderRadius: 16, borderWidth: 1, color: colors.text, minHeight: 52, paddingHorizontal: 14 },
  inputDisabled: { color: colors.muted, opacity: 0.72 },
  groupTitle: { color: colors.text, fontSize: 16, fontWeight: '900', marginBottom: 10, marginTop: 12 },
  collapseHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginTop: 12 },
  collapseText: { color: colors.accent, fontSize: 12, fontWeight: '900' },
  primaryButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, justifyContent: 'center', minHeight: 52 },
  primaryButtonText: { color: colors.background, fontWeight: '900' },
  dangerTitle: { color: colors.danger, fontSize: 14, fontWeight: '900', marginBottom: 10, marginTop: 20, textTransform: 'uppercase' },
  dangerButton: { alignItems: 'center', backgroundColor: colors.surface, borderColor: colors.danger, borderRadius: 18, borderWidth: 1, flexDirection: 'row', justifyContent: 'space-between', marginBottom: 10, padding: 15 },
  dangerText: { color: colors.danger, fontWeight: '900' },
  dangerSubtitle: { color: colors.muted, fontSize: 11, marginTop: 4 },
  dangerArrow: { color: colors.danger, fontSize: 27 },
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
