import { Ionicons } from '@expo/vector-icons';
import { useQueryClient } from '@tanstack/react-query';
import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useMemo, useRef, useState } from 'react';
import {
  ActivityIndicator,
  Alert,
  Pressable,
  ScrollView,
  StyleSheet,
  View,
  useWindowDimensions,
} from 'react-native';
import PagerView from 'react-native-pager-view';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BookMenuSheet } from '@/components/reader/book-menu-sheet';
import { EditTextSheet } from '@/components/reader/edit-text-sheet';
import { Text } from '@/components/ui/text';
import { isApiError } from '@/lib/api/client';
import {
  useRegenerateCover,
  useRegeneratePage,
  useRestyleBook,
  useUpdatePageText,
} from '@/lib/api/mutations';
import { invalidateBook, isGenerationInFlight, useBook, useBookStatus } from '@/lib/api/queries';
import type { BookPage } from '@/lib/api/types';
import { downloadAndShareBookPdf } from '@/lib/files/download-pdf';
import { tapFeedback } from '@/lib/haptics';
import {
  ART_ASPECT_RATIO,
  colors,
  fonts,
  frameGradient,
  isRtlLanguage,
  radii,
  spacing,
  storyFontFor,
} from '@/theme';

export default function ReaderScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const bookId = Number(id);
  const insets = useSafeAreaInsets();
  const { width, height } = useWindowDimensions();
  const queryClient = useQueryClient();

  const { data: book } = useBook(bookId);
  const inFlight = book !== undefined && isGenerationInFlight(book);
  const { data: liveStatus } = useBookStatus(bookId, inFlight);

  // While a page or cover regenerates, fold live status updates back into
  // the cached book so fresh art appears without leaving the reader.
  const lastLiveStamp = useRef('');

  useEffect(() => {
    if (liveStatus === undefined) {
      return;
    }

    const stamp = `${liveStatus.coverImageUrl}|${liveStatus.pages
      .map((page) => `${page.id}:${page.status}:${page.imageUrl}`)
      .join(',')}`;

    if (stamp !== lastLiveStamp.current) {
      lastLiveStamp.current = stamp;
      void invalidateBook(queryClient, bookId);
    }
  }, [liveStatus, queryClient, bookId]);

  const [pageIndex, setPageIndex] = useState(0);
  const [editingPage, setEditingPage] = useState<BookPage | null>(null);
  const [menuOpen, setMenuOpen] = useState(false);
  const [downloading, setDownloading] = useState(false);

  const pagerRef = useRef<PagerView>(null);

  const updateText = useUpdatePageText(bookId);
  const regeneratePage = useRegeneratePage(bookId);
  const regenerateCover = useRegenerateCover(bookId);
  const restyleBook = useRestyleBook(bookId);

  const pages = useMemo(() => book?.pages ?? [], [book?.pages]);

  if (book === undefined) {
    return (
      <View style={styles.loading}>
        <ActivityIndicator color={colors.gold} />
      </View>
    );
  }

  const rtl = isRtlLanguage(book.language);
  const currentPage: BookPage | null = pageIndex > 0 ? (pages[pageIndex - 1] ?? null) : null;

  const confirmRegeneratePage = (page: BookPage) => {
    Alert.alert('Repaint this page?', 'A fresh illustration replaces this one in a minute or two.', [
      { text: 'Cancel', style: 'cancel' },
      {
        text: 'Repaint',
        onPress: () => {
          regeneratePage.mutate(page.id, {
            onError: (cause) =>
              Alert.alert('Repaint', isApiError(cause) ? cause.message : 'Could not start. Try again.'),
          });
        },
      },
    ]);
  };

  const download = async (variant: 'home' | 'print') => {
    setMenuOpen(false);
    setDownloading(true);

    try {
      await downloadAndShareBookPdf(bookId, book.childName, variant);
    } catch (cause) {
      Alert.alert('Download', cause instanceof Error ? cause.message : 'The download failed.');
    } finally {
      setDownloading(false);
    }
  };

  return (
    <View style={styles.screen}>
      <PagerView
        ref={pagerRef}
        style={styles.pager}
        initialPage={0}
        layoutDirection={rtl ? 'rtl' : 'ltr'}
        onPageSelected={(event) => {
          setPageIndex(event.nativeEvent.position);
          tapFeedback();
        }}
      >
        {/* Cover page */}
        <View key="cover" style={styles.coverPage}>
          <LinearGradient colors={frameGradient} start={{ x: 0, y: 0 }} end={{ x: 1, y: 1 }} style={styles.coverFrame}>
            <View style={styles.coverRing}>
              {book.coverImageUrl !== null ? (
                <Image
                  source={{ uri: book.coverImageUrl }}
                  style={styles.coverArt}
                  contentFit="cover"
                  transition={300}
                  accessibilityLabel={`Cover of ${book.childName}'s storybook`}
                />
              ) : (
                <View style={[styles.coverArt, styles.coverPlaceholder]}>
                  {book.coverStatus === 'generating' ? (
                    <ActivityIndicator color={colors.gold} />
                  ) : (
                    <Text style={styles.coverMoon}>🌙</Text>
                  )}
                </View>
              )}
            </View>
          </LinearGradient>
          <Text variant="title" size="2xl" center style={styles.coverTitle}>
            {book.childName}'s Storybook
          </Text>
          <Text variant="caption" center>
            Swipe to begin
          </Text>
        </View>

        {/* Story pages: art on the night mat, text on warm paper. */}
        {pages.map((page) => {
          const artHeight = Math.min(width / ART_ASPECT_RATIO, height * 0.55);

          return (
            <View key={page.id} style={styles.storyPage}>
              <View style={[styles.artMat, { height: artHeight }]}>
                {page.imageUrl !== null ? (
                  <Image
                    source={{ uri: page.imageUrl }}
                    style={styles.pageArt}
                    contentFit="contain"
                    transition={300}
                    accessibilityLabel={`Illustration for page ${page.pageNumber}`}
                  />
                ) : (
                  <View style={styles.artPending}>
                    {page.status === 'generating' || page.status === 'pending' ? (
                      <>
                        <ActivityIndicator color={colors.gold} />
                        <Text variant="caption" size="xs">
                          Painting this page...
                        </Text>
                      </>
                    ) : (
                      <Text variant="caption" size="xs">
                        This page's art needs another try.
                      </Text>
                    )}
                  </View>
                )}
              </View>

              <ScrollView style={styles.paperPanel} contentContainerStyle={styles.paperContent}>
                <Text
                  style={{
                    fontFamily: storyFontFor(book.language),
                    fontSize: 21,
                    lineHeight: 34,
                    color: colors.paperInk,
                    textAlign: rtl ? 'right' : 'left',
                    writingDirection: rtl ? 'rtl' : 'ltr',
                  }}
                >
                  {page.text}
                </Text>
                <Text style={styles.pageNumber}>{page.pageNumber}</Text>
              </ScrollView>
            </View>
          );
        })}
      </PagerView>

      {/* Top bar */}
      <View style={[styles.topBar, { paddingTop: insets.top + spacing.sm }]}>
        <IconButton
          icon="chevron-down"
          label="Close the book"
          onPress={() => (router.canGoBack() ? router.back() : router.replace('/(tabs)/books'))}
        />
        <Text variant="display" size="sm" numberOfLines={1} style={styles.topTitle}>
          {book.childName}'s Storybook
        </Text>
        {downloading ? (
          <View style={styles.iconButton}>
            <ActivityIndicator size="small" color={colors.gold} />
          </View>
        ) : (
          <IconButton icon="ellipsis-horizontal" label="Book actions" onPress={() => setMenuOpen(true)} />
        )}
      </View>

      {/* Bottom bar: page dots + per-page actions */}
      <View style={[styles.bottomBar, { paddingBottom: insets.bottom + spacing.sm }]}>
        <View style={styles.dots}>
          {[null, ...pages].map((page, index) => (
            <Pressable
              key={page === null ? 'cover' : page.id}
              accessibilityRole="button"
              accessibilityLabel={index === 0 ? 'Cover' : `Page ${index}`}
              onPress={() => pagerRef.current?.setPage(index)}
              hitSlop={6}
            >
              <View style={[styles.dot, index === pageIndex && styles.dotActive]} />
            </Pressable>
          ))}
        </View>
        {currentPage !== null && (
          <View style={styles.pageActions}>
            <IconButton icon="pencil" label="Edit this page's text" onPress={() => setEditingPage(currentPage)} />
            <IconButton
              icon="color-wand"
              label="Repaint this page's art"
              onPress={() => confirmRegeneratePage(currentPage)}
            />
          </View>
        )}
      </View>

      <EditTextSheet
        page={editingPage}
        language={book.language}
        saving={updateText.isPending}
        onClose={() => setEditingPage(null)}
        onSave={(pageId, text) => {
          updateText.mutate(
            { pageId, text },
            {
              onSuccess: () => setEditingPage(null),
              onError: (cause) =>
                Alert.alert('Save', isApiError(cause) ? cause.message : 'Could not save. Try again.'),
            },
          );
        }}
      />

      <BookMenuSheet
        visible={menuOpen}
        currentArtStyle={book.artStyle}
        busy={restyleBook.isPending}
        onClose={() => setMenuOpen(false)}
        onDownload={(variant) => void download(variant)}
        onRegenerateCover={() => {
          setMenuOpen(false);
          regenerateCover.mutate(undefined, {
            onSuccess: () => pagerRef.current?.setPage(0),
            onError: (cause) =>
              Alert.alert('Cover', isApiError(cause) ? cause.message : 'Could not start. Try again.'),
          });
        }}
        onRestyle={(artStyle) => {
          restyleBook.mutate(artStyle, {
            onSuccess: () => {
              setMenuOpen(false);
              router.replace({ pathname: '/book/[id]/progress', params: { id: String(bookId) } });
            },
            onError: (cause) =>
              Alert.alert('Repaint', isApiError(cause) ? cause.message : 'Could not start. Try again.'),
          });
        }}
      />
    </View>
  );
}

