import { Pressable, StyleSheet } from 'react-native';

import { Text } from '@/components/ui/text';
import { tapFeedback } from '@/lib/haptics';
import { colors, fonts, radii, spacing } from '@/theme';

type ChipProps = {
  label: string;
  selected?: boolean;
  onPress?: () => void;
};

/** Selectable pill; gold ring and warm fill when active, like web chips. */
export function Chip({ label, selected = false, onPress }: ChipProps) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      onPress={
        onPress === undefined
          ? undefined
          : () => {
              tapFeedback();
              onPress();
            }
      }
      style={[styles.chip, selected && styles.selected]}
    >
      <Text
        size="sm"
        style={{
          fontFamily: fonts.sansBold,
          color: selected ? colors.gold : colors.mutedForeground,
        }}
      >
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  chip: {
    borderRadius: radii.pill,
    paddingHorizontal: spacing.lg,
    paddingVertical: spacing.sm + 2,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.whiteAlpha05,
  },
  selected: {
    borderColor: colors.gold,
    backgroundColor: colors.goldAlpha15,
  },
});
