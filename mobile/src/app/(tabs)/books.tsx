import { StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BrandMark } from '@/components/cubfable/brand-mark';
import { Text } from '@/components/ui/text';
import { spacing } from '@/theme';

export default function BooksScreen() {
  const insets = useSafeAreaInsets();

  return (
    <View style={[styles.screen, { paddingTop: insets.top + spacing.lg }]}>
      <BrandMark />
      <Text variant="caption">Coming in a later phase.</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    paddingHorizontal: spacing.xl,
    gap: spacing.lg,
  },
});