function IconButton({
  icon,
  label,
  onPress,
}: {
  icon: keyof typeof Ionicons.glyphMap;
  label: string;
  onPress: () => void;
}) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      onPress={() => {
        tapFeedback();
        onPress();
      }}
      style={({ pressed }) => [styles.iconButton, pressed && { opacity: 0.7 }]}
    >
      <Ionicons name={icon} size={20} color={colors.foreground} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  loading: {
    flex: 1,
    backgroundColor: colors.bg,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pager: {
    flex: 1,
  },
  coverPage: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.lg,
    padding: spacing['2xl'],
  },
  coverFrame: {
    borderRadius: radii.lg,
    padding: 8,
    width: '78%',
    aspectRatio: ART_ASPECT_RATIO,
  },
  coverRing: {
    flex: 1,
    borderRadius: radii.lg - 8,
    borderWidth: 1,
    borderColor: colors.frameRing,
    overflow: 'hidden',
  },
  coverArt: {
    flex: 1,
    backgroundColor: colors.artMat,
  },
  coverPlaceholder: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  coverMoon: {
    fontSize: 44,
  },
  coverTitle: {
    marginTop: spacing.md,
  },
  storyPage: {
    flex: 1,
    paddingTop: 92,
  },
  artMat: {
    backgroundColor: colors.artMat,
    alignItems: 'center',
    justifyContent: 'center',
  },
  pageArt: {
    width: '100%',
    height: '100%',
  },
  artPending: {
    alignItems: 'center',
    gap: spacing.sm,
  },
  paperPanel: {
    flex: 1,
    backgroundColor: colors.paper,
  },
  paperContent: {
    padding: spacing['2xl'],
    paddingBottom: 110,
    gap: spacing.xl,
  },
  pageNumber: {
    alignSelf: 'center',
    fontFamily: fonts.serifItalic,
    fontSize: 15,
    color: colors.paperGilt,
  },
  topBar: {
    position: 'absolute',
    top: 0,
    left: 0,
    right: 0,
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingHorizontal: spacing.lg,
    paddingBottom: spacing.sm,
    backgroundColor: 'rgba(12, 10, 34, 0.72)',
  },
  topTitle: {
    flex: 1,
    textAlign: 'center',
  },
  iconButton: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.whiteAlpha10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  bottomBar: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    paddingHorizontal: spacing.lg,
    paddingTop: spacing.sm,
    backgroundColor: 'rgba(12, 10, 34, 0.72)',
  },
  dots: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.sm,
    flexShrink: 1,
    flexWrap: 'wrap',
  },
  dot: {
    width: 8,
    height: 8,
    borderRadius: 4,
    backgroundColor: colors.whiteAlpha25,
  },
  dotActive: {
    backgroundColor: colors.gold,
    width: 20,
  },
  pageActions: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
});
