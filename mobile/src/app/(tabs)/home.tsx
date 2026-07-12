import { router } from 'expo-router';
import { useMemo, useState } from 'react';
import { FlatList, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BookCover } from '@/components/cubfable/book-cover';
import { BrandMark } from '@/components/cubfable/brand-mark';
import { Chip } from '@/components/ui/chip';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import type { Template } from '@/lib/api/types';
import { useTemplates } from '@/lib/api/queries';
import { useAuth } from '@/lib/auth/context';
import { tapFeedback } from '@/lib/haptics';
import { colors, fonts, spacing } from '@/theme';

const COVER_WIDTH = 138;

function greeting(): string {
  const hour = new Date().getHours();

  if (hour < 12) {
    return 'Good morning';
  }

  if (hour < 18) {
    return 'Good afternoon';
  }

  return 'Good evening';
}

type Shelf = {
  key: string;
  title: string;
  caption: string;
  templates: Template[];
};

function buildShelves(templates: Template[]): Shelf[] {
  const byBand = new Map<string, Template[]>();

  for (const template of templates) {
    const key = `${template.ageMin}-${template.ageMax}`;
    byBand.set(key, [...(byBand.get(key) ?? []), template]);
  }

  return [...byBand.entries()]
    .sort(([a], [b]) => Number(a.split('-')[0]) - Number(b.split('-')[0]))
    .map(([key, shelfTemplates]) => ({
      key,
      title: `Ages ${key.replace('-', ' to ')}`,
      caption: `${shelfTemplates[0]?.pageCount ?? 0} illustrated pages`,
      templates: shelfTemplates,
    }));
}

export default function HomeScreen() {
  const insets = useSafeAreaInsets();
  const { user } = useAuth();
  const { data, isPending, isError, refetch } = useTemplates();
  const [theme, setTheme] = useState<string | null>(null);

  const shelves = useMemo(() => {
    if (data === undefined) {
      return [];
    }

    const filtered = theme === null
      ? data.templates
      : data.templates.filter((template) => template.theme === theme);

    return buildShelves(filtered);
  }, [data, theme]);

  const firstName = user?.name.split(' ')[0] ?? '';

  return (
    <ScrollView
      style={styles.screen}
      contentContainerStyle={[styles.content, { paddingTop: insets.top + spacing.lg }]}
    >
      <View style={styles.header}>
        <BrandMark size={24} />
        <Text variant="title" size="2xl">
          {greeting()}, {firstName} ✨
        </Text>
        <Text variant="caption">Which story shall we tell next?</Text>
      </View>

      {data !== undefined && data.themes.length > 1 && (
        <ScrollView horizontal showsHorizontalScrollIndicator={false} contentContainerStyle={styles.filters}>
          <Chip label="All stories" selected={theme === null} onPress={() => setTheme(null)} />
          {data.themes.map((item) => (
            <Chip
              key={item}
              label={item.charAt(0).toUpperCase() + item.slice(1)}
              selected={theme === item}
              onPress={() => setTheme(theme === item ? null : item)}
            />
          ))}
        </ScrollView>
      )}

      {isPending && (
        <View style={styles.skeletonRow}>
          {[0, 1, 2].map((index) => (
            <Skeleton key={index} style={{ width: COVER_WIDTH, height: COVER_WIDTH / 0.75 }} />
          ))}
        </View>
      )}

      {isError && (
        <View style={styles.errorBox}>
          <Text center color={colors.mutedForeground}>
            The story catalog did not load.
          </Text>
          <Pressable onPress={() => void refetch()}>
            <Text center color={colors.gold} bold>
              Try again
            </Text>
          </Pressable>
        </View>
      )}

      {shelves.map((shelf, index) => (
        <Animated.View
          key={shelf.key}
          entering={FadeInDown.delay(index * 90).duration(500).springify()}
          style={styles.shelf}
        >
          <View style={styles.shelfHeader}>
            <Text variant="title" size="xl">
              {shelf.title}
            </Text>
            <Text variant="caption">{shelf.caption}</Text>
          </View>
          <FlatList
            horizontal
            data={shelf.templates}
            keyExtractor={(template) => String(template.id)}
            showsHorizontalScrollIndicator={false}
            contentContainerStyle={styles.shelfRow}
            renderItem={({ item }) => (
              <Pressable
                accessibilityRole="button"
                accessibilityLabel={item.title}
                onPress={() => {
                  tapFeedback();
                  router.push({ pathname: '/template/[id]', params: { id: String(item.id) } });
                }}
                style={({ pressed }) => [styles.templateCard, pressed && styles.pressed]}
              >
                <BookCover imageUrl={item.coverImageUrl} title={item.title} width={COVER_WIDTH} />
                <Text numberOfLines={2} size="sm" style={styles.templateTitle}>
                  {item.title}
                </Text>
              </Pressable>
            )}
          />
        </Animated.View>
      ))}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  content: {
    paddingBottom: spacing['4xl'],
    gap: spacing['2xl'],
  },
  header: {
    paddingHorizontal: spacing.xl,
    gap: spacing.xs,
  },
  filters: {
    paddingHorizontal: spacing.xl,
    gap: spacing.sm,
  },
  skeletonRow: {
    flexDirection: 'row',
    gap: spacing.md,
    paddingHorizontal: spacing.xl,
  },
  errorBox: {
    paddingHorizontal: spacing.xl,
    gap: spacing.sm,
  },
  shelf: {
    gap: spacing.md,
  },
  shelfHeader: {
    paddingHorizontal: spacing.xl,
    flexDirection: 'row',
    alignItems: 'baseline',
    justifyContent: 'space-between',
  },
  shelfRow: {
    paddingHorizontal: spacing.xl,
    gap: spacing.md,
  },
  templateCard: {
    width: COVER_WIDTH,
    gap: spacing.sm,
  },
  templateTitle: {
    fontFamily: fonts.sansBold,
  },
  pressed: {
    opacity: 0.85,
    transform: [{ scale: 0.98 }],
  },
});
