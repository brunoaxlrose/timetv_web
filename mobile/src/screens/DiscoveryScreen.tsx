import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { getDashboard } from '../api/mobile';
import { PosterCard } from '../components/PosterCard';
import { colors } from '../theme/colors';
import { Episode, Item } from '../types';

type Section = 'populares' | 'em_breve' | 'em_curso' | 'top_10_filmes' | 'top_10_series';
type CourseRow = { item: Item; next_episode: Episode; progress?: { total_count: number; watched_count: number } };

const DISCOVERY_CACHE_TTL = 60_000;
const discoveryCache = new Map<Section, { items: Item[]; courseRows: CourseRow[]; pagina: number; at: number }>();

export function DiscoveryScreen({ section, onBack, onOpenItem }: { section: Section; onBack: () => void; onOpenItem: (item: Item) => void }) {
  const [items, setItems] = useState<Item[]>(() => discoveryCache.get(section)?.items || []);
  const [pagina, setPagina] = useState(() => discoveryCache.get(section)?.pagina || 0);
  const [courseRows, setCourseRows] = useState<CourseRow[]>(() => discoveryCache.get(section)?.courseRows || []);
  const [loading, setLoading] = useState(() => !discoveryCache.get(section));

  const title = section === 'populares'
    ? 'Tendencias'
    : section === 'em_breve'
      ? 'Em breve'
      : section === 'top_10_filmes'
        ? 'Top 10 Filmes'
        : section === 'top_10_series'
          ? 'Top 10 Series'
          : 'Em curso';

  async function loadMore() {
    if (loading) return;
    setLoading(true);
    try {
      const nextPage = pagina + 1;
      const response = await getDashboard(nextPage);
      const novos = section === 'em_curso'
        ? (response.data?.continuar_assistindo || []).map((row) => row.item)
        : response.data?.[section] || [];
      const nextCourseRows = section === 'em_curso' ? (response.data?.continuar_assistindo || []) : courseRows;
      const nextItems = section === 'em_curso' || section === 'top_10_filmes' || section === 'top_10_series'
        ? novos
        : juntar(items, novos);

      if (section === 'em_curso') setCourseRows(nextCourseRows);
      setItems(nextItems);
      setPagina(nextPage);
      discoveryCache.set(section, { items: nextItems, courseRows: nextCourseRows, pagina: nextPage, at: Date.now() });
    } catch {
      // Keep already loaded data visible while connectivity is unavailable.
    } finally {
      setLoading(false);
    }
  }

  useEffect(() => {
    const cached = discoveryCache.get(section);
    if (cached && Date.now() - cached.at < DISCOVERY_CACHE_TTL) {
      setItems(cached.items);
      setCourseRows(cached.courseRows);
      setPagina(cached.pagina);
      setLoading(false);
      return;
    }
    setItems([]);
    setCourseRows([]);
    setPagina(0);
    void loadMore();
  }, [section]);

  return (
    <View style={styles.screen}>
      <View style={styles.header}>
        <Pressable onPress={onBack} style={styles.backButton}>
          <Text style={styles.back}>‹</Text>
        </Pressable>
        <Text style={styles.icon}>{section === 'populares' ? '●' : section.startsWith('top_10') ? '#' : '✦'}</Text>
        <Text style={styles.title}>{title}</Text>
      </View>

      {section === 'em_curso' ? (
        <FlatList
          data={courseRows}
          keyExtractor={(row, index) => String(row.item.id_item || index)}
          contentContainerStyle={styles.courseList}
          renderItem={({ item: row }) => <CourseCard row={row} onPress={() => onOpenItem(row.item)} />}
          ListFooterComponent={loading ? <ActivityIndicator color={colors.accent} style={styles.loader} /> : null}
        />
      ) : (
        <FlatList
          data={items}
          numColumns={3}
          keyExtractor={(item, index) => `${section}-${item.tmdb_id || item.id_item || index}`}
          columnWrapperStyle={styles.row}
          contentContainerStyle={styles.list}
          renderItem={({ item, index }) => (
            <View style={styles.card}>
              {section === 'top_10_filmes' || section === 'top_10_series' ? (
                <TopTenCard item={item} index={index} onPress={onOpenItem} />
              ) : (
                <PosterCard item={item} onPress={onOpenItem} />
              )}
            </View>
          )}
          onEndReached={section === 'top_10_filmes' || section === 'top_10_series' ? undefined : loadMore}
          onEndReachedThreshold={0.7}
          ListFooterComponent={loading ? <ActivityIndicator color={colors.accent} style={styles.loader} /> : null}
        />
      )}
    </View>
  );
}

function TopTenCard({ item, index, onPress }: { item: Item; index: number; onPress: (item: Item) => void }) {
  return (
    <View style={styles.topTenWrap}>
      <Text style={styles.topTenIndex}>{index + 1}</Text>
      <View style={styles.topTenPosterWrap}>
        <PosterCard item={item} onPress={onPress} />
      </View>
    </View>
  );
}

