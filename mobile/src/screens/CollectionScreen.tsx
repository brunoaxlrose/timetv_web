import { useEffect, useState } from 'react';
import { FlatList, Image, Modal, Pressable, RefreshControl, ScrollView, StyleSheet, Text, useWindowDimensions, View } from 'react-native';
import { getCollection } from '../api/mobile';
import { Skeleton } from '../components/Skeleton';
import { alpha, colors } from '../theme/colors';
import { Item } from '../types';

type StatusFilter = '' | 'watching' | 'em_dia' | 'visto' | 'para_ver' | 'em_pausa' | 'abandonado' | 'reassistindo';
type MediaFilter = 'all' | 'movie' | 'series' | 'anime';
type SortFilter = 'last_watched' | 'last_added' | 'last_premiered' | 'progress' | 'my_rating' | 'community_rating';
type ViewMode = 'grid' | 'list';

type Groups = {
  assistindo: Item[];
  up_to_date: Item[];
  concluido: Item[];
  quero_ver: Item[];
  em_pausa: Item[];
  abandonado: Item[];
  reassistindo: Item[];
};

type CollectionSectionData = {
  key: keyof Groups | 'flat';
  status: StatusFilter;
  label: string;
  color: string;
  data: Item[];
};

const statusFilters: Array<{ key: StatusFilter; label: string; color: string }> = [
  { key: 'watching', label: 'A ver', color: '#f6c45f' },
  { key: 'em_dia', label: 'Em dia', color: '#38ef7d' },
  { key: 'visto', label: 'Visto', color: colors.accent },
  { key: 'para_ver', label: 'Para ver', color: '#8fb8ff' },
  { key: 'em_pausa', label: 'Em pausa', color: '#f59e0b' },
  { key: 'abandonado', label: 'Abandonado', color: '#ff5f87' },
  { key: 'reassistindo', label: 'Reassistindo', color: colors.info },
];

const mediaFilters: Array<{ key: MediaFilter; label: string }> = [
  { key: 'all', label: 'Tudo' },
  { key: 'movie', label: 'Filmes' },
  { key: 'series', label: 'Series' },
  { key: 'anime', label: 'Animes' },
];

const sortFilters: Array<{ key: SortFilter; label: string; hint?: string }> = [
  { key: 'last_watched', label: 'Ultimo visto' },
  { key: 'last_added', label: 'Ultimo adicionado' },
  { key: 'last_premiered', label: 'Ultima estreia' },
  { key: 'progress', label: '% Conclusao', hint: 'Series & Anime' },
  { key: 'my_rating', label: 'O teu rating' },
  { key: 'community_rating', label: 'Rating comunidade' },
];

const groupLabels: Array<{ key: keyof Groups; status: StatusFilter; label: string; color: string }> = [
  { key: 'assistindo', status: 'watching', label: 'A ver', color: '#f6c45f' },
  { key: 'up_to_date', status: 'em_dia', label: 'Em dia', color: '#38ef7d' },
  { key: 'concluido', status: 'visto', label: 'Visto', color: colors.accent },
  { key: 'quero_ver', status: 'para_ver', label: 'Para ver', color: '#8fb8ff' },
  { key: 'em_pausa', status: 'em_pausa', label: 'Em pausa', color: '#f59e0b' },
  { key: 'abandonado', status: 'abandonado', label: 'Abandonado', color: '#ff5f87' },
  { key: 'reassistindo', status: 'reassistindo', label: 'Reassistindo', color: colors.info },
];

