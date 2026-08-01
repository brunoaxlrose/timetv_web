import { DimensionValue, StyleSheet, View } from 'react-native';
import { colors } from '../theme/colors';

export function Skeleton({ height, width = '100%', radius = 16 }: { height: number; width?: DimensionValue; radius?: number }) {
  return <View style={[styles.skeleton, { height, width, borderRadius: radius }]} />;
}

export function PosterSkeletonRow() {
  return (
    <View style={styles.row}>
      {[0, 1, 2].map((item) => (
        <View key={item} style={styles.posterBlock}>
          <Skeleton height={150} width={100} radius={12} />
          <Skeleton height={12} width={82} radius={6} />
        </View>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  skeleton: {
    backgroundColor: colors.surfaceRaised,
    opacity: 0.72,
  },
  row: {
    alignSelf: 'center',
    flexDirection: 'row',
    gap: 12,
    marginTop: 14,
  },
  posterBlock: {
    gap: 8,
  },
});
