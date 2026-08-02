import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, RefreshControl, StyleSheet, Text, TextInput, useWindowDimensions, View } from 'react-native';
import { searchCatalog } from '../api/mobile';
import { PosterCard } from '../components/PosterCard';
import { PosterSkeletonRow } from '../components/Skeleton';
import { colors } from '../theme/colors';
import { Item } from '../types';
const SEARCH_CACHE_TTL = 60_000;
type SearchPayload = { items: Item[]; popular: Item[]; recent: string[] };
const searchCache = new Map<string, { data: SearchPayload; at: number }>();

export function SearchScreen({ onOpenItem }: { onOpenItem: (item: Item) => void }) {
  const { width } = useWindowDimensions();
  const [query, setQuery] = useState('');
  const [items, setItems] = useState<Item[]>([]);
  const [popular, setPopular] = useState<Item[]>(() => searchCache.get('')?.data.popular || []);
  const [recent, setRecent] = useState<string[]>([]);
  const [loading, setLoading] = useState(() => !searchCache.get(''));
  const [refreshing, setRefreshing] = useState(false);
  const requestId = useRef(0);

  async function runSearch(value: string, refresh = false) {
    const currentRequest = ++requestId.current;
    const cacheKey = value.trim().toLowerCase();
    const cached = searchCache.get(cacheKey);
    if (!refresh && cached && Date.now() - cached.at < SEARCH_CACHE_TTL) {
      setItems(cached.data.items);
      setPopular(cached.data.popular);
      setRecent(cached.data.recent);
      setLoading(false);
    }
    if (refresh) setRefreshing(true);
    else setLoading(true);
    try {
      const response = await searchCatalog(value);
      if (currentRequest !== requestId.current) return;
      const nextPayload = {
        items: response.data?.items || [],
        popular: response.data?.popular || [],
        recent: response.data?.recent_searches || [],
      };
      searchCache.set(cacheKey, { data: nextPayload, at: Date.now() });
      setItems(nextPayload.items);
      setPopular(nextPayload.popular);
      setRecent(nextPayload.recent);
    } catch {
      // Keep the last result visible; the offline banner explains the connection state.
    } finally {
      if (currentRequest === requestId.current) {
        setLoading(false);
        setRefreshing(false);
      }
    }
  }

  useEffect(() => {
    const timer = setTimeout(() => runSearch(query), query.trim() ? 350 : 0);
    return () => clearTimeout(timer);
  }, [query]);

  const data = query.trim() ? items : popular;
  const columnSpace = 12;
  const availableWidth = width - 32;
  const cardWidth = Math.min(104, Math.floor((availableWidth - columnSpace * 2) / 3));
  const gridWidth = cardWidth * 3 + columnSpace * 2;

  return (
    <View style={styles.screen}>
      <Text style={styles.title}>Pesquisar</Text>
      <TextInput
        value={query}
        onChangeText={(value) => { requestId.current += 1; setQuery(value); }}
        placeholder="Filmes, series, anime..."
        placeholderTextColor={colors.muted}
        style={styles.input}
      />

      {recent.length && !query.trim() ? (
        <View style={styles.recentWrap}>
          <Text style={styles.recentTitle}>Buscas recentes</Text>
          <View style={styles.recentList}>
            {recent.slice(0, 4).map((term) => (
              <Pressable key={term} onPress={() => setQuery(term)} style={styles.recentPill}>
                <Text style={styles.recentText}>{term}</Text>
              </Pressable>
            ))}
          </View>
        </View>
      ) : null}

      {loading ? (
        <View style={{ marginTop: 18 }}>
          <PosterSkeletonRow />
          <PosterSkeletonRow />
        </View>
      ) : (
        <FlatList
          data={data}
          keyExtractor={(item, index) => String(item.id_item || item.tmdb_id || item.tvmaze_id || item.mal_id || index)}
          numColumns={3}
          renderItem={({ item }) => <PosterCard item={item} onPress={onOpenItem} width={cardWidth} />}
          columnWrapperStyle={[styles.gridRow, { width: gridWidth }]}
          contentContainerStyle={styles.gridContent}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => runSearch(query, true)} tintColor={colors.accent} />}
          ListHeaderComponent={!query.trim() ? <Text style={[styles.blockTitle, { width: gridWidth }]}>Populares agora</Text> : null}
          ListEmptyComponent={<Text style={styles.empty}>{query.trim() ? 'Nada encontrado.' : 'Carregando populares...'}</Text>}
        />
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1, padding: 16 },
  title: { color: colors.text, fontSize: 24, fontWeight: '900', marginBottom: 16 },
  input: {
    backgroundColor: colors.surface,
    borderColor: colors.surfaceRaised,
    borderRadius: 16,
    borderWidth: 1,
    color: colors.text,
    fontSize: 15,
    minHeight: 52,
    paddingHorizontal: 16,
  },
  recentWrap: { alignItems: 'center', marginTop: 16 },
  recentTitle: { color: colors.text, fontSize: 14, fontWeight: '900', marginBottom: 10 },
  recentList: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, justifyContent: 'center' },
  recentPill: { backgroundColor: colors.surface, borderRadius: 999, paddingHorizontal: 12, paddingVertical: 9 },
  recentText: { color: colors.muted, fontSize: 12, fontWeight: '900' },
  blockTitle: { color: colors.text, fontSize: 16, fontWeight: '900', marginBottom: 12 },
  empty: { color: colors.muted, marginTop: 18 },
  gridContent: { alignItems: 'center', gap: 16, paddingBottom: 110, paddingTop: 18 },
  gridRow: { justifyContent: 'space-between' },
});
