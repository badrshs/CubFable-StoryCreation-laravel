export type Lang = {
    code: string;
    label: string; // English label
    native: string; // endonym
    dir: 'ltr' | 'rtl';
};

// Languages the website UI is available in. This list is intentionally broader
// than the set of languages the AI can write stories in (see STORY_LANGUAGES in
// lib/story-options): the UI can be Arabic even when stories are not generated in
// Arabic and fall back to English.
export const LANGUAGES: Lang[] = [
    { code: 'en', label: 'English', native: 'English', dir: 'ltr' },
    { code: 'es', label: 'Spanish', native: 'Español', dir: 'ltr' },
    { code: 'fr', label: 'French', native: 'Français', dir: 'ltr' },
    { code: 'de', label: 'German', native: 'Deutsch', dir: 'ltr' },
    { code: 'it', label: 'Italian', native: 'Italiano', dir: 'ltr' },
    { code: 'pt', label: 'Portuguese', native: 'Português', dir: 'ltr' },
    { code: 'tr', label: 'Turkish', native: 'Türkçe', dir: 'ltr' },
    { code: 'ar', label: 'Arabic', native: 'العربية', dir: 'rtl' },
];

export const DEFAULT_LANG = 'en';

export function langDir(code: string): 'ltr' | 'rtl' {
    return LANGUAGES.find((l) => l.code === code)?.dir ?? 'ltr';
}
