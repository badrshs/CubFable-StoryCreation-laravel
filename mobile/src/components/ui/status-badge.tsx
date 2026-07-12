import { StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/text';
import type { BookStatus } from '@/lib/api/types';
import { colors, fonts, radii, spacing } from '@/theme';

const statusStyles: Record<BookStatus, { label: string; color: string; background: string }> = {
  draft: { label: 'Awaiting payment', color: colors.moonlit, background: 'rgba(153, 190, 234, 0.14)' },
  pending: { label: 'In line', color: colors.gold, background: colors.goldAlpha15 },
  generating: { label: 'Being made', color: colors.primary, background: colors.primaryAlpha15 },
  complete: { label: 'Ready', color: colors.success, background: 'rgba(74, 222, 128, 0.14)' },
  failed: { label: 'Needs attention', color: colors.destructive, background: 'rgba(230, 86, 96, 0.14)' },
};

export function StatusBadge({ status }: { status: BookStatus }) {
  const style = statusStyles[status];

  return (
    <View style={[styles.badge, { backgroundColor: style.background }]}>
      <View style={[styles.dot, { backgroundColor: style.color }]} />
      <Text size="xs" style={{ fontFamily: fonts.sansBold, color: style.color }}>
        {style.label}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.xs + 2,
    alignSelf: 'flex-start',
    borderRadius: radii.pill,
    paddingHorizontal: spacing.md,
    paddingVertical: spacing.xs + 1,
  },
  dot: {
    width: 6,
    height: 6,
    borderRadius: 3,
  },
});
