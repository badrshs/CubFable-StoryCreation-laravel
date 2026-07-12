import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect, useState } from 'react';
import { Alert, Linking, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import type { PurchasesPackage } from 'react-native-purchases';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BookCover } from '@/components/cubfable/book-cover';
import { NightSky } from '@/components/cubfable/night-sky';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { isApiError } from '@/lib/api/client';
import { createIapIntent, reconcileIap } from '@/lib/api/endpoints';
import { useBook } from '@/lib/api/queries';
import { invalidateBook } from '@/lib/api/queries';
import { successFeedback } from '@/lib/haptics';
import { artStyleLabel } from '@/lib/story-options';
import {
  fetchBookPackage,
  isPurchaseCancelled,
  purchasePackage,
  purchasesAvailable,
  restorePurchases,
  tagPurchaseTarget,
} from '@/lib/purchases/revenuecat';
import { useQueryClient } from '@tanstack/react-query';
import { colors, shadows, spacing } from '@/theme';

const TERMS_URL = 'https://cubfable.com/terms';
const PRIVACY_URL = 'https://cubfable.com/privacy';

export default function PaywallScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const bookId = Number(id);
  const insets = useSafeAreaInsets();
  const queryClient = useQueryClient();
  const { data: book } = useBook(bookId);

  const [pkg, setPkg] = useState<PurchasesPackage | null>(null);
  const [buying, setBuying] = useState(false);
  const [restoring, setRestoring] = useState(false);

  // Load the store offering up front so the price shows immediately.
  useEffect(() => {
    let mounted = true;

    (async () => {
      try {
        const intent = await createIapIntent(bookId).catch(() => null);
        const bookPackage = await fetchBookPackage(intent?.productId ?? 'cubfable_book');

        if (mounted) {
          setPkg(bookPackage);
        }
      } catch {
        // Offerings can fail in Expo Go or before store setup; the screen
        // still renders and purchase reports the problem on tap.
      }
    })();

    return () => {
      mounted = false;
    };
  }, [bookId]);

  const finishAfterPurchase = async () => {
    const status = await reconcileIap(bookId).catch(() => null);

    await invalidateBook(queryClient, bookId);

    if (status !== null && status !== 'draft') {
      successFeedback();
      router.replace({ pathname: '/book/[id]/progress', params: { id: String(bookId) } });
    }
  };

  const buy = async () => {
    if (!purchasesAvailable()) {
      Alert.alert(
        'Purchases unavailable',
        'In-app purchases need a development build with the store configured. This is expected inside Expo Go.',
      );

      return;
    }

    setBuying(true);

    try {
      const intent = await createIapIntent(bookId);
      const bookPackage = pkg ?? (await fetchBookPackage(intent.productId));

      if (bookPackage === null) {
        Alert.alert('Store', 'The book product is not available right now. Please try again later.');

        return;
      }

      await tagPurchaseTarget(bookId, intent.orderId);
      await purchasePackage(bookPackage);
      await finishAfterPurchase();
    } catch (cause) {
      if (isPurchaseCancelled(cause)) {
        return;
      }

      if (isApiError(cause)) {
        // A book that is already paid routes forward instead of erroring.
        if (cause.error.kind === 'conflict') {
          await finishAfterPurchase();

          return;
        }

        Alert.alert('Purchase', cause.message);

        return;
      }

      Alert.alert('Purchase', 'The purchase did not complete. You have not been charged twice; try again.');
    } finally {
      setBuying(false);
    }
  };

  const restore = async () => {
    setRestoring(true);

    try {
      await restorePurchases();
      await finishAfterPurchase();
    } finally {
      setRestoring(false);
    }
  };

  if (book === undefined) {
    return <NightSky stars={20}>{null}</NightSky>;
  }

  const pageCount = book.pages.length > 0 ? book.pages.length : null;

  return (
    <NightSky stars={40}>
      <ScrollView
        contentContainerStyle={[
          styles.content,
          { paddingTop: insets.top + spacing.lg, paddingBottom: insets.bottom + spacing['2xl'] },
        ]}
      >
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Back"
          onPress={() => (router.canGoBack() ? router.back() : router.replace('/(tabs)/books'))}
          style={styles.back}
        >
          <Ionicons name="chevron-back" size={22} color={colors.foreground} />
        </Pressable>

        <View style={[styles.coverWrap, shadows.goldGlow]}>
          <BookCover imageUrl={book.coverImageUrl} title={`${book.childName}'s Storybook`} width={190} />
        </View>

        <View style={styles.headline}>
          <Text variant="title" center>
            {book.childName}'s story is written and waiting
          </Text>
          <Text variant="caption" center>
            Unlock it once, keep it forever.
          </Text>
        </View>

        <View style={styles.features}>
          <Feature icon="color-palette" text={`Every page illustrated in ${artStyleLabel(book.artStyle)}`} />
          <Feature
            icon="book"
            text={`${pageCount !== null ? `${pageCount} pages` : 'A full storybook'} starring ${book.childName}`}
          />
          <Feature icon="print" text="Print-ready PDF plus a home edition" />
          <Feature icon="sparkles" text="Edit text and repaint art whenever you like" />
        </View>

        <View style={styles.actions}>
          <Button
            title={pkg !== null ? `Unlock for ${pkg.product.priceString}` : 'Unlock this book'}
            variant="gold"
            loading={buying}
            onPress={() => void buy()}
          />
          <Button title="Restore purchases" variant="ghost" loading={restoring} onPress={() => void restore()} />
          <Button
            title="Edit the story first"
            variant="outline"
            onPress={() =>
              router.push({ pathname: '/create/hero', params: { bookId: String(bookId) } })
            }
          />
        </View>

        <View style={styles.legal}>
          <Text variant="caption" size="xs" center>
            One-time purchase billed by the app store.{' '}
            <Text
              variant="caption"
              size="xs"
              color={colors.moonlit}
              onPress={() => void Linking.openURL(TERMS_URL)}
            >
              Terms
            </Text>{' '}
            <Text
              variant="caption"
              size="xs"
              color={colors.moonlit}
              onPress={() => void Linking.openURL(PRIVACY_URL)}
            >
              Privacy
            </Text>
          </Text>
        </View>
      </ScrollView>
    </NightSky>
  );
}

function Feature({ icon, text }: { icon: keyof typeof Ionicons.glyphMap; text: string }) {
  return (
    <View style={styles.feature}>
      <Ionicons name={icon} size={18} color={colors.gold} />
      <Text size="sm" style={styles.featureText}>
        {text}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  content: {
    paddingHorizontal: spacing['2xl'],
    gap: spacing.xl,
  },
  back: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.whiteAlpha10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  coverWrap: {
    alignSelf: 'center',
  },
  headline: {
    gap: spacing.xs,
  },
  features: {
    gap: spacing.md,
    backgroundColor: colors.whiteAlpha05,
    borderRadius: 18,
    borderWidth: StyleSheet.hairlineWidth,
    borderColor: colors.border,
    padding: spacing.lg,
  },
  feature: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
  },
  featureText: {
    flex: 1,
  },
  actions: {
    gap: spacing.sm,
  },
  legal: {
    paddingHorizontal: spacing.lg,
  },
});
