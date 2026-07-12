import { Ionicons } from '@expo/vector-icons';
import { router, useLocalSearchParams } from 'expo-router';
import { Pressable, ScrollView, StyleSheet, View, useWindowDimensions } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { BookCover } from '@/components/cubfable/book-cover';
import { Button } from '@/components/ui/button';
import { Chip } from '@/components/ui/chip';
import { Skeleton } from '@/components/ui/skeleton';
import { Text } from '@/components/ui/text';
import { useTemplates } from '@/lib/api/queries';
import { colors, shadows, spacing } from '@/theme';

export default function TemplateDetailScreen() {
  const { id } = useLocalSearchParams<{ id: string }>();
  const insets = useSafeAreaInsets();
  const { width } = useWindowDimensions();
  const { data, isPending } = useTemplates();

  const template = data?.templates.find((item) => item.id === Number(id));
  const coverWidth = Math.min(width * 0.56, 260);

  return (
    <View style={styles.screen}>
      <ScrollView
        contentContainerStyle={[
          styles.content,
          { paddingTop: insets.top + spacing.lg, paddingBottom: insets.bottom + 120 },
        ]}
      >
        <Pressable
          accessibilityRole="button"
          accessibilityLabel="Back"
          onPress={() => router.back()}
          style={styles.back}
        >
          <Ionicons name="chevron-back" size={22} color={colors.foreground} />
        </Pressable>

        {isPending && <Skeleton style={{ width: coverWidth, height: coverWidth / 0.75, alignSelf: 'center' }} />}

        {template !== undefined && (
          <>
            <View style={[styles.coverWrap, shadows.goldGlow]}>
              <BookCover imageUrl={template.coverImageUrl} title={template.title} width={coverWidth} />
            </View>

            <View style={styles.meta}>
              <Text variant="title" center>
                {template.title}
              </Text>
              <View style={styles.badges}>
                <Chip label={`Ages ${template.ageMin} to ${template.ageMax}`} />
                <Chip label={`${template.pageCount} pages`} />
              </View>
            </View>

            <Text color={colors.mutedForeground} style={styles.description}>
              {template.description}
            </Text>

            {template.lifeLessons.length > 0 && (
              <View style={styles.lessons}>
                <Text variant="caption">Life lessons this story can carry</Text>
                <View style={styles.badges}>
                  {template.lifeLessons.map((lesson) => (
                    <Chip key={lesson} label={lesson} />
                  ))}
                </View>
              </View>
            )}
          </>
        )}
      </ScrollView>

      {template !== undefined && (
        <View style={[styles.cta, { paddingBottom: insets.bottom + spacing.lg }]}>
          <Button
            title="Make your child the hero"
            variant="gold"
            onPress={() =>
              router.push({ pathname: '/create/hero', params: { templateId: String(template.id) } })
            }
          />
        </View>
      )}
    </View>
  );
}

const styles = StyleSheet.create({
  screen: {
    flex: 1,
    backgroundColor: colors.bg,
  },
  content: {
    paddingHorizontal: spacing.xl,
    gap: spacing.xl,
  },
  back: {
    width: 40,
    height: 40,
    borderRadius: 20,
    backgroundColor: colors.whiteAlpha10,
    alignItems: 'center',
    justifyContent: 'center',
  },
  coverWrap: {
    alignSelf: 'center',
  },
  meta: {
    gap: spacing.md,
    alignItems: 'center',
  },
  badges: {
    flexDirection: 'row',
    flexWrap: 'wrap',
    gap: spacing.sm,
    justifyContent: 'center',
  },
  description: {
    fontSize: 16,
    lineHeight: 25,
  },
  lessons: {
    gap: spacing.md,
    alignItems: 'center',
  },
  cta: {
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
