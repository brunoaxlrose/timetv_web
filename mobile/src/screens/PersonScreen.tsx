import { useEffect, useState } from 'react';
import { ActivityIndicator, FlatList, Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { getPersonCredits } from '../api/mobile';
import { PosterCard } from '../components/PosterCard';
import { colors } from '../theme/colors';
import { CastMember, Item, Person } from '../types';

export function PersonScreen({ castMember, onBack, onOpenItem }: { castMember: CastMember; onBack: () => void; onOpenItem: (item: Item) => void }) {
  const [person, setPerson] = useState<Person | null>(null);
  const [credits, setCredits] = useState<Item[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    setLoading(true);
    getPersonCredits(Number(castMember.person_id), castMember.source || 'tmdb').then((response) => {
      setPerson(response.data?.person || null);
      setCredits(response.data?.credits || []);
    }).catch((reason) => setError(reason instanceof Error ? reason.message : 'Não foi possível carregar a filmografia.')).finally(() => setLoading(false));
  }, [castMember.person_id, castMember.source]);

  const header = <View><View style={styles.topBar}><Pressable onPress={onBack} style={styles.backButton}><Text style={styles.back}>‹</Text></Pressable><Text style={styles.topTitle}>Filmografia</Text></View><View style={styles.hero}>{(person?.image_url || castMember.image_url) ? <Image source={{ uri: person?.image_url || castMember.image_url || '' }} style={styles.photo} /> : <View style={styles.photoFallback}><Text style={styles.initial}>{castMember.name[0]}</Text></View>}<View style={styles.identity}><Text style={styles.name}>{person?.name || castMember.name}</Text><Text style={styles.department}>{person?.department || 'Atuação'}</Text>{person?.birthday ? <Text style={styles.meta}>{formatDate(person.birthday)}</Text> : null}{person?.place_of_birth ? <Text style={styles.meta}>{person.place_of_birth}</Text> : null}</View></View>{person?.biography ? <Text style={styles.biography}>{person.biography}</Text> : null}<View style={styles.creditsHeader}><Text style={styles.creditsTitle}>Filmes e séries</Text><Text style={styles.count}>{credits.length}</Text></View></View>;

  if (loading) return <View style={styles.screen}><View style={styles.loadingTop}><Pressable onPress={onBack}><Text style={styles.back}>‹</Text></Pressable><Text style={styles.topTitle}>Filmografia</Text></View><View style={styles.loading}><ActivityIndicator color={colors.accent} size="large" /><Text style={styles.loadingText}>Carregando trabalhos...</Text></View></View>;
  return <View style={styles.screen}>{error ? <View style={styles.error}><Pressable onPress={onBack}><Text style={styles.back}>‹</Text></Pressable><Text style={styles.errorText}>{error}</Text></View> : <FlatList data={credits} numColumns={3} keyExtractor={(item, index) => `${item.tmdb_id || item.tvmaze_id || item.mal_id || index}-${item.tipo}`} ListHeaderComponent={header} columnWrapperStyle={styles.row} contentContainerStyle={styles.list} renderItem={({ item }) => <View style={styles.card}><PosterCard item={item} onPress={onOpenItem} /></View>} ListEmptyComponent={<Text style={styles.empty}>Nenhum trabalho encontrado.</Text>} />}</View>;
}

function formatDate(value: string) { return new Date(`${value}T12:00:00`).toLocaleDateString('pt-BR'); }

const styles = StyleSheet.create({
  screen: { backgroundColor: colors.background, flex: 1 }, loadingTop: { alignItems: 'center', flexDirection: 'row', gap: 14, paddingHorizontal: 16, paddingTop: 12 }, loading: { alignItems: 'center', backgroundColor: colors.background, flex: 1, justifyContent: 'center' }, loadingText: { color: colors.muted, marginTop: 12 }, topBar: { alignItems: 'center', flexDirection: 'row', paddingVertical: 14 }, backButton: { marginRight: 14 }, back: { color: colors.text, fontSize: 40, lineHeight: 40 }, topTitle: { color: colors.text, fontSize: 20, fontWeight: '900' }, hero: { alignItems: 'center', backgroundColor: colors.surface, borderRadius: 22, flexDirection: 'row', padding: 16 }, photo: { borderRadius: 48, height: 96, width: 96 }, photoFallback: { alignItems: 'center', backgroundColor: colors.surfaceRaised, borderRadius: 48, height: 96, justifyContent: 'center', width: 96 }, initial: { color: colors.text, fontSize: 32, fontWeight: '900' }, identity: { flex: 1, paddingLeft: 16 }, name: { color: colors.text, fontSize: 24, fontWeight: '900' }, department: { color: colors.accent, fontSize: 13, fontWeight: '900', marginTop: 5 }, meta: { color: colors.muted, fontSize: 11, marginTop: 5 }, biography: { color: colors.text, lineHeight: 21, marginTop: 16 }, creditsHeader: { alignItems: 'center', flexDirection: 'row', justifyContent: 'space-between', marginBottom: 12, marginTop: 24 }, creditsTitle: { color: colors.text, fontSize: 19, fontWeight: '900' }, count: { backgroundColor: colors.surfaceRaised, borderRadius: 999, color: colors.text, fontWeight: '900', paddingHorizontal: 10, paddingVertical: 6 }, list: { paddingBottom: 115, paddingHorizontal: 12 }, row: { alignItems: 'flex-start' }, card: { paddingHorizontal: 5, width: '33.333%' }, empty: { color: colors.muted, paddingVertical: 30, textAlign: 'center' }, error: { padding: 18 }, errorText: { color: colors.text, marginTop: 20 },
});
