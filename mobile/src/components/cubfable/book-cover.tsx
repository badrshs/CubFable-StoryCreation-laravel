import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/text';
import { ART_ASPECT_RATIO, colors, fonts, frameGradient, radii, spacing } from '@/theme';

type BookCoverProps = {
  imageUrl: string | null;
  title: string;
  width: number;
  framed?: boolean;
};

/**
 * A 3:4 book cover in the brand's gold-inset indigo frame. Without art yet
 * (drafts, templates missing covers) it shows a quiet night placeholder with
 * the title set in the storybook serif.
 */
export function BookCover({ imageUrl, title, width, framed = true }: BookCoverProps) {
  const height = width / ART_ASPECT_RATIO;

  const art = imageUrl ? (
    <Image
      source={{ uri: imageUrl }}
      style={styles.art}
      contentFit="cover"
      transition={250}
      accessibilityLabel={`Cover of ${title}`}
    />
  ) : (
    <View style={styles.placeholder}>
      <Text style={styles.placeholderMoon}>🌙</Text>
      <Text
        numberOfLines={3}
        center
        style={{ fontFamily: fonts.serifSemiBold, fontSize: Math.max(14, width / 11), color: colors.foreground }}
      >
        {title}
      </Text>
    </View>
  );

  if (!framed) {
    return <View style={[styles.bare, { width, height }]}>{art}</View>;
  }

  return (
    <LinearGradient
      colors={frameGradient}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={[styles.frame, { width, height }]}
    >
      <View style={styles.ring}>{art}</View>
    </LinearGradient>
  );
}

const FRAME_PADDING = 5;

const styles = StyleSheet.create({
  frame: {
    borderRadius: radii.md,
    padding: FRAME_PADDING,
  },
  ring: {
    flex: 1,
    borderRadius: radii.md - FRAME_PADDING,
    borderWidth: 1,
    borderColor: colors.frameRing,
    overflow: 'hidden',
  },
  bare: {
    borderRadius: radii.md,
    overflow: 'hidden',
  },
  art: {
    flex: 1,
    backgroundColor: colors.artMat,
  },
  placeholder: {
    flex: 1,
    backgroundColor: colors.artMat,
    alignItems: 'center',
    justifyContent: 'center',
    padding: spacing.md,
    gap: spacing.sm,
  },
  placeholderMoon: {
    fontSize: 22,
  },
});
