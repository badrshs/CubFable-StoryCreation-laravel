import { router } from 'expo-router';
import { ScrollView, StyleSheet, View } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Input } from '@/components/ui/input';
import { Text } from '@/components/ui/text';
import { ArtStyleSwatch } from '@/components/wizard/art-style-swatch';
import { StepHeader } from '@/components/wizard/step-header';
import { ART_STYLES, FONTS, LESSONS, STORY_LANGUAGES, SUBJECTS } from '@/lib/story-options';
import { useWizard } from '@/lib/wizard-context';
import { colors, spacing } from '@/theme';

export default function SettingsStepScreen() {
  const insets = useSafeAreaInsets();
  const { state, update } = useWizard();

  if (state === null) {
    router.replace('/(tabs)/home');

    return null;
  }

  return (
    <View style={styles.screen}>
      <ScrollView
        contentContainerStyle={[styles.body, { paddingBottom: insets.bottom + 120 }]}
        keyboardShouldPersistTaps="handled"
      >
        <StepHeader step={2} title="Shape the story" caption="Art, language, and the lesson it carries." />

        <View style={styles.section}>
          <View style={styles.field}>
            <Text variant="caption" style={styles.fieldLabel}>
              Art style
            </Text>
            <View style={styles.swatchGrid}>
              {ART_STYLES.map((style) => (
                <ArtStyleSwatch
                  key={style.value}
                  style={style.value}
                  label={style.label}
                  gradient={style.swatch}
                  selected={state.artStyle === style.value}
                  onPress={() => update({ artStyle: style.value })}
                />
              ))}
            </View>
          </View>

          <View style={styles.field}>
            <Text variant="caption" style={styles.fieldLabel}>
              What are they into?
            </Text>
            <View style={styles.chips}>
              {SUBJECTS.map((subject) => (
                <Chip
                  key={subject}
                  label={subject}
                  selected={state.subject === subject}
                  onPress={() => update({ subject })}
                />
              ))}
            </View>
          </View>

          <View style={styles.field}>
            <Text variant="caption" style={styles.fieldLabel}>
              Life lesson
            </Text>
            <View style={styles.chips}>
              {LESSONS.map((lesson) => (
                <Chip
                  key={lesson}
                  label={lesson}
                  selected={state.lifeLesson === lesson}
                  onPress={() => update({ lifeLesson: lesson })}
                />
              ))}
            </View>
          </View>

          <Input
            label="Theme"
            value={state.theme}
            onChangeText={(theme) => update({ theme })}
            placeholder="The world of the story"
          />

          <View style={styles.field}>
            <Text variant="caption" style={styles.fieldLabel}>
              Story language
            </Text>
            <View style={styles.chips}>
              {STORY_LANGUAGES.map((language) => (
                <Chip
                  key={language.code}
                  label={language.native}
                  selected={state.language === language.code}
                  onPress={() => update({ language: language.code })}
                />
              ))}
            </View>
          </View>

          <View style={styles.field}>
            <Text variant="caption" style={styles.fieldLabel}>
              Lettering
            </Text>
            <View style={styles.chips}>
              {FONTS.map((font) => (
                <Chip
                  key={font.value}
                  label={font.label}
                  selected={state.font === font.value}
                  onPress={() => update({ font: font.value })}
                />
              ))}
            </View>
          </View>
        </View>
      </ScrollView>

      <View style={[styles.footer, { paddingBottom: insets.bottom + spacing.lg }]}>
        <Button
          title="Next: the cast"
          variant="gold"
          disabled={state.theme.trim() === '' || state.subject.trim() === '' || state.lifeLesson.trim() === ''}
          onPress={() => router.push('/create/cast')}
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
  swatchGrid: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.md,
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