function CourseCard({ row, onPress }: { row: CourseRow; onPress: () => void }) {
  const total = Number(row.progress?.total_count || row.item.progress?.total_count || row.item.total_episodios || 0);
  const watched = Number(row.progress?.watched_count || row.item.progress?.watched_count || 0);
  const percent = total ? Math.min(100, watched / total * 100) : Number(row.item.progress_percent || 0);

  return (
    <Pressable onPress={onPress} style={styles.courseCard}>
      {row.item.url_poster ? <Image source={{ uri: row.item.url_poster }} style={styles.coursePoster} /> : <View style={styles.coursePoster} />}
      <View style={styles.courseCopy}>
        <Text numberOfLines={1} style={styles.courseTitle}>{row.item.titulo}</Text>
        <View style={styles.courseMetaRow}>
          <Text style={styles.courseEpisode}>T{String(row.next_episode.numero_temporada).padStart(2, '0')} · E{String(row.next_episode.numero_episodio).padStart(2, '0')}</Text>
          <ProviderBadges value={row.item.provedores_streaming} />
        </View>
        <Text numberOfLines={1} style={styles.courseEpisodeTitle}>{row.next_episode.titulo}</Text>
        <View style={styles.progressTrack}>
          <View style={[styles.progressFill, { width: `${percent}%` }]} />
        </View>
        <Text style={styles.progressText}>{watched}/{total || '?'} episodios · Restam {Math.max(0, total - watched)}</Text>
      </View>
      <Text style={styles.courseCheck}>✓</Text>
    </Pressable>
  );
}

function ProviderBadges({ value }: { value?: string }) {
  let providers: Array<{ name?: string; logo?: string }> = [];
  try {
    providers = value ? JSON.parse(value) : [];
  } catch {
    providers = [];
  }
  if (!providers.length) return <Text style={styles.noProvider}>Streaming nao informado</Text>;
  return (
    <View style={styles.providerRow}>
      {providers.slice(0, 3).map((provider, index) => provider.logo ? (
        <Image key={`${provider.name || 'provider'}-${index}`} source={{ uri: provider.logo }} style={styles.providerLogo} />
      ) : (
        <Text key={`${provider.name || 'provider'}-${index}`} numberOfLines={1} style={styles.providerName}>{provider.name}</Text>
      ))}
    </View>
  );
}

function juntar(current: Item[], next: Item[]) {
  const ids = new Set(current.map((item) => `${item.tmdb_id || item.id_item}-${item.tipo}`));
  return [...current, ...next.filter((item) => !ids.has(`${item.tmdb_id || item.id_item}-${item.tipo}`))];
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1 },
  header: { alignItems: 'center', flexDirection: 'row', paddingHorizontal: 16, paddingVertical: 18 },
  backButton: { marginRight: 14 },
  back: { color: colors.text, fontSize: 40, lineHeight: 40 },
  icon: { color: colors.accent, fontSize: 18, marginRight: 9 },
  title: { color: colors.text, fontSize: 22, fontWeight: '900' },
  list: { paddingBottom: 115, paddingHorizontal: 10 },
  row: { alignItems: 'flex-start' },
  card: { paddingHorizontal: 6, width: '33.333%' },
  loader: { marginVertical: 24 },
  topTenWrap: { minHeight: 188, position: 'relative' },
  topTenIndex: { color: colors.accent, fontSize: 60, fontWeight: '900', left: -2, opacity: 0.28, position: 'absolute', top: 72, zIndex: 1 },
  topTenPosterWrap: { marginLeft: 24, zIndex: 2 },
  courseList: { gap: 10, paddingBottom: 115, paddingHorizontal: 16 },
  courseCard: { backgroundColor: colors.surface, borderLeftColor: colors.accent, borderLeftWidth: 4, borderRadius: 15, flexDirection: 'row', minHeight: 136, padding: 10 },
  coursePoster: { backgroundColor: colors.surface, borderRadius: 9, height: 116, width: 80 },
  courseCopy: { flex: 1, paddingHorizontal: 12 },
  courseTitle: { color: colors.text, fontSize: 17, fontWeight: '900' },
  courseMetaRow: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  courseEpisode: { color: colors.accent, fontWeight: '900', marginTop: 4 },
  courseEpisodeTitle: { color: colors.text, marginTop: 5 },
  progressTrack: { backgroundColor: colors.surfaceRaised, borderRadius: 4, height: 5, marginTop: 14, overflow: 'hidden' },
  progressFill: { backgroundColor: colors.accent, height: '100%' },
  progressText: { color: colors.muted, fontSize: 11, marginTop: 6 },
  courseCheck: { alignSelf: 'flex-end', color: colors.accent, fontSize: 25 },
  providerRow: { flexDirection: 'row', gap: 4 },
  providerLogo: { borderRadius: 5, height: 22, width: 22 },
  providerName: { color: colors.text, fontSize: 9, maxWidth: 62 },
  noProvider: { color: colors.muted, fontSize: 9, marginLeft: 8 },
});
