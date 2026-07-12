import { StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/text';
import { colors, fonts } from '@/theme';

/** The CubFable wordmark: display face, "Fable" in lamplight gold. */
export function BrandMark({ size = 30 }: { size?: number }) {
  return (
    <View style={styles.row} accessibilityRole="header" accessibilityLabel="CubFable">
      <Text style={{ fontFamily: fonts.displayBold, fontSize: size, lineHeight: size * 1.25, color: colors.foreground }}>
        Cub
      </Text>
      <Text style={{ fontFamily: fonts.displayBold, fontSize: size, lineHeight: size * 1.25, color: colors.gold }}>
        Fable
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'baseline',
  },
});
