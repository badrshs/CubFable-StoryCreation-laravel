import { useEffect, useMemo, useState } from 'react';
import { AccessibilityInfo, StyleSheet, View } from 'react-native';
import Animated, {
  useAnimatedStyle,
  useSharedValue,
  withDelay,
  withRepeat,
  withTiming,
} from 'react-native-reanimated';

import { colors } from '@/theme';

type Star = {
  top: `${number}%`;
  left: `${number}%`;
  size: number;
  delay: number;
  duration: number;
  baseOpacity: number;
  gold: boolean;
};

/**
 * The brand's ambient night sky, ported from the web Starfield: gently
 * twinkling stars (every seventh one lamplight gold) over two soft aurora
 * glows. Purely decorative; stars hold still under reduced motion.
 */
export function Starfield({ count = 36, aurora = true }: { count?: number; aurora?: boolean }) {
  const [reduceMotion, setReduceMotion] = useState(false);

  useEffect(() => {
    let mounted = true;

    void AccessibilityInfo.isReduceMotionEnabled().then((enabled) => {
      if (mounted) {
        setReduceMotion(enabled);
      }
    });

    return () => {
      mounted = false;
    };
  }, []);

  const stars = useMemo<Star[]>(
    () =>
      Array.from({ length: count }, (_, index) => {
        const gold = index % 7 === 0;

        return {
          top: `${Math.round(Math.random() * 100)}%` as `${number}%`,
          left: `${Math.round(Math.random() * 100)}%` as `${number}%`,
          size: gold ? 2.5 + Math.random() * 2 : 1 + Math.random() * 2,
          delay: Math.random() * 4500,
          duration: 3500 + Math.random() * 3000,
          baseOpacity: 0.35 + Math.random() * 0.5,
          gold,
        };
      }),
    [count],
  );

  return (
    <View pointerEvents="none" style={StyleSheet.absoluteFill} accessible={false}>
      {aurora && (
        <>
          <View style={[styles.aurora, styles.auroraIndigo]} />
          <View style={[styles.aurora, styles.auroraGold]} />
        </>
      )}
      {stars.map((star, index) => (
        <TwinklingStar key={index} star={star} animate={!reduceMotion} />
      ))}
    </View>
  );
}

function TwinklingStar({ star, animate }: { star: Star; animate: boolean }) {
  const opacity = useSharedValue(star.baseOpacity * 0.4);

  useEffect(() => {
    if (!animate) {
      opacity.value = star.baseOpacity;
      return;
    }

    opacity.value = withDelay(
      star.delay,
      withRepeat(withTiming(star.baseOpacity, { duration: star.duration / 2 }), -1, true),
    );
  }, [animate, opacity, star]);

  const animatedStyle = useAnimatedStyle(() => ({ opacity: opacity.value }));

  return (
    <Animated.View
      style={[
        styles.star,
        {
          top: star.top,
          left: star.left,
          width: star.size,
          height: star.size,
          borderRadius: star.size / 2,
          backgroundColor: star.gold ? colors.starGold : colors.starWhite,
          shadowColor: star.gold ? colors.starGold : colors.starWhite,
        },
        animatedStyle,
      ]}
    />
  );
}

const styles = StyleSheet.create({
  star: {
    position: 'absolute',
    shadowOpacity: 0.8,
    shadowRadius: 4,
    shadowOffset: { width: 0, height: 0 },
  },
  aurora: {
    position: 'absolute',
    borderRadius: 999,
    opacity: 0.55,
  },
  auroraIndigo: {
    top: '-20%',
    left: '-15%',
    width: '70%',
    height: '45%',
    backgroundColor: 'rgba(108, 92, 231, 0.28)',
    transform: [{ scale: 1.4 }],
  },
  auroraGold: {
    bottom: '-15%',
    right: '-15%',
    width: '65%',
    height: '40%',
    backgroundColor: 'rgba(242, 178, 62, 0.10)',
    transform: [{ scale: 1.4 }],
  },
});
