import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { router } from 'expo-router';
import { FlatList, Pressable, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { useCharacters } from '@/lib/api/queries';
import { colors, radii, spacing } from '@/theme';

export default function CharactersScreen() {
  const insets = useSafeAreaInsets();
  const { data, isPending, refetch, isRefetching } = useCharacters();

  return (
    <View style={styles.screen}>
      <FlatList
        data={data ?? []}
        keyExtractor={(character) => String(character.id)}
        numColumns={2}
        columnWrapperStyle={styles.column}
        refreshing={isRefetching}
        onRefresh={() => void refetch()}
        contentContainerStyle={[
          styles.content,
          { paddingTop: insets.top + spacing.lg },
          (data?.length ?? 0) === 0 && styles.emptyContent,
        ]}
        ListHeaderComponent={
          <View style={styles.header}>
            <View style={styles.headerText}>
              <Text variant="title">Characters</Text>
              <Text variant="caption">Your cast, ready for any story.</Text>
            </View>
            <Pressable
              accessibilityRole="button"
              accessibilityLabel="Add character"
              onPress={() => router.push('/character/new')}
              style={styles.addButton}
            >
              <Ionicons name="add" size={24} color={colors.goldForeground} />
            </Pressable>
          </View>
        }
        ListEmptyComponent={
          isPending ? (
            <View style={styles.column}>
              <Skeleton style={styles.skeletonCard} />
              <Skeleton style={styles.skeletonCard} />
            </View>
          ) : (
            <View style={styles.empty}>
              <Text style={styles.emptyEmoji}>🧸</Text>
              <Text variant="title" size="xl" center>
                No characters yet
              </Text>
              <Text variant="caption" center>
                Save family and friends once, and reuse them in every story.
              </Text>
              <Button
                title="Add your first character"
                variant="gold"
                onPress={() => router.push('/character/new')}
                style={styles.emptyCta}
              />
            </View>
          )
        }
        renderItem={({ item }) => (
          <Card
            style={styles.characterCard}
            onPress={() => router.push({ pathname: '/character/[id]', params: { id: String(item.id) } })}
          >
            {item.photoUrl !== null ? (
              <Image source={{ uri: item.photoUrl }} style={styles.avatar} contentFit="cover" transition={200} />
            ) : (
              <View style={[styles.avatar, styles.avatarInitial]}>
                <Text variant="display" size="2xl">
                  {item.name.charAt(0).toUpperCase()}
                </Text>
              </View>
            )}
            <Text variant="label" numberOfLines={1} center>
              {item.name}
            </Text>
            <Text variant="caption" size="xs" numberOfLines={1} center>
              {item.role ?? (item.ageGroup === 'child' ? 'Child' : 'Adult')}
            </Text>
          </Card>
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
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: spacing.md,
  },
  headerText: {
    gap: spacing.xs,
  },
  addButton: {
    width: 44,
    height: 44,
    borderRadius: 22,
    backgroundColor: colors.gold,
    alignItems: 'center',
    justifyContent: 'center',
  },
  column: {
    gap: spacing.md,
  },
  skeletonCard: {
    flex: 1,
    height: 150,
  },
  empty: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.md,
    paddingBottom: spacing['4xl'],
  },
  emptyEmoji: {
    fontSize: 44,
  },
  emptyCta: {
    marginTop: spacing.md,
    alignSelf: 'stretch',
  },
  characterCard: {
    flex: 1,
    alignItems: 'center',
    gap: spacing.sm,
  },
  avatar: {
    width: 72,
    height: 72,
    borderRadius: radii.pill,
    backgroundColor: colors.cardRaised,
  },
  avatarInitial: {
    alignItems: 'center',
    justifyContent: 'center',
  },
});
