import { Ionicons } from '@expo/vector-icons';
import { useQueryClient } from '@tanstack/react-query';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useRef, useState } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import Animated, {
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withSequence,
  withSpring,
  withTiming,
} from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { NightSky } from '@/components/cubfable/night-sky';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { invalidateBook, useBookStatus } from '@/lib/api/queries';
import type { PageStatus } from '@/lib/api/types';
import { lightImpact, successFeedback } from '@/lib/haptics';
import { colors, fonts, radii, spacing } from '@/theme';

const STORY_LINES = [
  'Mixing the twilight colors...',
  'Teaching the moon your child’s name...',
  'Painting brave little footsteps...',
  'Sprinkling stars between the pages...',
  'Asking the fireflies to hold the lantern...',
];

export default function ProgressScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const bookId = Number(id);
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const { data: status } = useBookStatus(bookId);

  const [lineIndex, setLineIndex] = useState(0);

  // Rotate the storytelling caption every few seconds while waiting.
  useEffect(() => {
    const timer = setInterval(() => {
      setLineIndex((index) => (index + 1) % STORY_LINES.length);
    }, 4200);

    return () => clearInterval(timer);
  }, []);

  // A finished book flows straight into the reader (one success haptic).
  const navigatedRef = useRef(false);

  useEffect(() => {
    if (status?.status === 'complete' && !navigatedRef.current) {
      navigatedRef.current = true;
      successFeedback();
      void invalidateBook(queryClient, bookId);

      const timer = setTimeout(() => {
        router.replace({ pathname: '/book/[id]/reader', params: { id: String(bookId) } });
      }, 1200);

      return () => clearTimeout(timer);
    }

    return undefined;
  }, [status?.status, bookId, queryClient]);

  const failed = status?.status === 'failed';

  return (
    <NightSky stars={48}>
      <ScrollView
        contentContainerStyle={[
          styles.content,
          { paddingTop: insets.top + spacing.lg, paddingBottom: insets.bottom + spacing['3xl'] },
        ]}
      >
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Back to my books"
          onPress={() => router.replace('/(tabs)/books')}
          style={styles.back}
        >
          <Ionicons name="chevron-down" size={22} color={colors.foreground} />
        </Pressable>

        <BreathingMoon done={status?.status === 'complete'} failed={failed} />

        <View style={styles.headline}>
          <Text variant="title" center>
            {failed
              ? 'This story hit a snag'
              : status?.status === 'complete'
                ? 'The book is ready!'
                : 'Your storybook is being made'}
          </Text>
          <Text variant="caption" center>
            {failed
              ? 'Our storytellers have been notified. Your purchase is safe and the finished pages are kept.'
              : status?.status === 'complete'
                ? 'Opening it now...'
                : STORY_LINES[lineIndex]}
          </Text>
        </View>

        {status !== undefined && status.pagesTotal > 0 && (
          <>
            <Text variant="display" size="lg" center color={colors.gold}>
              {status.pagesDone} of {status.pagesTotal} pages ready
            </Text>
            <View style={styles.tileGrid}>
              <CoverTile done={status.coverImageUrl !== null && status.coverStatus !== 'generating'} />
              {status.pages.map((page) => (
                <PageTile key={page.id} pageNumber={page.pageNumber} status={page.status} />
              ))}
            </View>
          </>
        )}

        {status !== undefined && status.pagesTotal === 0 && !failed && (
          <Text variant="caption" center>
            Writing the story itself first; the pages appear here in a moment.
          </Text>
        )}

        {failed && (
          <Button title="Back to my books" variant="outline" onPress={() => router.replace('/(tabs)/books')} />
        )}

        <Text variant="caption" size="xs" center style={styles.note}>
          You can close the app; the book keeps being made and will be waiting
          in My Books.
        </Text>
      </ScrollView>
    </NightSky>
  );
}

function BreathingMoon({ done, failed }: { done: boolean; failed: boolean }) {
  const scale = useSharedValue(1);

  useEffect(() => {
    scale.value = withRepeat(withTiming(1.06, { duration: 2400 }), -1, true);
  }, [scale]);

  const animatedStyle = useAnimatedStyle(() => ({ transform: [{ scale: scale.value }] }));

  return (
    <Animated.View style={[styles.moonWrap, animatedStyle]}>
      <Text style={styles.moon}>{failed ? '🌧️' : done ? '🌝' : '🌕'}</Text>
    </Animated.View>
  );
}

function CoverTile({ done }: { done: boolean }) {
  return (
    <Tile label="Cover" state={done ? 'complete' : 'generating'} celebrate={done} />
  );
}

function PageTile({ pageNumber, status }: { pageNumber: number; status: PageStatus }) {
  return (
    <Tile
      label={String(pageNumber)}
      state={status}
      celebrate={status === 'complete'}
    />
  );
}

function Tile({
  label,
  state,
  celebrate,
}: {
  label: string;
  state: PageStatus;
  celebrate: boolean;
}) {
  const scale = useSharedValue(1);
  const wasComplete = useRef(celebrate);

  // Spring + a haptic tick the moment this page turns gold.
  useEffect(() => {
    if (celebrate && !wasComplete.current) {
      wasComplete.current = true;
      lightImpact();
      scale.value = withSequence(
        withSpring(1.18, { damping: 6, stiffness: 300 }),
        withSpring(1, { damping: 12, stiffness: 260 }),
      );
    }
  }, [celebrate, scale]);

  const pulsing = state === 'generating';
  const opacity = useSharedValue(1);

  useEffect(() => {
    opacity.value = pulsing ? withRepeat(withTiming(0.45, { duration: 800 }), -1, true) : withTiming(1);
  }, [pulsing, opacity]);

  const animatedStyle = useAnimatedStyle(() => ({
    transform: [{ scale: scale.value }],
    opacity: opacity.value,
  }));

  return (
    <Animated.View
      style={[
        styles.tile,
        state === 'complete' && styles.tileComplete,
        state === 'failed' && styles.tileFailed,
        animatedStyle,
      ]}
    >
      <Text
        variant="display"
        size="sm"
        style={{
          fontFamily: fonts.displayBold,
          color:
            state === 'complete'
              ? colors.goldForeground
              : state === 'failed'
                ? colors.destructive
                : colors.mutedForeground,
        }}
      >
        {label}
      </Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  content: {
    flexGrow: 1,
    paddingHorizontal: spacing['2xl'],
    gap: spacing.xl,
    alignItems: 'center',
  },
  back: {
    alignSelf: 'flex-start',
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.whiteAlpha10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  moonWrap: {
    marginTop: spacing.xl,
  },
  moon: {
    fontSize: 76,
  },
  headline: {
    gap: spacing.sm,
    maxWidth: 300,
  },
  tileGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.md,
    justifyContent: 'center',
    maxWidth: 320,
  },
  tile: {
    width: 52,
    height: 52,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.whiteAlpha05,
    alignItems: 'center',
    justifyContent: 'center',
  },
  tileComplete: {
    backgroundColor: colors.gold,
    borderColor: colors.gold,
  },
  tileFailed: {
    borderColor: colors.destructive,
  },
  note: {
    marginTop: 'auto',
    maxWidth: 280,
  },
});