export function CollectionScreen({ onOpenItem, refreshKey = 0 }: { onOpenItem: (item: Item) => void; refreshKey?: number }) {
  const [items, setItems] = useState<Item[]>([]);
  const [groups, setGroups] = useState<Groups | null>(null);
  const [status, setStatus] = useState<StatusFilter>('');
  const [media, setMedia] = useState<MediaFilter>('all');
  const [sort, setSort] = useState<SortFilter>('last_watched');
  const [grouped, setGrouped] = useState(true);
  const [viewMode, setViewMode] = useState<ViewMode>('grid');
  const [filterOpen, setFilterOpen] = useState(false);
  const [draftStatus, setDraftStatus] = useState<StatusFilter>('');
  const [draftMedia, setDraftMedia] = useState<MediaFilter>('all');
  const [draftSort, setDraftSort] = useState<SortFilter>('last_watched');
  const [draftGrouped, setDraftGrouped] = useState(true);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  async function load(nextStatus = status, nextMedia = media, nextSort = sort, refresh = false) {
    if (refresh) setRefreshing(true);
    else if (!items.length) setLoading(true);

    const types = nextMedia === 'all' ? 'movie,series,anime' : nextMedia;
    try {
      const response = await getCollection(nextStatus, types, nextSort);
      let nextItems = response.data?.items || [];
      if (nextSort === 'progress') {
        nextItems = [...nextItems].sort((a, b) => Number(b.progress_percent || 0) - Number(a.progress_percent || 0));
      }
      if (nextSort === 'my_rating') {
        nextItems = [...nextItems].sort((a, b) => Number(b.nota || 0) - Number(a.nota || 0));
      }
      setItems(nextItems);
      setGroups(response.data?.groups || null);
    } catch {
      // Preserve the last collection while connectivity is unavailable.
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }

  useEffect(() => {
    load();
  }, [refreshKey]);

  function openFilters() {
    setDraftStatus(status);
    setDraftMedia(media);
    setDraftSort(sort);
    setDraftGrouped(grouped);
    setFilterOpen(true);
  }

  function applyFilters() {
    setStatus(draftStatus);
    setMedia(draftMedia);
    setSort(draftSort);
    setGrouped(draftGrouped);
    setFilterOpen(false);
    load(draftStatus, draftMedia, draftSort);
  }

  function resetFilters() {
    setDraftStatus('');
    setDraftMedia('all');
    setDraftSort('last_watched');
    setDraftGrouped(true);
  }

  const totalCount = grouped && groups ? Object.values(groups).reduce((sum: number, rows: Item[]) => sum + rows.length, 0) : items.length;
  const hasActiveFilters = !!status || media !== 'all' || sort !== 'last_watched' || !grouped;
  const visibleSections: CollectionSectionData[] = grouped && groups && !status
    ? groupLabels.map((group) => ({ ...group, data: groups[group.key] || [] })).filter((group) => group.data.length > 0)
    : [{ key: 'flat' as const, status, label: statusFilters.find((item) => item.key === status)?.label || 'Coleção', color: colors.accent, data: items }];

  return (
    <View style={styles.screen}>
      <View style={styles.header}>
        <View style={styles.titleRow}>
          <Text style={styles.title}>Coleção</Text>
          <Text style={styles.count}>({totalCount})</Text>
        </View>
        <View style={styles.headerActions}>
          <Pressable onPress={() => setViewMode('grid')} style={[styles.iconButton, viewMode === 'grid' && styles.iconButtonActive]}><GridIcon active={viewMode === 'grid'} /></Pressable>
          <Pressable onPress={() => setViewMode('list')} style={[styles.iconButton, viewMode === 'list' && styles.iconButtonActive]}><ListIcon active={viewMode === 'list'} /></Pressable>
          <Pressable onPress={openFilters} style={[styles.iconButton, (filterOpen || hasActiveFilters) && styles.iconButtonActive]}><FilterIcon active={filterOpen || hasActiveFilters} /></Pressable>
        </View>
      </View>

      {loading ? (
        <CollectionSkeleton />
      ) : (
        <FlatList
          data={visibleSections}
          keyExtractor={(section) => String(section.key)}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load(status, media, sort, true)} tintColor={colors.accent} />}
          renderItem={({ item: section }) => (
            <CollectionSection
              color={section.color}
              items={section.data}
              label={section.label}
              onOpenItem={onOpenItem}
              viewMode={viewMode}
            />
          )}
          contentContainerStyle={{ paddingBottom: 120, paddingTop: 14 }}
          ListEmptyComponent={<Text style={styles.empty}>Nada nesta coleção ainda.</Text>}
        />
      )}

      <FilterSheet
        grouped={draftGrouped}
        media={draftMedia}
        onApply={applyFilters}
        onClose={() => setFilterOpen(false)}
        onReset={resetFilters}
        onSetGrouped={setDraftGrouped}
        onSetMedia={setDraftMedia}
        onSetSort={setDraftSort}
        onSetStatus={setDraftStatus}
        sort={draftSort}
        status={draftStatus}
        visible={filterOpen}
      />
    </View>
  );
}

