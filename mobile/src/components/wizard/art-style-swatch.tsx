import { Image } from 'expo-image';
import { LinearGradient } from 'expo-linear-gradient';
import { Pressable, StyleSheet, View } from 'react-native';

import { Text } from '@/components/ui/text';
import { apiBaseUrl } from '@/lib/api/client';
import type { ArtStyle } from '@/lib/api/types';
import { tapFeedback } from '@/lib/haptics';
import { colors, fonts, radii, spacing } from '@/theme';

/** The real example art the web style picker uses, served by the app. */
export function artStyleImageUrl(style: ArtStyle): string {
  return `${apiBaseUrl()}/images/art-styles/${style}.jpg`;
}

type ArtStyleSwatchProps = {
  style: ArtStyle;
  label: string;
  gradient: [string, string];
  selected: boolean;
  onPress: () => void;
  height?: number;
};

/**
 * An art-style option showing the style's real example illustration. The
 * brand gradient renders instantly underneath; the image lazy-loads over it
 * (memory + disk cached), so scrolling stays native-smooth on cold starts.
 */
export function ArtStyleSwatch({
  style,
  label,
  gradient,
  selected,
  onPress,
  height = 84,
}: ArtStyleSwatchProps) {
  return (
    <Pressable
      accessibilityRole="button"
      accessibilityState={{ selected }}
      accessibilityLabel={label}
      onPress={() => {
        tapFeedback();
        onPress();
      }}
      style={[styles.swatch, selected && styles.selected]}
    >
      <View style={[styles.art, { height }]}>
        <LinearGradient
          colors={gradient}
          start={{ x: 0, y: 0 }}
          end={{ x: 1, y: 1 }}
          style={StyleSheet.absoluteFill}
        />
        <Image
          source={{ uri: artStyleImageUrl(style) }}
          style={StyleSheet.absoluteFill}
          contentFit="cover"
          transition={300}
          cachePolicy="memory-disk"
          recyclingKey={style}
          accessibilityLabel={`${label} example art`}
        />
      </View>
      <Text
        size="xs"
        center
        style={{
          fontFamily: fonts.sansBold,
          color: selected ? colors.gold : colors.mutedForeground,
        }}
      >
        {label}
      </Text>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  swatch: {
    width: '30%',
    minWidth: 96,
    gap: spacing.sm,
    padding: spacing.sm,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.whiteAlpha05,
  },
  selected: {
    borderColor: colors.gold,
    backgroundColor: colors.goldAlpha15,
  },
  art: {
    borderRadius: radii.sm,
    overflow: 'hidden',
  },
});
