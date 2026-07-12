import { Image } from 'expo-image';
import { router, useLocalSearchParams } from 'expo-router';
import { useEffect } from 'react';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { PhotoPicker } from '@/components/wizard/photo-picker';
import { StepHeader } from '@/components/wizard/step-header';
import { useBook, useCharacters, useTemplates } from '@/lib/api/queries';
import { tapFeedback } from '@/lib/haptics';
import { AGE_RANGES } from '@/lib/story-options';
import { newCastKey, useWizard } from '@/lib/wizard-context';
import { colors, radii, spacing } from '@/theme';

export default function HeroStepScreen() {
  const { templateId, bookId } = useLocalSearchParams<{ templateId?: string; bookId?: string }>();
  const insets = useSafeAreaInsets();
  const { state, startFromTemplate, startFromDraft, update } = useWizard();
  const { data: templatesData } = useTemplates();
  const { data: savedCharacters } = useCharacters();
  const editingBook = useBook(bookId !== undefined ? Number(bookId) : 0);

  // Initialize the wizard once its inputs load: from a template for a new
  // book, or from the draft being edited.
  useEffect(() => {
    if (state !== null || templatesData === undefined) {
      return;
    }

    if (bookId !== undefined) {
      const book = editingBook.data;

      if (book === undefined) {
        return;
      }

      const template =
        templatesData.templates.find((item) => item.theme === book.theme) ?? templatesData.templates[0];

      if (template !== undefined) {
        startFromDraft(template, book);
      }

      return;
    }

    const template = templatesData.templates.find((item) => item.id === Number(templateId));

    if (template !== undefined) {
      startFromTemplate(template);
    }
  }, [state, templatesData, templateId, bookId, editingBook.data, startFromDraft, startFromTemplate]);

  if (state === null) {
    return (
      <View style={styles.screen}>
        <StepHeader step={1} title="Your little hero" caption="Opening the story..." />
        <View style={styles.section}>
          <Skeleton style={{ height: 120 }} />
        </View>
      </View>
    );
  }

  const { hero } = state;
  const heroReady = hero.name.trim() !== '';

  return (
    <View style={styles.screen}>
      <ScrollView
        contentContainerStyle={[styles.body, { paddingBottom: insets.bottom + 120 }]}
        keyboardShouldPersistTaps="handled"
      >
        <StepHeader step={1} title="Your little hero" caption={`Who stars in "${state.template.title}"?`} />

        <View style={styles.section}>
          <Input
            label="Child's name"
            value={hero.name}
            onChangeText={(name) => update({ hero: { ...hero, name } })}
            placeholder="This becomes the cover title"
          />

          <View style={styles.field}>
            <Text variant="caption" style={styles.fieldLabel}>
              Age
            </Text>
            <View style={styles.chips}>
              {AGE_RANGES.map((range) => (
                <Chip
                  key={range.value}
                  label={range.label}
                  selected={state.ageRange === range.value}
                  onPress={() => update({ ageRange: range.value })}
                />
              ))}
            </View>
          </View>

          <View style={styles.field}>
            <Text variant="caption" style={styles.fieldLabel}>
              Photo (optional, recommended)
            </Text>
            <View style={styles.photoRow}>
              <PhotoPicker
                previewUri={hero.photoPreviewUri}
                onPicked={(photo) =>
                  update({ hero: { ...hero, photoDataUrl: photo.dataUrl, photoPreviewUri: photo.previewUri } })
                }
                onCleared={() => update({ hero: { ...hero, photoDataUrl: null, photoPreviewUri: null } })}
                label="Add their photo"
              />
              <Text variant="caption" style={styles.photoHint}>
                The art is painted from the photo so your child is recognizably
                the hero. The photo itself never appears in the book.
              </Text>
            </View>
          </View>

          {(savedCharacters?.length ?? 0) > 0 && (
            <View style={styles.field}>
              <Text variant="caption" style={styles.fieldLabel}>
                Or pick a saved character
              </Text>
              <ScrollView
                horizontal
                showsHorizontalScrollIndicator={false}
                contentContainerStyle={styles.savedRow}
              >
                {savedCharacters!.map((character) => {
                  const selected = hero.characterId === character.id;

                  return (
                    <Pressable
                      key={character.id}
                      accessibilityRole="button"
                      accessibilityLabel={character.name}
                      onPress={() => {
                        tapFeedback();
                        update({
                          hero: {
                            key: newCastKey(),
                            characterId: character.id,
                            name: character.name,
                            role: character.role ?? '',
                            ageGroup: character.ageGroup ?? 'child',
                            description: character.description ?? '',
                            photoDataUrl: null,
                            photoPreviewUri: character.photoUrl,
                          },
                        });
                      }}
                      style={[styles.savedCard, selected && styles.savedCardSelected]}
                    >
                      {character.photoUrl !== null ? (
                        <Image source={{ uri: character.photoUrl }} style={styles.savedPhoto} contentFit="cover" />
                      ) : (
                        <View style={[styles.savedPhoto, styles.savedInitial]}>
                          <Text variant="display" size="xl">
                            {character.name.charAt(0).toUpperCase()}
                          </Text>
                        </View>
                      )}
                      <Text variant="caption" size="xs" numberOfLines={1}>
                        {character.name}
                      </Text>
                    </Pressable>
                  );
                })}
              </ScrollView>
            </View>
          )}
        </View>
      </ScrollView>

      <View style={[styles.footer, { paddingBottom: insets.bottom + spacing.lg }]}>
        <Button
          title="Next: story settings"
          variant="gold"
          disabled={!heroReady}
          onPress={() => router.push('/create/settings')}
        />
      </View>
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
    gap: spacing.xl,
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
    flexWrap: 'wrap',
    gap: spacing.sm,
  },
  photoRow: {
    flexDirection: 'row',
    gap: spacing.lg,
    alignItems: 'center',
  },
  photoHint: {
    flex: 1,
  },
  savedRow: {
    gap: spacing.md,
  },
  savedCard: {
    width: 76,
    gap: spacing.xs,
    alignItems: 'center',
    padding: spacing.xs,
    borderRadius: radii.md,
    borderWidth: 1,
    borderColor: 'transparent',
  },
  savedCardSelected: {
    borderColor: colors.gold,
    backgroundColor: colors.goldAlpha15,
  },
  savedPhoto: {
    width: 64,
    height: 64,
    borderRadius: 32,
    backgroundColor: colors.cardRaised,
  },
  savedInitial: {
    alignItems: 'center',
    justifyContent: 'center',
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
