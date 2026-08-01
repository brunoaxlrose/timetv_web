import { useEffect, useState } from 'react';
import { FlatList, Image, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { EventoCalendario, getDashboard } from '../api/mobile';
import { PosterCard } from '../components/PosterCard';
import { PosterSkeletonRow, Skeleton } from '../components/Skeleton';
import { colors } from '../theme/colors';
import { Item } from '../types';

type Dados = NonNullable<Awaited<ReturnType<typeof getDashboard>>['data']>;

export function DashboardScreen({ onOpenItem, onOpenDiscovery, refreshKey = 0 }: { onOpenItem: (item: Item) => void; onOpenDiscovery: (section: 'populares' | 'em_breve' | 'em_curso') => void; refreshKey?: number }) {
  const [data, setData] = useState<Dados | null>(null);
  const [aba, setAba] = useState<'continuar' | 'proximos'>('continuar');
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [courseOpen, setCourseOpen] = useState(true);
  const [loadError, setLoadError] = useState('');

  async function load(refresh = false) {
    if (refresh) setRefreshing(true);
    try {
      const response = await getDashboard(1);
      if (response.data) {
        setData(response.data);
        setLoadError('');
      }
    } catch (error) {
      if (!data) setLoadError(error instanceof Error ? error.message : 'Nao foi possivel carregar o inicio.');
    } finally { setLoading(false); setRefreshing(false); }
  }

  useEffect(() => { load(); }, [refreshKey]);

  return <ScrollView style={styles.screen} contentContainerStyle={styles.content} refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load(true)} tintColor={colors.accent} />}>
    {loading ? <><Skeleton height={98} /><PosterSkeletonRow /><PosterSkeletonRow /></> : loadError && !data ? <Empty text={loadError} /> : <>
      <View style={styles.sectionHeader}>
        <Pressable onPress={() => setCourseOpen((value) => !value)} style={styles.courseTitle}><Text style={styles.sectionTitle}>Em curso</Text><Text style={styles.count}>{data?.continuar_assistindo.length || 0}</Text><Text style={styles.chevron}>{courseOpen ? '⌃' : '⌄'}</Text></Pressable>
        <Pressable onPress={() => onOpenDiscovery('em_curso')} hitSlop={12}><Text style={styles.sectionArrow}>›</Text></Pressable>
      </View>
      {courseOpen ? <>
        <View style={styles.switcher}>
          <Pressable onPress={() => setAba('continuar')} style={[styles.switchButton, aba === 'continuar' && styles.switchActive]}><Text style={[styles.switchText, aba === 'continuar' && styles.switchTextActive]}>Continuar</Text></Pressable>
          <Pressable onPress={() => setAba('proximos')} style={[styles.switchButton, aba === 'proximos' && styles.switchActive]}><Text style={[styles.switchText, aba === 'proximos' && styles.switchTextActive]}>Próximos</Text></Pressable>
        </View>
        {aba === 'continuar' ? <FlatList horizontal data={data?.continuar_assistindo || []} keyExtractor={(row) => String(row.next_episode.id_episodio)} renderItem={({ item: row }) => <ContinueCard row={row} onPress={() => onOpenItem(row.item)} />} showsHorizontalScrollIndicator={false} /> : <FlatList horizontal data={(data?.proximos || []).slice(0, 10)} keyExtractor={(evento, index) => `${evento.id_item}-${evento.id_episodio || index}`} renderItem={({ item: evento }) => <NextCard evento={evento} onPress={() => onOpenItem(evento as unknown as Item)} />} showsHorizontalScrollIndicator={false} />}
        {aba === 'continuar' && !data?.continuar_assistindo.length ? <Empty text="Nada para continuar por enquanto." /> : null}
        {aba === 'proximos' && !data?.proximos.length ? <Empty text="Nenhum próximo lançamento cadastrado." /> : null}
      </> : null}

      <SectionHeader title="Populares" onPress={() => onOpenDiscovery('populares')} />
      <FlatList horizontal data={(data?.populares || []).slice(0, 10)} keyExtractor={(item, index) => `popular-${item.tmdb_id || index}`} renderItem={({ item }) => <View style={styles.posterCard}><PosterCard item={item} onPress={onOpenItem} /></View>} showsHorizontalScrollIndicator={false} />

      <SectionHeader title="Em breve" onPress={() => onOpenDiscovery('em_breve')} />
      <FlatList horizontal data={(data?.em_breve || []).slice(0, 10)} keyExtractor={(item, index) => `breve-${item.tmdb_id || index}`} renderItem={({ item }) => <View style={styles.posterCard}><PosterCard item={item} onPress={onOpenItem} /></View>} showsHorizontalScrollIndicator={false} />
    </>}
  </ScrollView>;
}

function SectionHeader({ title, onPress }: { title: string; onPress: () => void }) { return <View style={styles.sectionHeader}><Text style={styles.sectionTitle}>{title}</Text><Pressable onPress={onPress} hitSlop={12}><Text style={styles.sectionArrow}>›</Text></Pressable></View>; }

