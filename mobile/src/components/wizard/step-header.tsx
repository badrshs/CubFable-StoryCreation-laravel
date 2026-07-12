import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Text } from '@/components/ui/text';
import { colors, spacing } from '@/theme';

type StepHeaderProps = {
  step: 1 | 2 | 3;
  title: string;
  caption: string;
};

/** Wizard header: back, title, and three moons filling gold step by step. */
export function StepHeader({ step, title, caption }: StepHeaderProps) {
  const insets = useSafeAreaInsets();

  return (
    <View style={[styles.header, { paddingTop: insets.top + spacing.md }]}>
      <View style={styles.topRow}>
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Back"
          onPress={() => router.back()}
          style={styles.back}
        >
          <Ionicons name="chevron-back" size={20} color={colors.foreground} />
        </Pressable>
        <View style={styles.moons} accessibilityLabel={`Step ${step} of 3`}>
          {([1, 2, 3] as const).map((moon) => (
            <Text key={moon} style={[styles.moon, moon > step && styles.moonDim]}>
              {moon <= step ? '🌕' : '🌑'}
            </Text>
          ))}
        </View>
        <View style={styles.back} />
      </View>
      <Text variant="title">{title}</Text>
      <Text variant="caption">{caption}</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  header: {
    paddingHorizontal: spacing.xl,
    gap: spacing.xs,
    paddingBottom: spacing.md,
  },
  topRow: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginBottom: spacing.md,
  },
  back: {
    width: 38,
    height: 38,
    borderRadius: 19,
    backgroundColor: colors.whiteAlpha10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  moons: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  moon: {
    fontSize: 16,
  },
  moonDim: {
    opacity: 0.45,
  },
});
