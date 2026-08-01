import { Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { Item } from '../types';
import { colors } from '../theme/colors';

type Props = {
  item: Item;
  onPress?: (item: Item) => void;
  width?: number;
};

export function PosterCard({ item, onPress, width = 104 }: Props) {
  const showReleaseDate = item.release_date ? item.release_date.slice(0, 10) > new Date().toISOString().slice(0, 10) : false;

  return (
    <Pressable onPress={() => onPress?.(item)} disabled={!onPress} style={[styles.card, { width }]}>
      <View style={styles.posterWrap}>
        {item.poster_url ? <Image source={{ uri: item.poster_url }} style={styles.poster} /> : <View style={styles.placeholder} />}
        <Text style={styles.type}>{item.type === 'movie' ? 'FILM' : item.type === 'anime' ? 'ANIME' : 'TV'}</Text>
      </View>
      <Text numberOfLines={1} style={styles.title}>{item.title}</Text>
      {showReleaseDate ? <Text numberOfLines={1} style={styles.date}>Lanca em {formatDate(item.release_date!)}</Text> : null}
    </Pressable>
  );
}

function formatDate(date: string) {
  const [year, month, day] = date.slice(0, 10).split('-');
  return `${day}/${month}/${year}`;
}

const styles = StyleSheet.create({
  card: {},
  posterWrap: {
    aspectRatio: 2 / 3,
    backgroundColor: colors.surface,
    borderColor: colors.surfaceRaised,
    borderRadius: 12,
    borderWidth: 1,
    overflow: 'hidden',
  },
  poster: { height: '100%', width: '100%' },
  placeholder: { backgroundColor: colors.surfaceRaised, height: '100%', width: '100%' },
  type: {
    backgroundColor: 'rgba(0,0,0,0.72)',
    bottom: 5,
    color: '#ffd200',
    fontSize: 9,
    fontWeight: '900',
    left: 5,
    paddingHorizontal: 4,
    position: 'absolute',
  },
  title: { color: colors.text, fontSize: 12, fontWeight: '800', marginTop: 6 },
  date: { color: colors.muted, fontSize: 10, fontWeight: '700', marginTop: 3 },
});
