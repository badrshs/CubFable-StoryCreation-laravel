import { Redirect, useLocalSearchParams } from 'expo-router';
import { ActivityIndicator, StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/text';
import { useBook } from '@/lib/api/queries';
import { colors, spacing } from '@/theme';

/**
 * Status dispatcher: opening a book routes to the right experience for the
 * moment it is in: paywall for drafts, live progress while generating, the
 * reader once there is something to read.
 */
export default function BookDispatcher() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const bookId = Number(id);
  const { data: book, isPending, isError } = useBook(bookId);

  if (isPending) {
    return (
      <View style={styles.center}>
        <ActivityIndicator color={colors.gold} />
      </View>
    );
  }

  if (isError || book === undefined) {
    return (
      <View style={styles.center}>
        <Text variant="caption" center>
          This book could not be found.
        </Text>
      </View>
    );
  }

  if (book.status === 'draft') {
    return <Redirect href={{ pathname: '/book/[id]/paywall', params: { id } }} />;
  }

  if (book.status === 'pending' || book.status === 'generating') {
    return <Redirect href={{ pathname: '/book/[id]/progress', params: { id } }} />;
  }

  return <Redirect href={{ pathname: '/book/[id]/reader', params: { id } }} />;
}

const styles = StyleSheet.create({
  center: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing['2xl'],
  },
});
