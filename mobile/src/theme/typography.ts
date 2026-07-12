import type { StoryLanguage } from '@/lib/api/types';

/**
 * Font roles, mirroring the web: Nunito carries the UI, Cormorant the
 * storybook voice, Baloo 2 the brand. Cairo and Baloo Bhaijaan 2 keep the
 * same warmth for Arabic and Urdu story text.
 */
export const fonts = {
  sans: 'Nunito_400Regular',
  sansSemiBold: 'Nunito_600SemiBold',
  sansBold: 'Nunito_700Bold',
  sansExtraBold: 'Nunito_800ExtraBold',
  serif: 'Cormorant_500Medium',
  serifSemiBold: 'Cormorant_600SemiBold',
  serifItalic: 'Cormorant_500Medium_Italic',
  display: 'Baloo2_600SemiBold',
  displayBold: 'Baloo2_700Bold',
  arabic: 'Cairo_500Medium',
  arabicBold: 'Cairo_700Bold',
  arabicDisplay: 'BalooBhaijaan2_600SemiBold',
} as const;

export const typeScale = {
  xs: { fontSize: 12, lineHeight: 16 },
  sm: { fontSize: 14, lineHeight: 20 },
  base: { fontSize: 16, lineHeight: 23 },
  lg: { fontSize: 18, lineHeight: 26 },
  xl: { fontSize: 22, lineHeight: 29 },
  '2xl': { fontSize: 28, lineHeight: 35 },
  '3xl': { fontSize: 34, lineHeight: 41 },
} as const;

export type TypeSize = keyof typeof typeScale;

const RTL_LANGUAGES: StoryLanguage[] = ['ar', 'ur'];

export function isRtlLanguage(language: string): boolean {
  return RTL_LANGUAGES.includes(language as StoryLanguage);
}

/** The story-text face for a book language (reader + edit sheet). */
export function storyFontFor(language: string, bold = false): string {
  if (isRtlLanguage(language)) {
    return bold ? fonts.arabicBold : fonts.arabic;
  }

  return bold ? fonts.serifSemiBold : fonts.serif;
}
