import { Image, Pressable, StyleSheet, Text, View } from 'react-native';
import { Item } from '../types';
import { colors } from '../theme/colors';

type Props = {
  item: Item;
  onPress?: (item: Item) => void;
  width?: number;
};

export function PosterCard({ item, onPress, width = 104 }: Props) {
  const showReleaseDate = item.data_lancamento ? item.data_lancamento.slice(0, 10) > new Date().toISOString().slice(0, 10) : false;

  return (
    <Pressable onPress={() => onPress?.(item)} disabled={!onPress} style={[styles.card, { width }]}>
      <View style={styles.posterWrap}>
        {item.url_poster ? <Image source={{ uri: item.url_poster }} style={styles.poster} /> : <View style={styles.placeholder} />}
        <View style={styles.typeBadge}>
          <Text style={styles.typeIcon}>{typeGlyph(item.tipo)}</Text>
        </View>
      </View>
      <Text numberOfLines={1} style={styles.title}>{item.titulo}</Text>
      {showReleaseDate ? <Text numberOfLines={1} style={styles.date}>Lanca em {formatDate(item.data_lancamento!)}</Text> : null}
    </Pressable>
  );
}

function formatDate(date: string) {
  const [year, month, day] = date.slice(0, 10).split('-');
  return `${day}/${month}/${year}`;
}

function typeGlyph(type: string) {
  if (type === 'movie') return '▶';
  if (type === 'anime') return '✦';
  return '▣';
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
  typeBadge: {
    alignItems: 'center',
    backgroundColor: 'rgba(0,0,0,0.7)',
    borderRadius: 999,
    height: 18,
    justifyContent: 'center',
    position: 'absolute',
    right: 6,
    top: 6,
    width: 18,
  },
  typeIcon: { color: '#ffd200', fontSize: 9, fontWeight: '900', lineHeight: 11 },
  title: { color: colors.text, fontSize: 12, fontWeight: '800', marginTop: 6 },
  date: { color: colors.muted, fontSize: 10, fontWeight: '700', marginTop: 3 },
});
