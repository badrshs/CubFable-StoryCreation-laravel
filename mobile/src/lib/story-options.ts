import type { AgeRange, ArtStyle, BookFont, StoryLanguage } from '@/lib/api/types';

// Wizard option lists, copied from the web (resources/js/lib/story-options.ts)
// with the display labels the web keeps in its i18n catalog.

export const ART_STYLES: { value: ArtStyle; label: string; swatch: [string, string] }[] = [
  { value: '3d-animation', label: '3D animation', swatch: ['#7C6FF0', '#4A3ECC'] },
  { value: 'cartoon', label: 'Cartoon', swatch: ['#FFB347', '#F0755F'] },
  { value: 'storybook', label: 'Storybook', swatch: ['#E8B94F', '#B07C33'] },
  { value: 'watercolor', label: 'Watercolor', swatch: ['#8FBFE8', '#5E8FC7'] },
  { value: 'soft-anime', label: 'Soft anime', swatch: ['#F2A2C4', '#B36FC9'] },
  { value: 'comic-book', label: 'Comic book', swatch: ['#F55F5F', '#2E4FBF'] },
];

export const LESSONS: string[] = [
  'Friendship',
  'Courage',
  'Care for Nature',
  'Love',
  'Perseverance',
  'Sharing',
  'Honesty',
  'Respect',
];

export const SUBJECTS: string[] = [
  'Garbage truck',
  'Construction machinery',
  'Airplane',
  'Racing',
  'Fire Department',
  'Police',
  'Dinosaurs',
  'Pirates',
  'Superhero',
  'Camping',
  'Travel',
  'Treasure Hunts',
  'Secret Missions',
  'Haunted House',
  'Time Travel',
];

export const STORY_LANGUAGES: { code: StoryLanguage; native: string; rtl: boolean }[] = [
  { code: 'en', native: 'English', rtl: false },
  { code: 'ar', native: 'العربية', rtl: true },
  { code: 'es', native: 'Español', rtl: false },
  { code: 'fr', native: 'Français', rtl: false },
  { code: 'de', native: 'Deutsch', rtl: false },
  { code: 'it', native: 'Italiano', rtl: false },
  { code: 'pt', native: 'Português', rtl: false },
  { code: 'tr', native: 'Türkçe', rtl: false },
];

export const AGE_RANGES: { value: AgeRange; label: string }[] = [
  { value: '2-4', label: '2 to 4' },
  { value: '4-6', label: '4 to 6' },
  { value: '6-8', label: '6 to 8' },
  { value: '8-10', label: '8 to 10' },
];

export const FONTS: { value: BookFont; label: string }[] = [
  { value: 'classic', label: 'Classic' },
  { value: 'playful', label: 'Playful' },
  { value: 'handwritten', label: 'Handwritten' },
];

export function artStyleLabel(value: string): string {
  return ART_STYLES.find((style) => style.value === value)?.label ?? value;
}
