import { router } from 'expo-router';
import { FlatList, Pressable, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BookCover } from '@/components/cubfable/book-cover';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { StatusBadge } from '@/components/ui/status-badge';
import { Text } from '@/components/ui/text';
import type { BookSummary } from '@/lib/api/types';
import { useBooks } from '@/lib/api/queries';
import { artStyleLabel } from '@/lib/story-options';
import { colors, spacing } from '@/theme';

const COVER_WIDTH = 88;

function subtitle(book: BookSummary): string {
  if (book.status === 'generating' && book.pagesTotal > 0) {
    return `${book.pagesDone} of ${book.pagesTotal} pages painted`;
  }

  if (book.status === 'complete') {
    return `${book.pagesTotal} pages, ${artStyleLabel(book.artStyle)}`;
  }

  if (book.status === 'draft') {
    return 'Finish checkout to start the magic';
  }

  return artStyleLabel(book.artStyle);
}

export default function BooksScreen() {
  const insets = useSafeAreaInsets();
  const { data, isPending, refetch, isRefetching } = useBooks();

  return (
    <View style={styles.screen}>
      <FlatList
        data={data ?? []}
        keyExtractor={(book) => String(book.id)}
        refreshing={isRefetching}
        onRefresh={() => void refetch()}
        contentContainerStyle={[
          styles.content,
          { paddingTop: insets.top + spacing.lg },
          (data?.length ?? 0) === 0 && styles.emptyContent,
        ]}
        ListHeaderComponent={
          <View style={styles.header}>
            <Text variant="title">My Books</Text>
            <Text variant="caption">Every story you have made, kept safe.</Text>
          </View>
        }
        ListEmptyComponent={
          isPending ? (
            <View style={styles.skeletons}>
              {[0, 1, 2].map((index) => (
                <Skeleton key={index} style={styles.skeletonCard} />
              ))}
            </View>
          ) : (
            <View style={styles.empty}>
              <Text style={styles.emptyMoon}>📖</Text>
              <Text variant="title" size="xl" center>
                No books yet
              </Text>
              <Text variant="caption" center>
                Pick a story and make your child the hero of their first book.
              </Text>
              <Button
                title="Browse stories"
                variant="gold"
                onPress={() => router.push('/(tabs)/home')}
                style={styles.emptyCta}
              />
            </View>
          )
        }
        renderItem={({ item, index }) => (
          <Animated.View entering={FadeInDown.delay(Math.min(index * 70, 350)).duration(450)}>
            <Card
              padded={false}
              onPress={() => router.push({ pathname: '/book/[id]', params: { id: String(item.id) } })}
              style={styles.bookCard}
            >
              <View style={styles.bookRow}>
                <BookCover imageUrl={item.coverImageUrl} title={`${item.childName}'s story`} width={COVER_WIDTH} />
                <View style={styles.bookMeta}>
                  <Text variant="title" size="lg" numberOfLines={1}>
                    {item.childName}'s Storybook
                  </Text>
                  <Text variant="caption" numberOfLines={1}>
                    {subtitle(item)}
                  </Text>
                  <StatusBadge status={item.status} />
                </View>
              </View>
            </Card>
          </Animated.View>
        )}
      />
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  content: {
    paddingHorizontal: spacing.xl,
    paddingBottom: spacing['4xl'],
    gap: spacing.md,
  },
  emptyContent: {
    flexGrow: 1,
  },
  header: {
    gap: spacing.xs,
    marginBottom: spacing.md,
  },
  skeletons: {
    gap: spacing.md,
  },
  skeletonCard: {
    height: COVER_WIDTH / 0.75 + spacing.lg * 2,
  },
  empty: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.md,
    paddingBottom: spacing['4xl'],
  },
  emptyMoon: {
    fontSize: 44,
  },
  emptyCta: {
    marginTop: spacing.md,
    alignSelf: 'stretch',
  },
  bookCard: {
    padding: spacing.md,
  },
  bookRow: {
    flexDirection: 'row',
    gap: spacing.lg,
    alignItems: 'center',
  },
  bookMeta: {
    flex: 1,
    gap: spacing.sm,
  },
});
