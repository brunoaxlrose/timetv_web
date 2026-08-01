import { ReactNode, useEffect, useRef, useState } from 'react';
import { AccessibilityInfo, Animated, Easing, LayoutChangeEvent, StyleSheet, View } from 'react-native';
import { alpha, colors } from '../theme/colors';

type MarqueeProps<T> = {
  data: T[];
  renderItem: (item: T, index: number) => ReactNode;
  keyExtractor: (item: T, index: number) => string;
  gap?: number;
  speed?: number;
};

export function Marquee<T>({ data, renderItem, keyExtractor, gap = 12, speed = 34 }: MarqueeProps<T>) {
  const translateX = useRef(new Animated.Value(0)).current;
  const [groupWidth, setGroupWidth] = useState(0);
  const [reduceMotion, setReduceMotion] = useState(false);
  const repeated = data;
  const staticMode = data.length === 1 || reduceMotion;

  useEffect(() => {
    AccessibilityInfo.isReduceMotionEnabled().then(setReduceMotion);
    const subscription = AccessibilityInfo.addEventListener('reduceMotionChanged', setReduceMotion);
    return () => subscription.remove();
  }, []);

  useEffect(() => {
    translateX.stopAnimation();
    translateX.setValue(0);
    if (!groupWidth || staticMode) return;

    const animation = Animated.loop(Animated.timing(translateX, {
      duration: Math.max(6000, groupWidth / speed * 1000),
      easing: Easing.linear,
      toValue: -groupWidth,
      useNativeDriver: true,
    }));
    animation.start();
    return () => animation.stop();
  }, [groupWidth, speed, staticMode, translateX]);

  function measureGroup(event: LayoutChangeEvent) {
    const width = Math.ceil(event.nativeEvent.layout.width);
    if (width > 0 && width !== groupWidth) setGroupWidth(width);
  }

  if (!repeated.length) return null;

  return (
    <View style={styles.viewport} accessibilityRole="list">
      <Animated.View style={[styles.track, { transform: [{ translateX }] }]}>
        <View onLayout={measureGroup} style={[styles.group, { gap, paddingRight: gap }]}>
          {repeated.map((item, index) => <View key={`first-${keyExtractor(item, index)}`}>{renderItem(item, index)}</View>)}
        </View>
        {!staticMode ? <View style={[styles.group, { gap, paddingRight: gap }]}>{repeated.map((item, index) => <View key={`second-${keyExtractor(item, index)}`}>{renderItem(item, index)}</View>)}</View> : null}
      </Animated.View>
      <View pointerEvents="none" style={[styles.edge, styles.leftEdge]} />
      <View pointerEvents="none" style={[styles.edge, styles.rightEdge]} />
    </View>
  );
}

const styles = StyleSheet.create({
  viewport: { overflow: 'hidden', position: 'relative', width: '100%' },
  track: { alignItems: 'stretch', flexDirection: 'row' },
  group: { flexDirection: 'row' },
  edge: { bottom: 0, position: 'absolute', top: 0, width: 12 },
  leftEdge: { backgroundColor: alpha(colors.background, 0.42), left: 0 },
  rightEdge: { backgroundColor: alpha(colors.background, 0.42), right: 0 },
});
