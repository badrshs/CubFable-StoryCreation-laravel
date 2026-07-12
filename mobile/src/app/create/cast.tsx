import { Ionicons } from '@expo/vector-icons';
import { router } from 'expo-router';
import { useState } from 'react';
import { Alert, Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Chip } from '@/components/ui/chip';
import { Input } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { PhotoPicker } from '@/components/wizard/photo-picker';
import { StepHeader } from '@/components/wizard/step-header';
import { isApiError } from '@/lib/api/client';
import { useCreateBook, useUpdateBook } from '@/lib/api/mutations';
import { successFeedback } from '@/lib/haptics';
import { artStyleLabel } from '@/lib/story-options';
import { emptyCastMember, useWizard, type WizardCastMember } from '@/lib/wizard-context';
import { colors, spacing } from '@/theme';

const MAX_SUPPORTING_CAST = 5;

export default function CastStepScreen() {
  const insets = useSafeAreaInsets();
  const { state, update, buildPayload, reset } = useWizard();
  const createBook = useCreateBook();
  const updateBook = useUpdateBook(state?.bookId ?? 0);
  const [submitting, setSubmitting] = useState(false);

  if (state === null) {
    router.replace('/(tabs)/home');

    return null;
  }

  const patchMember = (key: string, patch: Partial<WizardCastMember>) => {
    update({
      cast: state.cast.map((member) => (member.key === key ? { ...member, ...patch } : member)),
    });
  };

  const submit = async () => {
    setSubmitting(true);

    try {
      const payload = buildPayload();

      const book =
        state.bookId !== null
          ? await updateBook.mutateAsync({ ...payload })
          : await createBook.mutateAsync(payload);

      successFeedback();
      reset();
      router.dismissAll();
      router.replace({ pathname: '/book/[id]/paywall', params: { id: String(book.id) } });
    } catch (cause) {
      Alert.alert(
        'Could not save the story',
        isApiError(cause) ? cause.message : 'Something went wrong. Please try again.',
      );
      setSubmitting(false);
    }
  };

  return (
    <View style={styles.screen}>
      <ScrollView
        contentContainerStyle={[styles.body, { paddingBottom: insets.bottom + 130 }]}
        keyboardShouldPersistTaps="handled"
      >
        <StepHeader step={3} title="The cast" caption="Family and friends who join the adventure (optional)." />

        <View style={styles.section}>
          {state.cast.map((member, index) => (
            <Card key={member.key} style={styles.castCard}>
              <View style={styles.castHeader}>
                <Text variant="label">Cast member {index + 1}</Text>
                <Pressable
                  accessibilityRole="button"
                  accessibilityLabel="Remove cast member"
                  onPress={() => update({ cast: state.cast.filter((item) => item.key !== member.key) })}
                >
                  <Ionicons name="close-circle" size={22} color={colors.faint} />
                </Pressable>
              </View>
              <View style={styles.castRow}>
                <PhotoPicker
                  size={72}
                  label="Photo"
                  previewUri={member.photoPreviewUri}
                  onPicked={(photo) =>
                    patchMember(member.key, { photoDataUrl: photo.dataUrl, photoPreviewUri: photo.previewUri })
                  }
                  onCleared={() => patchMember(member.key, { photoDataUrl: null, photoPreviewUri: null })}
                />
                <View style={styles.castFields}>
                  <Input
                    label="Name"
                    value={member.name}
                    onChangeText={(name) => patchMember(member.key, { name })}
                    placeholder="Grandma Rose"
                  />
                  <Input
                    label="Relation"
                    value={member.role}
                    onChangeText={(role) => patchMember(member.key, { role })}
                    placeholder="grandmother, best friend..."
                  />
                </View>
              </View>
              <View style={styles.chips}>
                <Chip
                  label="Adult"
                  selected={member.ageGroup === 'adult'}
                  onPress={() => patchMember(member.key, { ageGroup: 'adult' })}
                />
                <Chip
                  label="Child"
                  selected={member.ageGroup === 'child'}
                  onPress={() => patchMember(member.key, { ageGroup: 'child' })}
                />
              </View>
            </Card>
          ))}

          {state.cast.length < MAX_SUPPORTING_CAST && (
            <Button
              title="Add a cast member"
              variant="outline"
              icon={<Ionicons name="person-add" size={18} color={colors.foreground} />}
              onPress={() => update({ cast: [...state.cast, emptyCastMember('adult')] })}
            />
          )}

          <Card style={styles.review}>
            <Text variant="title" size="lg">
              Ready for the printers?
            </Text>
            <ReviewRow label="Story" value={state.template.title} />
            <ReviewRow label="Hero" value={`${state.hero.name.trim()} (ages ${state.ageRange})`} />
            <ReviewRow label="Art style" value={artStyleLabel(state.artStyle)} />
            <ReviewRow label="Lesson" value={state.lifeLesson} />
            <ReviewRow
              label="Cast"
              value={
                state.cast.filter((member) => member.name.trim() !== '').length === 0
                  ? `Just ${state.hero.name.trim()}`
                  : `${state.hero.name.trim()} + ${state.cast.filter((member) => member.name.trim() !== '').length}`
              }
            />
            <Text variant="caption">
              Next you unlock the book; the story and art are made right after.
            </Text>
          </Card>
        </View>
      </ScrollView>

      <View style={[styles.footer, { paddingBottom: insets.bottom + spacing.lg }]}>
        <Button
          title={state.bookId !== null ? 'Save and continue' : 'Continue to unlock'}
          variant="gold"
          loading={submitting}
          onPress={() => void submit()}
        />
      </View>
    </View>
  );
}

function ReviewRow({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.reviewRow}>
      <Text variant="caption">{label}</Text>
      <Text variant="label" size="sm" style={styles.reviewValue} numberOfLines={1}>
        {value}
      </Text>
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  body: {
    gap: spacing.lg,
  },
  section: {
    paddingHorizontal: spacing.xl,
    gap: spacing.lg,
  },
  castCard: {
    gap: spacing.md,
  },
  castHeader: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  castRow: {
    flexDirection: 'row',
    gap: spacing.md,
  },
  castFields: {
    flex: 1,
    gap: spacing.md,
  },
  chips: {
    flexDirection: 'row',
    gap: spacing.sm,
  },
  review: {
    gap: spacing.md,
  },
  reviewRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    gap: spacing.lg,
  },
  reviewValue: {
    flexShrink: 1,
  },
  footer: {
    position: 'absolute',
    left: 0,
    right: 0,
    bottom: 0,
    paddingHorizontal: spacing.xl,
    paddingTop: spacing.md,
    backgroundColor: colors.bgDeep,
    borderTopWidth: StyleSheet.hairlineWidth,
    borderTopColor: colors.borderSoft,
  },
});
