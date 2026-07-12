import { useState } from 'react';
import { Alert, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Input } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { PhotoPicker } from '@/components/wizard/photo-picker';
import { fieldError, isApiError } from '@/lib/api/client';
import type { Character, CharacterInput } from '@/lib/api/types';
import { colors, spacing } from '@/theme';

type CharacterFormProps = {
  title: string;
  initial?: Character;
  submitLabel: string;
  onSubmit: (input: CharacterInput) => Promise<void>;
  onDelete?: () => Promise<void>;
};

/** Shared create/edit character form, presented as a modal sheet. */
export function CharacterForm({ title, initial, submitLabel, onSubmit, onDelete }: CharacterFormProps) {
  const insets = useSafeAreaInsets();

  const [name, setName] = useState(initial?.name ?? '');
  const [role, setRole] = useState(initial?.role ?? '');
  const [ageGroup, setAgeGroup] = useState<'adult' | 'child'>(initial?.ageGroup ?? 'adult');
  const [description, setDescription] = useState(initial?.description ?? '');
  const [photoDataUrl, setPhotoDataUrl] = useState<string | null>(null);
  const [photoPreviewUri, setPhotoPreviewUri] = useState<string | null>(initial?.photoUrl ?? null);
  const [photoCleared, setPhotoCleared] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [nameError, setNameError] = useState<string | null>(null);

  const submit = async () => {
    setSubmitting(true);
    setNameError(null);

    try {
      await onSubmit({
        name: name.trim(),
        role: role.trim() === '' ? null : role.trim(),
        ageGroup,
        description: description.trim() === '' ? null : description.trim(),
        // A fresh photo uploads, an explicit clear sends null, otherwise the
        // stored photo is left untouched (PATCH semantics).
        ...(photoDataUrl !== null
          ? { photoUrl: photoDataUrl }
          : photoCleared
            ? { photoUrl: null }
            : {}),
      });
    } catch (cause) {
      setNameError(fieldError(cause, 'name'));

      if (fieldError(cause, 'name') === null) {
        Alert.alert(
          'Could not save',
          isApiError(cause) ? cause.message : 'Something went wrong. Please try again.',
        );
      }

      setSubmitting(false);
    }
  };

  return (
    <View style={styles.screen}>
      <ScrollView
        contentContainerStyle={[styles.body, { paddingBottom: insets.bottom + spacing['3xl'] }]}
        keyboardShouldPersistTaps="handled"
      >
        <Text variant="title" style={styles.title}>
          {title}
        </Text>

        <View style={styles.photoRow}>
          <PhotoPicker
            previewUri={photoPreviewUri}
            onPicked={(photo) => {
              setPhotoDataUrl(photo.dataUrl);
              setPhotoPreviewUri(photo.previewUri);
              setPhotoCleared(false);
            }}
            onCleared={() => {
              setPhotoDataUrl(null);
              setPhotoPreviewUri(null);
              setPhotoCleared(true);
            }}
          />
          <Text variant="caption" style={styles.photoHint}>
            A photo helps the AI paint this character consistently in every
            book they appear in.
          </Text>
        </View>

        <Input label="Name" value={name} onChangeText={setName} placeholder="Grandma Rose" error={nameError} />
        <Input label="Relation" value={role} onChangeText={setRole} placeholder="grandmother, best friend..." />

        <View style={styles.field}>
          <Text variant="caption" style={styles.fieldLabel}>
            Age group
          </Text>
          <View style={styles.chips}>
            <Chip label="Adult" selected={ageGroup === 'adult'} onPress={() => setAgeGroup('adult')} />
            <Chip label="Child" selected={ageGroup === 'child'} onPress={() => setAgeGroup('child')} />
          </View>
        </View>

        <Input
          label="Description (optional)"
          value={description}
          onChangeText={setDescription}
          placeholder="Curly grey hair, round glasses, warm smile..."
          multiline
          style={styles.multiline}
        />

        <Button
          title={submitLabel}
          variant="gold"
          loading={submitting}
          disabled={name.trim() === ''}
          onPress={() => void submit()}
        />

        {onDelete !== undefined && (
          <Button
            title="Delete character"
            variant="ghost"
            onPress={() =>
              Alert.alert('Delete character', 'Books already made with them keep their art.', [
                { text: 'Cancel', style: 'cancel' },
                { text: 'Delete', style: 'destructive', onPress: () => void onDelete() },
              ])
            }
          />
        )}
      </ScrollView>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  body: {
    padding: spacing.xl,
    gap: spacing.lg,
  },
  title: {
    marginBottom: spacing.sm,
  },
  photoRow: {
    flexDirection: 'row',
    gap: spacing.lg,
    alignItems: 'center',
  },
  photoHint: {
    flex: 1,
  },
  field: {
    gap: spacing.sm,
  },
  fieldLabel: {
    marginLeft: spacing.xs,
    textTransform: 'uppercase',
    letterSpacing: 1.2,
    fontSize: 11,
  },
  chips: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  multiline: {
    minHeight: 96,
    paddingTop: spacing.md,
    textAlignVertical: 'top',
  },
});