function ContinueCard({ row, onPress }: { row: Dados['continuar_assistindo'][number]; onPress: () => void }) {
  const total = row.progress?.total_count || row.item.progress?.total_count || 0;
  const watched = row.progress?.watched_count || row.item.progress?.watched_count || 0;
  const percent = total > 0 ? Math.min(100, watched / total * 100) : row.item.progress_percent || 0;
  const remaining = Math.max(0, total - watched);
  return <Pressable onPress={onPress} style={styles.trackingCard}>{row.item.url_poster ? <Image source={{ uri: row.item.url_poster }} style={styles.trackingPoster} /> : <View style={styles.trackingPoster} />}<View style={styles.trackingCopy}><Text numberOfLines={1} style={styles.trackingTitle}>{row.item.titulo}</Text><Text style={styles.episode}>T{String(row.next_episode.numero_temporada).padStart(2, '0')} · E{String(row.next_episode.numero_episodio).padStart(2, '0')}</Text><Text numberOfLines={1} style={styles.episodeTitle}>{row.next_episode.titulo}</Text><View style={styles.progressTrack}><View style={[styles.progressFill, { width: `${percent}%` }]} /></View><Text style={styles.progressMeta}>{watched}/{total || '?'} episódios{total ? ` · Restam ${remaining}` : ''}</Text></View><Text style={styles.check}>✓</Text></Pressable>;
}

function NextCard({ evento, onPress }: { evento: EventoCalendario; onPress: () => void }) { return <Pressable onPress={onPress} style={styles.trackingCard}>{evento.url_poster ? <Image source={{ uri: evento.url_poster }} style={styles.trackingPoster} /> : <View style={styles.trackingPoster} />}<View style={styles.trackingCopy}><Text numberOfLines={1} style={styles.trackingTitle}>{evento.titulo}</Text><Text style={styles.episode}>{evento.numero_temporada != null ? `T${evento.numero_temporada} · E${evento.numero_episodio}` : 'ESTREIA'}</Text><Text numberOfLines={1} style={styles.episodeTitle}>{evento.titulo_episodio || 'Novo lançamento'}</Text><Text style={styles.date}>{new Date(`${evento.data_evento}T12:00:00`).toLocaleDateString('pt-BR', { weekday: 'short', day: '2-digit', month: 'short' })}</Text></View></Pressable>; }
function Empty({ text }: { text: string }) { return <View style={styles.empty}><Text style={styles.emptyText}>{text}</Text></View>; }

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1 }, content: { padding: 16, paddingBottom: 115 },
  switcher: { backgroundColor: colors.surface, borderRadius: 15, flexDirection: 'row', marginBottom: 14, padding: 4 }, switchButton: { alignItems: 'center', borderRadius: 11, flex: 1, paddingVertical: 11 }, switchActive: { backgroundColor: colors.surfaceRaised }, switchText: { color: colors.muted, fontWeight: '800' }, switchTextActive: { color: colors.text },
  sectionHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 12, marginTop: 20 }, sectionTitle: { color: colors.accent, fontSize: 14, fontWeight: '900', letterSpacing: 1.4, textTransform: 'uppercase' }, sectionArrow: { color: colors.accent, fontSize: 34, lineHeight: 34 }, count: { color: colors.accent, fontWeight: '900' }, courseTitle: { alignItems: 'center', flexDirection: 'row', gap: 10 }, courseMeta: { alignItems: 'center', flexDirection: 'row', gap: 10 }, chevron: { color: colors.accent, fontSize: 22 },
  trackingCard: { backgroundColor: colors.surface, borderLeftColor: colors.accent, borderLeftWidth: 4, borderRadius: 15, flexDirection: 'row', marginBottom: 8, marginRight: 12, minHeight: 126, padding: 10, width: 310 }, trackingPoster: { backgroundColor: colors.surface, borderRadius: 9, height: 106, width: 74 }, trackingCopy: { flex: 1, paddingHorizontal: 12 }, trackingTitle: { color: colors.text, fontSize: 17, fontWeight: '900' }, episode: { color: colors.accent, fontWeight: '800', marginTop: 4 }, episodeTitle: { color: colors.text, marginTop: 5 }, progressTrack: { backgroundColor: colors.surfaceRaised, borderRadius: 4, height: 5, marginTop: 13, overflow: 'hidden' }, progressFill: { backgroundColor: colors.accent, height: '100%' }, progressMeta: { color: colors.muted, fontSize: 11, marginTop: 5, textAlign: 'right' }, date: { color: colors.accent, fontSize: 12, marginTop: 12 }, check: { alignSelf: 'flex-end', color: colors.accent, fontSize: 25 },
  posterCard: { marginRight: 12 }, empty: { backgroundColor: colors.surface, borderRadius: 14, padding: 20 }, emptyText: { color: colors.muted, textAlign: 'center' },
});
