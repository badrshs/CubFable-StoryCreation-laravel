import { StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/text';
import { colors } from '@/theme';

export default function PlaceholderScreen() {
  return (
    <View style={styles.center}>
      <Text variant="caption">Coming in a later phase.</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
