import type { ArtStyle, StoryLanguage } from '@/types/cubfable';

// Global option lists offered in the wizard (decoupled from individual
// templates). Values are the canonical English strings stored on the book;
// labels are localized in the UI via t("artStyle.*" / "lesson.*" / "subject.*").

export const ART_STYLES: ArtStyle[] = [
    '3d-animation',
    'cartoon',
    'storybook',
    'watercolor',
    'crayon',
    'clay-animation',
    'felt-craft',
    'paper-lightbox',
    'soft-anime',
    'comic-book',
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

// Languages the AI can actually write a story in (labelled with their endonym).
// Deliberately narrower than the UI language list (LANGUAGES in i18n/languages):
// the wizard defaults the story language to the website language when it appears
// here, and falls back to English otherwise.
export const STORY_LANGUAGES: { code: StoryLanguage; native: string }[] = [
    { code: 'en', native: 'English' },
    { code: 'ar', native: 'العربية' },
    { code: 'es', native: 'Español' },
    { code: 'fr', native: 'Français' },
    { code: 'de', native: 'Deutsch' },
    { code: 'it', native: 'Italiano' },
    { code: 'pt', native: 'Português' },
    { code: 'tr', native: 'Türkçe' },
];

export function defaultStoryLanguage(uiLang: string): StoryLanguage {
    return STORY_LANGUAGES.some((l) => l.code === uiLang)
        ? (uiLang as StoryLanguage)
        : 'en';
}

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