function CollectionSkeleton() {
  const { width } = useWindowDimensions();
  const posterWidth = Math.floor((width - 36) * 0.31);
  const sections = [3, 6];

  return (
    <ScrollView contentContainerStyle={styles.collectionSkeletonContent} scrollEnabled={false}>
      {sections.map((itemCount, sectionIndex) => (
        <View key={sectionIndex} style={styles.collectionSkeletonSection}>
          <View style={styles.collectionSkeletonHeader}>
            <View style={styles.collectionSkeletonHeading}>
              <Skeleton height={11} width={11} radius={6} />
              <Skeleton height={20} width={sectionIndex ? 74 : 58} radius={7} />
            </View>
            <Skeleton height={14} width={18} radius={6} />
          </View>
          <View style={styles.collectionSkeletonGrid}>
            {Array.from({ length: itemCount }, (_, index) => (
              <View key={index} style={{ width: posterWidth }}>
                <Skeleton height={Math.round(posterWidth * 1.5)} width={posterWidth} radius={12} />
                <View style={styles.collectionSkeletonText}>
                  <Skeleton height={13} width={index % 2 ? '72%' : '90%'} radius={5} />
                  <Skeleton height={11} width="42%" radius={5} />
                </View>
              </View>
            ))}
          </View>
        </View>
      ))}
    </ScrollView>
  );
}

function CollectionSection({ label, color, items, viewMode, onOpenItem }: { label: string; color: string; items: Item[]; viewMode: ViewMode; onOpenItem: (item: Item) => void }) {
  if (!items.length) return null;

  return (
    <View style={styles.section}>
      <View style={styles.sectionHeader}>
        <View style={styles.sectionTitleRow}>
          <View style={[styles.sectionDot, { backgroundColor: color }]} />
          <Text style={styles.sectionTitle}>{label}</Text>
        </View>
        <Text style={styles.sectionCount}>{items.length}</Text>
      </View>
      {viewMode === 'grid' ? (
        <View style={styles.grid}>
          {items.map((item, index) => (
            <CollectionPoster key={String(item.id_item || item.tmdb_id || item.tvmaze_id || index)} item={item} onPress={() => onOpenItem(item)} />
          ))}
        </View>
      ) : (
        <View style={styles.list}>
          {items.map((item, index) => (
            <CollectionRow key={String(item.id_item || item.tmdb_id || item.tvmaze_id || index)} item={item} onPress={() => onOpenItem(item)} />
          ))}
        </View>
      )}
    </View>
  );
}

function CollectionPoster({ item, onPress }: { item: Item; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={styles.posterCard}>
      <View style={styles.posterWrap}>
        <Image source={{ uri: item.url_poster }} style={styles.poster} />
        <View style={styles.posterProgress}><View style={[styles.posterProgressFill, { width: `${item.progress_percent || 0}%` }]} /></View>
      </View>
      <Text numberOfLines={1} style={styles.posterTitle}>{item.titulo}</Text>
      <Text style={styles.posterYear}>{item.ano_lancamento || ''}</Text>
    </Pressable>
  );
}

function CollectionRow({ item, onPress }: { item: Item; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={styles.row}>
      <Image source={{ uri: item.url_poster }} style={styles.posterMini} />
      <View style={styles.rowCopy}>
        <Text numberOfLines={1} style={styles.rowTitle}>{item.titulo}</Text>
        <Text style={styles.rowMeta}>{labelType(item.tipo)} - {item.progress_percent || 0}%</Text>
        <View style={styles.progressTrack}>
          <View style={[styles.progressFill, { width: `${item.progress_percent || 0}%` }]} />
        </View>
        {item.next_episode ? <Text style={styles.nextText}>Proximo: T{item.next_episode.numero_temporada}E{item.next_episode.numero_episodio}</Text> : null}
      </View>
    </Pressable>
  );
}

