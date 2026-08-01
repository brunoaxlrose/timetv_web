import { useEffect, useState } from 'react';
import { FlatList, Image, Pressable, RefreshControl, ScrollView, StyleSheet, Text, View } from 'react-native';
import { getDashboard } from '../api/mobile';
import { PosterCard } from '../components/PosterCard';
import { Section } from '../components/Section';
import { PosterSkeletonRow, Skeleton } from '../components/Skeleton';
import { colors } from '../theme/colors';
import { Item, UserList } from '../types';

type Props = {
  onOpenItem: (item: Item) => void;
  onOpenLists: () => void;
};

export function DashboardScreen({ onOpenItem, onOpenLists }: Props) {
  const [data, setData] = useState<Awaited<ReturnType<typeof getDashboard>>['data'] | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  async function load(refresh = false) {
    if (refresh) setRefreshing(true);
    try {
      const response = await getDashboard();
      setData(response.data);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }

  useEffect(() => {
    load();
  }, []);

  return (
    <ScrollView
      style={styles.screen}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={() => load(true)} tintColor={colors.accent} />}
    >
      {loading ? (
        <>
          <Skeleton height={98} />
          <PosterSkeletonRow />
          <PosterSkeletonRow />
        </>
      ) : (
        <>
          <Section title="Continuar">
            {data?.continue_watching?.length ? (
              <FlatList
                horizontal
                data={data.continue_watching}
                keyExtractor={(row) => String(row.next_episode.id_episodio)}
                renderItem={({ item }) => (
                  <View style={styles.continueCard}>
                    {item.item.poster_url ? <Image source={{ uri: item.item.poster_url }} style={styles.continuePoster} /> : null}
                    <View style={styles.continueCopy}>
                      <Text numberOfLines={1} style={styles.continueTitle}>{item.item.title}</Text>
                      <Text style={styles.continueMeta}>T{item.next_episode.season_number} E{item.next_episode.episode_number}</Text>
                    </View>
                  </View>
                )}
                showsHorizontalScrollIndicator={false}
              />
            ) : (
              <View style={styles.emptyCard}>
                <Text style={styles.emptyTitle}>Nada para continuar</Text>
                <Text style={styles.emptyBody}>Quando começares uma série, anime ou novela, ela aparece aqui para retomar rápido.</Text>
              </View>
            )}
          </Section>

          <Section title="As tuas listas">
            {data?.lists?.length ? (
              <FlatList
                horizontal
                data={data.lists}
                keyExtractor={(list: UserList) => String(list.id_lista)}
                renderItem={({ item }) => <ListCard list={item} onPress={onOpenLists} />}
                ItemSeparatorComponent={() => <View style={{ width: 12 }} />}
                showsHorizontalScrollIndicator={false}
              />
            ) : (
              <View style={styles.emptyCard}>
                <Text style={styles.emptyTitle}>Nenhuma lista ainda.</Text>
                <Text style={styles.emptyBody}>Cria a tua primeira lista para organizar os teus titulos.</Text>
              </View>
            )}
          </Section>

          <Section title="Mais populares">
            {(data?.popular?.length || 0) > 0 ? (
              <FlatList
                horizontal
                data={data?.popular || []}
                keyExtractor={(item, index) => String(item.tmdb_id || item.id_item || index)}
                renderItem={({ item }) => <PosterCard item={item} onPress={onOpenItem} />}
                ItemSeparatorComponent={() => <View style={{ width: 12 }} />}
                showsHorizontalScrollIndicator={false}
              />
            ) : (
              <View style={styles.emptyStrip}>
                <Text style={styles.emptyTitle}>Sem títulos populares no momento.</Text>
                <Text style={styles.emptyBody}>Se o TMDB estiver indisponível, esta secção fica vazia. Podemos ligar um fallback local a seguir.</Text>
              </View>
            )}
          </Section>

          <Section title="Em breve">
            {(data?.upcoming?.length || 0) > 0 ? (
              <FlatList
                horizontal
                data={data?.upcoming || []}
                keyExtractor={(item, index) => String(item.tmdb_id || item.id_item || index)}
                renderItem={({ item }) => <PosterCard item={item} onPress={onOpenItem} />}
                ItemSeparatorComponent={() => <View style={{ width: 12 }} />}
                showsHorizontalScrollIndicator={false}
              />
            ) : (
              <View style={styles.emptyStrip}>
                <Text style={styles.emptyTitle}>Sem estreias em destaque.</Text>
                <Text style={styles.emptyBody}>Isto depende dos dados vindos do TMDB. Se a API responder vazia, mostramos esta mensagem em vez de deixar em branco.</Text>
              </View>
            )}
          </Section>
        </>
      )}
      <View style={{ height: 110 }} />
    </ScrollView>
  );
}

function ListCard({ list, onPress }: { list: UserList; onPress: () => void }) {
  return (
    <Pressable onPress={onPress} style={styles.listCard}>
      {list.cover_poster_url ? <Image source={{ uri: list.cover_poster_url }} style={styles.listCover} /> : <View style={styles.listCover} />}
      <View style={styles.listOverlay}>
        <Text numberOfLines={1} style={styles.listTitle}>{list.nome}</Text>
        <Text style={styles.listCount}>{list.item_count} itens</Text>
      </View>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1, padding: 16 },
  brand: { color: colors.text, fontSize: 24, fontWeight: '900', marginBottom: 6 },
  empty: { color: colors.muted },
  emptyCard: { backgroundColor: colors.surface, borderColor: colors.surfaceRaised, borderRadius: 18, borderWidth: 1, padding: 16 },
  emptyTitle: { color: colors.text, fontSize: 16, fontWeight: '900' },
  emptyBody: { color: colors.muted, marginTop: 6 },
  emptyStrip: {
    backgroundColor: colors.surface,
    borderColor: colors.surfaceRaised,
    borderRadius: 18,
    borderWidth: 1,
    padding: 16,
  },
  continueCard: {
    alignItems: 'center',
    backgroundColor: colors.surface,
    borderColor: colors.surfaceRaised,
    borderRadius: 16,
    borderWidth: 1,
    flexDirection: 'row',
    gap: 12,
    marginRight: 12,
    padding: 10,
    width: 238,
  },
  continuePoster: { borderRadius: 10, height: 72, width: 52 },
  continueCopy: { flex: 1 },
  continueTitle: { color: colors.text, fontWeight: '900' },
  continueMeta: { color: colors.muted, marginTop: 6 },
  listCard: { width: 180 },
  listCover: { backgroundColor: colors.surfaceRaised, borderTopLeftRadius: 18, borderTopRightRadius: 18, height: 110, width: '100%' },
  listOverlay: { backgroundColor: colors.surface, borderBottomLeftRadius: 18, borderBottomRightRadius: 18, borderColor: colors.surfaceRaised, borderLeftWidth: 1, borderRightWidth: 1, borderBottomWidth: 1, padding: 12 },
  listTitle: { color: colors.text, fontSize: 14, fontWeight: '900' },
  listCount: { color: colors.muted, fontSize: 12, marginTop: 4 },
});
