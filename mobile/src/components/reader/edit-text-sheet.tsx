import { useState } from 'react';
import {
  KeyboardAvoidingView,
  Modal,
  Platform,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';

import { Button } from '@/components/ui/button';
import { Text } from '@/components/ui/text';
import type { BookPage, StoryLanguage } from '@/lib/api/types';
import { colors, isRtlLanguage, radii, spacing, storyFontFor } from '@/theme';

type EditTextSheetProps = {
  page: BookPage | null;
  language: StoryLanguage;
  saving: boolean;
  onSave: (pageId: number, text: string) => void;
  onClose: () => void;
};

/** Bottom sheet for editing a page's story text, RTL-aware. */
export function EditTextSheet({ page, language, saving, onSave, onClose }: EditTextSheetProps) {
  const [text, setText] = useState('');
  const rtl = isRtlLanguage(language);

  return (
    <Modal visible={page !== null} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable style={styles.backdrop} onPress={onClose} accessibilityLabel="Close" />
      <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <View style={styles.sheet}>
          <View style={styles.grabber} />
          <Text variant="title" size="lg">
            Edit page {page?.pageNumber}
          </Text>
          <TextInput
            defaultValue={page?.text ?? ''}
            onChangeText={setText}
            multiline
            autoFocus
            selectionColor={colors.gold}
            style={[
              styles.input,
              {
                fontFamily: storyFontFor(language),
                textAlign: rtl ? 'right' : 'left',
                writingDirection: rtl ? 'rtl' : 'ltr',
              },
            ]}
          />
          <Button
            title="Save changes"
            variant="gold"
            loading={saving}
            onPress={() => {
              if (page !== null) {
                onSave(page.id, text !== '' ? text : page.text);
              }
            }}
          />
        </View>
      </KeyboardAvoidingView>
    </Modal>
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
    gap: spacing.lg,
  },
  grabber: {
    alignSelf: 'center',
    width: 42,
    height: 5,
    borderRadius: 3,
    backgroundColor: colors.border,
  },
  input: {
    minHeight: 140,
    maxHeight: 260,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: colors.border,
    backgroundColor: colors.bg,
    color: colors.foreground,
    padding: spacing.lg,
    fontSize: 19,
    lineHeight: 30,
    textAlignVertical: 'top',
  },
});