function FilterSheet(props: {
  visible: boolean;
  grouped: boolean;
  status: StatusFilter;
  media: MediaFilter;
  sort: SortFilter;
  onSetGrouped: (value: boolean) => void;
  onSetStatus: (value: StatusFilter) => void;
  onSetMedia: (value: MediaFilter) => void;
  onSetSort: (value: SortFilter) => void;
  onApply: () => void;
  onReset: () => void;
  onClose: () => void;
}) {
  return (
    <Modal visible={props.visible} transparent animationType="slide" onRequestClose={props.onClose}>
      <View style={styles.sheetOverlay}>
        <View style={styles.sheet}>
          <View style={styles.dragHandle} />
          <View style={styles.sheetHeader}>
            <Text style={styles.sheetTitle}>Filtrar & Ordenar</Text>
            <View style={styles.sheetHeaderActions}>
              <Pressable onPress={props.onClose} style={styles.sheetCloseButton}><Text style={styles.sheetCloseText}>Fechar</Text></Pressable>
              <Pressable onPress={props.onReset}><Text style={styles.resetText}>Repor tudo</Text></Pressable>
            </View>
          </View>

          <ScrollView showsVerticalScrollIndicator={false} contentContainerStyle={styles.sheetContent}>
          <Text style={styles.sheetGroupTitle}>Visualização</Text>
          <View style={styles.groupedRow}>
            <View style={{ flex: 1 }}>
              <Text style={styles.groupedTitle}>Agrupado por estado</Text>
              <Text style={styles.groupedSubtitle}>Organiza a tua biblioteca em secoes por estado.</Text>
            </View>
            <Pressable onPress={() => props.onSetGrouped(!props.grouped)} style={[styles.switchTrack, props.grouped && styles.switchTrackActive]}>
              <View style={[styles.switchThumb, props.grouped && styles.switchThumbActive]} />
            </Pressable>
          </View>

          <Text style={styles.sheetGroupTitle}>Status</Text>
          <View style={styles.chips}>
            <FilterChip active={props.status === ''} label="Tudo" onPress={() => props.onSetStatus('')} />
            {statusFilters.map((item) => <FilterChip key={item.key} active={props.status === item.key} label={item.label} onPress={() => props.onSetStatus(item.key)} />)}
          </View>

          <Text style={styles.sheetGroupTitle}>Tipo de conteúdo</Text>
          <View style={styles.chips}>
            {mediaFilters.map((item) => <FilterChip key={item.key} active={props.media === item.key} label={item.label} onPress={() => props.onSetMedia(item.key)} />)}
          </View>

          <Text style={styles.sheetGroupTitle}>Ordenação</Text>
          <View style={styles.sortGrid}>{sortFilters.map((item) => (
            <Pressable key={item.key} onPress={() => props.onSetSort(item.key)} style={[styles.sortRow, props.sort === item.key && styles.sortRowActive]}>
              <View style={[styles.radio, props.sort === item.key && styles.radioActive]}>{props.sort === item.key ? <View style={styles.radioDot} /> : null}</View>
              <View style={{ flex: 1 }}><Text style={[styles.sortText, props.sort === item.key && styles.sortTextActive]}>{item.label}</Text>{item.hint ? <Text style={styles.sortHint}>{item.hint}</Text> : null}</View>
            </Pressable>
          ))}</View>

          <Pressable onPress={props.onApply} style={styles.applyButton}>
            <Text style={styles.applyText}>Aplicar filtros</Text>
          </Pressable>
          </ScrollView>
        </View>
      </View>
    </Modal>
  );
}

function FilterChip({ label, active, onPress }: { label: string; active: boolean; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={[styles.chip, active && styles.chipActive]}>
      <Text style={[styles.chipText, active && styles.chipTextActive]}>{label}</Text>
    </Pressable>
  );
}

function GridIcon({ active }: { active: boolean }) {
  const color = active ? colors.accent : colors.muted;
  return <View style={styles.gridIcon}>{[0, 1, 2, 3].map((item) => <View key={item} style={[styles.gridSquare, { backgroundColor: color }]} />)}</View>;
}

