import { Image } from 'expo-image';
import { router } from 'expo-router';
import { StyleSheet, View } from 'react-native';
import Animated, { FadeInDown, FadeInUp } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BrandMark } from '@/components/cubfable/brand-mark';
import { NightSky } from '@/components/cubfable/night-sky';
import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { colors, fonts, spacing } from '@/theme';

export default function WelcomeScreen() {
  const insets = useSafeAreaInsets();

  return (
    <NightSky stars={52}>
      <View style={[styles.screen, { paddingTop: insets.top + spacing['4xl'], paddingBottom: insets.bottom + spacing['2xl'] }]}>
        <Animated.View entering={FadeInDown.duration(700).springify()} style={styles.hero}>
          <Image
            source={require('@/assets/images/splash-icon.png')}
            style={styles.mark}
            contentFit="contain"
            accessibilityLabel="CubFable"
          />
          <BrandMark size={40} />
          <Text variant="title" size="3xl" center style={styles.headline}>
            Your child, the hero of their own storybook
          </Text>
          <Text center color={colors.mutedForeground} style={styles.subhead}>
            Pick a story, add your little one's name and photo, and watch a
            one-of-a-kind illustrated book come to life, ready to read tonight.
          </Text>
        </Animated.View>

        <Animated.View entering={FadeInUp.delay(250).duration(700).springify()} style={styles.actions}>
          <Button
            title="Create your story"
            variant="gold"
            onPress={() => router.push('/(auth)/register')}
          />
          <Button
            title="I already have an account"
            variant="outline"
            onPress={() => router.push('/(auth)/login')}
          />
        </Animated.View>
      </View>
    </NightSky>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    paddingHorizontal: spacing['2xl'],
    justifyContent: 'space-between',
  },
  hero: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.lg,
  },
  mark: {
    width: 108,
    height: 108,
    marginBottom: spacing.sm,
  },
  headline: {
    fontFamily: fonts.serifSemiBold,
    maxWidth: 320,
  },
  subhead: {
    maxWidth: 320,
  },
  actions: {
    gap: spacing.md,
  },
});
