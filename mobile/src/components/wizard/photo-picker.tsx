import { Ionicons } from '@expo/vector-icons';
import { Image } from 'expo-image';
import { ActionSheetIOS, Alert, Platform, Pressable, StyleSheet, View } from 'react-native';
import { useState } from 'react';

import { Text } from '@/components/ui/text';
import { useMeta } from '@/lib/api/queries';
import { tapFeedback } from '@/lib/haptics';
import { pickPhoto, type PickedPhoto } from '@/lib/images/pick-and-crop';
import { colors, radii, spacing } from '@/theme';

type PhotoPickerProps = {
  previewUri: string | null;
  onPicked: (photo: PickedPhoto) => void;
  onCleared?: () => void;
  size?: number;
  label?: string;
};

/**
 * Photo affordance: tap to choose camera or library, native 3:4 crop, then a
 * framed preview. The photo is repainted by the AI, never printed as-is.
 */
export function PhotoPicker({
  previewUri,
  onPicked,
  onCleared,
  size = 96,
  label = 'Add a photo',
}: PhotoPickerProps) {
  const { data: meta } = useMeta();
  const [busy, setBusy] = useState(false);

  const pickFrom = async (source: 'camera' | 'library') => {
    setBusy(true);

    try {
      const photo = await pickPhoto(source, meta?.photoUploadQuality ?? 'optimized');

      if (photo !== null) {
        onPicked(photo);
      }
    } catch {
      Alert.alert('Photo', 'That photo could not be used. Try a different one.');
    } finally {
      setBusy(false);
    }
  };

  const choose = () => {
    tapFeedback();

    const options = ['Take a photo', 'Choose from library'];
    const withClear = previewUri !== null && onCleared !== undefined;

    if (Platform.OS === 'ios') {
      ActionSheetIOS.showActionSheetWithOptions(
        {
          options: [...options, ...(withClear ? ['Remove photo'] : []), 'Cancel'],
          cancelButtonIndex: withClear ? 3 : 2,
          destructiveButtonIndex: withClear ? 2 : undefined,
        },
        (index) => {
          if (index === 0) {
            void pickFrom('camera');
          } else if (index === 1) {
            void pickFrom('library');
          } else if (withClear && index === 2) {
            onCleared();
          }
        },
      );

      return;
    }

    Alert.alert('Photo', 'How would you like to add the photo?', [
      { text: 'Take a photo', onPress: () => void pickFrom('camera') },
      { text: 'Choose from library', onPress: () => void pickFrom('library') },
      ...(withClear ? [{ text: 'Remove photo', style: 'destructive' as const, onPress: onCleared }] : []),
      { text: 'Cancel', style: 'cancel' as const },
    ]);
  };

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={previewUri === null ? label : 'Change photo'}
      onPress={choose}
      disabled={busy}
      style={[styles.slot, { width: size, height: (size * 4) / 3 }, busy && { opacity: 0.6 }]}
    >
      {previewUri !== null ? (
        <Image source={{ uri: previewUri }} style={styles.preview} contentFit="cover" transition={200} />
      ) : (
        <View style={styles.emptySlot}>
          <Ionicons name="camera" size={22} color={colors.mutedForeground} />
          <Text variant="caption" size="xs" center>
            {label}
          </Text>
        </View>
      )}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  slot: {
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.border,
    borderStyle: 'dashed',
    overflow: 'hidden',
    backgroundColor: colors.whiteAlpha05,
  },
  preview: {
    flex: 1,
  },
  emptySlot: {
    flex: 1,
    alignItems: 'center',
    justifyContent: 'center',
    gap: spacing.xs,
    padding: spacing.sm,
  },
});