function FilterIcon({ active }: { active: boolean }) {
  const color = active ? colors.text : colors.muted;
  const knobColor = active ? colors.accent : colors.surfaceRaised;
  return (
    <View style={styles.filterIcon}>
      <View style={[styles.filterLine, { backgroundColor: color }]} />
      <View style={[styles.filterLine, { backgroundColor: color, top: 10 }]} />
      <View style={[styles.filterLine, { backgroundColor: color, top: 18 }]} />
      <View style={[styles.filterKnob, { backgroundColor: knobColor, left: 5, top: -1 }]} />
      <View style={[styles.filterKnob, { backgroundColor: knobColor, right: 4, top: 7 }]} />
      <View style={[styles.filterKnob, { backgroundColor: knobColor, left: 11, top: 15 }]} />
    </View>
  );
}

function ListIcon({ active }: { active: boolean }) {
  const color = active ? colors.accent : colors.muted;
  return <View style={styles.listIcon}>{[0, 1, 2].map((item) => <View key={item} style={[styles.listLine, { backgroundColor: color, top: 3 + item * 8 }]} />)}</View>;
}

function StatusGlyph({ kind, color }: { kind: StatusFilter; color: string }) {
  if (kind === 'watching') return <View style={[styles.statusCircle, { borderColor: color }]}><View style={[styles.statusPlay, { borderLeftColor: color }]} /></View>;
  if (kind === 'em_dia') return <View style={[styles.statusCircle, { borderColor: color }]}><View style={[styles.statusCheck, { borderColor: color }]} /></View>;
  if (kind === 'visto') return <View style={[styles.statusCircle, { borderColor: color }]}><View style={[styles.statusDoubleCheck, { borderColor: color }]} /></View>;
  if (kind === 'para_ver') return <View style={[styles.bookmarkIcon, { borderColor: color }]} />;
  if (kind === 'em_pausa') return <View style={[styles.statusCircle, { borderColor: color }]}><View style={[styles.pauseBar, { backgroundColor: color, left: 11 }]} /><View style={[styles.pauseBar, { backgroundColor: color, right: 11 }]} /></View>;
  if (kind === 'abandonado') return <View style={[styles.statusCircle, { borderColor: color }]}><View style={[styles.crossLine, { backgroundColor: color }]} /><View style={[styles.crossLine, { backgroundColor: color, transform: [{ rotate: '-45deg' }] }]} /></View>;
  return <View style={[styles.statusCircle, { borderColor: color }]}><View style={[styles.reloadArc, { borderColor: color }]} /></View>;
}

