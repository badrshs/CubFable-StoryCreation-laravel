import { Ionicons } from '@expo/vector-icons';
import { useState } from 'react';
import { Modal, Pressable, StyleSheet, View } from 'react-native';

import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import { ArtStyleSwatch } from '@/components/wizard/art-style-swatch';
import type { ArtStyle } from '@/lib/api/types';
import { ART_STYLES } from '@/lib/story-options';
import { colors, radii, spacing } from '@/theme';

type BookMenuSheetProps = {
  visible: boolean;
  currentArtStyle: string;
  busy: boolean;
  onDownload: (variant: 'home' | 'print') => void;
  onRegenerateCover: () => void;
  onRestyle: (artStyle: ArtStyle) => void;
  onClose: () => void;
};

/** The reader's overflow menu: download, cover regeneration, restyle. */
export function BookMenuSheet({
  visible,
  currentArtStyle,
  busy,
  onDownload,
  onRegenerateCover,
  onRestyle,
  onClose,
}: BookMenuSheetProps) {
  const [restyleOpen, setRestyleOpen] = useState(false);
  const [selectedStyle, setSelectedStyle] = useState<ArtStyle | null>(null);

  const close = () => {
    setRestyleOpen(false);
    setSelectedStyle(null);
    onClose();
  };

  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={close}>
      <Pressable style={styles.backdrop} onPress={close} accessibilityLabel="Close" />
      <View style={styles.sheet}>
        <View style={styles.grabber} />

        {restyleOpen ? (
          <>
            <Text variant="title" size="lg">
              Repaint the whole book
            </Text>
            <Text variant="caption">
              The story stays the same; every illustration is repainted in the
              new style. It takes a few minutes.
            </Text>
            <View style={styles.chips}>
              {ART_STYLES.filter((style) => style.value !== currentArtStyle).map((style) => (
                <ArtStyleSwatch
                  key={style.value}
                  style={style.value}
                  label={style.label}
                  gradient={style.swatch}
                  selected={selectedStyle === style.value}
                  onPress={() => setSelectedStyle(style.value)}
                  height={64}
                />
              ))}
            </View>
            <Button
              title="Repaint the book"
              variant="gold"
              loading={busy}
              disabled={selectedStyle === null}
              onPress={() => {
                if (selectedStyle !== null) {
                  onRestyle(selectedStyle);
                }
              }}
            />
            <Button title="Back" variant="ghost" onPress={() => setRestyleOpen(false)} />
          </>
        ) : (
          <>
            <MenuRow icon="download" label="Download PDF (home edition)" onPress={() => onDownload('home')} />
            <MenuRow icon="print" label="Download PDF (print edition)" onPress={() => onDownload('print')} />
            <MenuRow icon="image" label="Repaint the cover" onPress={onRegenerateCover} />
            <MenuRow icon="color-palette" label="Repaint the whole book" onPress={() => setRestyleOpen(true)} />
          </>
        )}
      </View>
    </Modal>
  );
}

function MenuRow({
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
      onPress={onPress}
      style={({ pressed }) => [styles.row, pressed && styles.rowPressed]}
    >
      <Ionicons name={icon} size={20} color={colors.gold} />
      <Text style={styles.rowLabel}>{label}</Text>
      <Ionicons name="chevron-forward" size={16} color={colors.faint} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  backdrop: {
    flex: 1,
    backgroundColor: colors.blackAlpha40,
  },
  sheet: {
    backgroundColor: colors.card,
    borderTopLeftRadius: radii.xl,
    borderTopRightRadius: radii.xl,
    padding: spacing.xl,
    paddingBottom: spacing['3xl'],
    gap: spacing.md,
  },
  grabber: {
    alignSelf: 'center',
    width: 42,
    height: 5,
    borderRadius: 3,
    backgroundColor: colors.border,
  },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: spacing.md,
    paddingVertical: spacing.md,
  },
  rowPressed: {
    opacity: 0.7,
  },
  rowLabel: {
    flex: 1,
  },
  chips: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
  },
});
