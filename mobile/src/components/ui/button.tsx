import { LinearGradient } from 'expo-linear-gradient';
import { type ReactNode } from 'react';
import { ActivityIndicator, Pressable, StyleSheet, type ViewStyle } from 'react-native';
import Animated, { useAnimatedStyle, useSharedValue, withSpring } from 'react-native-reanimated';

import { Text } from '@/components/ui/text';
import { tapFeedback } from '@/lib/haptics';
import { colors, fonts, goldGradient, radii, shadows, spacing } from '@/theme';

type Variant = 'gold' | 'primary' | 'outline' | 'ghost' | 'destructive';

type ButtonProps = {
  title: string;
  onPress: () => void;
  variant?: Variant;
  disabled?: boolean;
  loading?: boolean;
  icon?: ReactNode;
  style?: ViewStyle;
};

const AnimatedPressable = Animated.createAnimatedComponent(Pressable);

/**
 * Pill button. The gold variant is the one CTA per screen: lamplight
 * gradient, dark ink text, a soft glow. Everything else stays quiet.
 */
export function Button({
  title,
  onPress,
  variant = 'primary',
  disabled = false,
  loading = false,
  icon,
  style,
}: ButtonProps) {
  const scale = useSharedValue(1);

  const animatedStyle = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value }],
  }));

  const inactive = disabled || loading;

  const textColor =
    variant === 'gold'
      ? colors.goldForeground
      : variant === 'primary'
        ? '#100D2B'
        : variant === 'destructive'
          ? '#FFF5F5'
          : colors.foreground;

  const content = (
    <>
      {loading ? (
        <ActivityIndicator color={textColor} />
      ) : (
        <>
          {icon}
          <Text
            size="base"
            style={{ fontFamily: fonts.sansExtraBold, color: textColor, letterSpacing: 0.3 }}
          >
            {title}
          </Text>
        </>
      )}
    </>
  );

  return (
    <AnimatedPressable
      accessibilityRole="button"
      accessibilityState={{ disabled: inactive, busy: loading }}
      disabled={inactive}
      onPress={() => {
        tapFeedback();
        onPress();
      }}
      onPressIn={() => {
        scale.value = withSpring(0.97, { damping: 18, stiffness: 320 });
      }}
      onPressOut={() => {
        scale.value = withSpring(1, { damping: 18, stiffness: 320 });
      }}
      style={[
        animatedStyle,
        styles.base,
        variant === 'primary' && { backgroundColor: colors.primary },
        variant === 'outline' && styles.outline,
        variant === 'ghost' && { backgroundColor: 'transparent' },
        variant === 'destructive' && { backgroundColor: colors.destructive },
        variant === 'gold' && shadows.goldGlow,
        inactive && { opacity: 0.55 },
        style,
      ]}
    >
      {variant === 'gold' ? (
        <LinearGradient
          colors={goldGradient}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={styles.gradient}
        >
          {content}
        </LinearGradient>
      ) : (
        content
      )}
    </AnimatedPressable>
  );
}

const styles = StyleSheet.create({
  base: {
    borderRadius: radii.pill,
    minHeight: 52,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    paddingHorizontal: spacing['2xl'],
    overflow: 'hidden',
  },
  outline: {
    borderWidth: 1,
    borderColor: colors.whiteAlpha25,
    backgroundColor: colors.whiteAlpha05,
  },
  gradient: {
    position: 'absolute',
    top: 0,
    right: 0,
    bottom: 0,
    left: 0,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.sm,
    borderRadius: radii.pill,
  },
});