function labelType(type: string) {
  if (type === 'movie') return 'Filme';
  if (type === 'anime') return 'Anime';
  return 'Serie';
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1 },
  header: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', paddingHorizontal: 18, paddingTop: 20 },
  titleRow: { alignItems: 'baseline', flexDirection: 'row', gap: 10 },
  title: { color: colors.text, fontSize: 30, fontWeight: '900' },
  count: { color: colors.muted, fontSize: 18, fontWeight: '900' },
  headerActions: { alignItems: 'center', flexDirection: 'row', gap: 16 },
  iconButton: { alignItems: 'center', borderColor: 'transparent', borderRadius: 12, borderWidth: 1, height: 38, justifyContent: 'center', width: 38 },
  iconButtonActive: { backgroundColor: alpha(colors.accent, 0.18), borderColor: alpha(colors.accent, 0.55) },
  statusRail: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 20, borderWidth: 1, marginHorizontal: 18, marginTop: 14, maxHeight: 56 },
  statusRailContent: { alignItems: 'center', gap: 10, paddingHorizontal: 10, paddingVertical: 8 },
  statusIconButton: { alignItems: 'center', backgroundColor: 'rgba(255,255,255,0.03)', borderColor: 'transparent', borderRadius: 14, borderWidth: 1, height: 38, justifyContent: 'center', opacity: 1, width: 38 },
  statusIconActive: { backgroundColor: alpha(colors.accent, 0.18), borderColor: colors.accent },
  empty: { color: colors.muted, marginTop: 24, paddingHorizontal: 18 },
  collectionSkeletonContent: { paddingBottom: 120 },
  collectionSkeletonSection: { marginTop: 20, paddingHorizontal: 18 },
  collectionSkeletonHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 12 },
  collectionSkeletonHeading: { alignItems: 'center', flexDirection: 'row', gap: 9 },
  collectionSkeletonGrid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  collectionSkeletonText: { gap: 5, paddingTop: 7 },
  section: { marginTop: 20, paddingHorizontal: 18 },
  sectionHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 12 },
  sectionTitleRow: { alignItems: 'center', flexDirection: 'row', gap: 9 },
  sectionDot: { borderRadius: 6, height: 11, width: 11 },
  sectionTitle: { color: colors.text, fontSize: 20, fontWeight: '900' },
  sectionCount: { color: colors.muted, fontSize: 14, fontWeight: '900' },
  grid: { flexDirection: 'row', flexWrap: 'wrap', gap: 12 },
  list: { gap: 12 },
  posterCard: { width: '31%' },
  posterWrap: { aspectRatio: 2 / 3, backgroundColor: colors.surface, borderRadius: 12, overflow: 'hidden' },
  poster: { height: '100%', width: '100%' },
  posterProgress: { backgroundColor: 'rgba(0,0,0,0.35)', bottom: 0, height: 5, left: 0, position: 'absolute', right: 0 },
  posterProgressFill: { backgroundColor: colors.accent, height: 5 },
  posterTitle: { color: colors.text, fontSize: 13, fontWeight: '800', marginTop: 7 },
  posterYear: { color: colors.muted, fontSize: 12, marginTop: 2 },
  row: { alignItems: 'center', backgroundColor: 'rgba(57,68,82,0.9)', borderColor: 'rgba(208,204,195,0.16)', borderRadius: 18, borderWidth: 1, flexDirection: 'row', gap: 12, padding: 12 },
  posterMini: { backgroundColor: colors.surfaceRaised, borderRadius: 10, height: 82, width: 56 },
  rowCopy: { flex: 1 },
  rowTitle: { color: colors.text, fontSize: 16, fontWeight: '900' },
  rowMeta: { color: colors.muted, fontSize: 12, marginTop: 4 },
  progressTrack: { backgroundColor: colors.surfaceRaised, borderRadius: 999, height: 5, marginTop: 10, overflow: 'hidden' },
  progressFill: { backgroundColor: colors.success, height: 5 },
  nextText: { color: colors.info, fontSize: 12, fontWeight: '800', marginTop: 8 },
  sheetOverlay: { backgroundColor: 'rgba(0,0,0,0.72)', flex: 1, justifyContent: 'flex-end' },
  sheet: { backgroundColor: colors.surface, borderColor: 'rgba(208,204,195,0.16)', borderTopLeftRadius: 28, borderTopRightRadius: 28, borderWidth: 1, maxHeight: '90%', paddingHorizontal: 20, paddingTop: 12 },
  sheetContent: { paddingBottom: 28 },
  dragHandle: { alignSelf: 'center', backgroundColor: '#73758a', borderRadius: 999, height: 4, marginBottom: 16, width: 48 },
  sheetHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between' },
  sheetHeaderActions: { alignItems: 'center', flexDirection: 'row', gap: 10 },
  sheetTitle: { color: colors.text, fontSize: 23, fontWeight: '900' },
  sheetCloseButton: { backgroundColor: 'rgba(255,255,255,0.06)', borderRadius: 999, paddingHorizontal: 12, paddingVertical: 8 },
  sheetCloseText: { color: colors.text, fontSize: 12, fontWeight: '900' },
  resetText: { color: colors.accent, fontSize: 13, fontWeight: '900' },
  sheetGroupTitle: { color: '#8f91a8', fontSize: 12, fontWeight: '900', letterSpacing: 1.1, marginTop: 24, textTransform: 'uppercase' },
  groupedRow: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderColor: 'rgba(208,204,195,0.14)', borderRadius: 18, borderWidth: 1, flexDirection: 'row', gap: 14, marginTop: 12, padding: 16 },
  groupedTitle: { color: colors.text, fontSize: 16, fontWeight: '900' },
  groupedSubtitle: { color: '#c0c5ce', fontSize: 13, marginTop: 4 },
  switchTrack: { backgroundColor: '#272f3b', borderRadius: 999, height: 38, justifyContent: 'center', padding: 3, width: 78 },
  switchTrackActive: { backgroundColor: colors.accent },
  switchThumb: { backgroundColor: '#d9dde7', borderRadius: 16, height: 32, width: 32 },
  switchThumbActive: { alignSelf: 'flex-end', backgroundColor: colors.text },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: 9, marginTop: 12 },
  chip: { backgroundColor: colors.surfaceRaised, borderColor: 'rgba(208,204,195,0.18)', borderRadius: 13, borderWidth: 1, paddingHorizontal: 15, paddingVertical: 11 },
  chipActive: { backgroundColor: alpha(colors.accent, 0.22), borderColor: colors.accent },
  chipText: { color: '#c3c8d1', fontSize: 14, fontWeight: '800' },
  chipTextActive: { color: colors.text },
  sortGrid: { gap: 8, marginTop: 12 },
  sortRow: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderColor: 'transparent', borderRadius: 15, borderWidth: 1, flexDirection: 'row', gap: 12, padding: 13 },
  sortRowActive: { backgroundColor: alpha(colors.accent, 0.14), borderColor: alpha(colors.accent, 0.65) },
  radio: { alignItems: 'center', borderColor: '#686a7d', borderRadius: 9, borderWidth: 2, height: 18, justifyContent: 'center', width: 18 },
  radioActive: { borderColor: colors.accent },
  radioDot: { backgroundColor: colors.accent, borderRadius: 4, height: 8, width: 8 },
  sortText: { color: '#d7dbe3', fontSize: 15, fontWeight: '800' },
  sortTextActive: { color: colors.text },
  sortHint: { color: '#8f91a8', fontSize: 11, marginTop: 3 },
  applyButton: { alignItems: 'center', backgroundColor: colors.accent, borderRadius: 16, marginTop: 18, padding: 16 },
  applyText: { color: colors.text, fontSize: 17, fontWeight: '900' },
  gridIcon: { flexDirection: 'row', flexWrap: 'wrap', gap: 3, height: 25, width: 25 },
  gridSquare: { borderRadius: 3, height: 11, width: 11 },
  filterIcon: { height: 24, position: 'relative', width: 26 },
  filterLine: { borderRadius: 2, height: 3, left: 1, position: 'absolute', top: 2, width: 24 },
  filterKnob: { borderColor: colors.background, borderRadius: 5, borderWidth: 1, height: 9, position: 'absolute', width: 9 },
  listIcon: { height: 26, position: 'relative', width: 27 },
  listLine: { borderRadius: 2, height: 4, left: 1, position: 'absolute', width: 25 },
  statusCircle: { alignItems: 'center', borderRadius: 15, borderWidth: 2, height: 30, justifyContent: 'center', width: 30 },
  statusPlay: { borderBottomColor: 'transparent', borderBottomWidth: 7, borderLeftWidth: 10, borderTopColor: 'transparent', borderTopWidth: 7, height: 0, marginLeft: 3, width: 0 },
  statusCheck: { borderBottomWidth: 2, borderLeftWidth: 2, height: 9, transform: [{ rotate: '-45deg' }], width: 16 },
  statusDoubleCheck: { borderBottomWidth: 2, borderLeftWidth: 2, height: 9, transform: [{ rotate: '-45deg' }], width: 18 },
  bookmarkIcon: { borderBottomLeftRadius: 3, borderBottomRightRadius: 3, borderTopLeftRadius: 4, borderTopRightRadius: 4, borderWidth: 2, height: 30, width: 22 },
  pauseBar: { borderRadius: 2, height: 12, position: 'absolute', top: 8, width: 4 },
  crossLine: { borderRadius: 2, height: 3, position: 'absolute', transform: [{ rotate: '45deg' }], width: 17 },
  reloadArc: { borderRadius: 9, borderTopWidth: 2, borderWidth: 2, height: 18, width: 18 },
});
