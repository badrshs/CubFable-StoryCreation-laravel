import { useEffect } from 'react';
import { StyleSheet, type ViewStyle } from 'react-native';
import Animated, {
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withTiming,
} from 'react-native-reanimated';

import { colors, radii } from '@/theme';

/** Pulsing placeholder block, the mobile cousin of the web shimmer. */
export function Skeleton({ style }: { style?: ViewStyle }) {
  const opacity = useSharedValue(0.4);

  useEffect(() => {
    opacity.value = withRepeat(withTiming(0.9, { duration: 900 }), -1, true);
  }, [opacity]);

  const animatedStyle = useAnimatedStyle(() => ({ opacity: opacity.value }));

  return <Animated.View style={[styles.block, animatedStyle, style]} />;
}

const styles = StyleSheet.create({
  block: {
    backgroundColor: colors.cardRaised,
    borderRadius: radii.md,
  },
});
