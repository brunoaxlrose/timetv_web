import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Pressable, StyleSheet, Text, TextInput, useWindowDimensions, View } from 'react-native';
import { searchCatalog } from '../api/mobile';
import { PosterCard } from '../components/PosterCard';
import { PosterSkeletonRow } from '../components/Skeleton';
import { colors } from '../theme/colors';
import { Item } from '../types';

export function SearchScreen({ onOpenItem }: { onOpenItem: (item: Item) => void }) {
  const { width } = useWindowDimensions();
  const [query, setQuery] = useState('');
  const [items, setItems] = useState<Item[]>([]);
  const [popular, setPopular] = useState<Item[]>([]);
  const [recent, setRecent] = useState<string[]>([]);
  const [loading, setLoading] = useState(false);

  async function runSearch(value: string) {
    setLoading(true);
    try {
      const response = await searchCatalog(value);
      setItems(response.data?.items || []);
      setPopular(response.data?.popular || []);
      setRecent(response.data?.recent_searches || []);
    } finally {
      setLoading(false);
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
        onChangeText={setQuery}
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
