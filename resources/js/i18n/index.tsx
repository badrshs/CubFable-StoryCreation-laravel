import type { ReactNode } from 'react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useState,
} from 'react';
import { LANGUAGES, DEFAULT_LANG, langDir } from './languages';

// Load every locale file in ./locales/*.json at build time. Adding a new locale
// file is automatically picked up; missing keys fall back to English.
const modules = import.meta.glob('./locales/*.json', {
    eager: true,
    import: 'default',
});
const dicts: Record<string, Record<string, string>> = {};

for (const [path, mod] of Object.entries(modules)) {
    const code = path.split('/').pop()!.replace('.json', '');
    dicts[code] = mod as Record<string, string>;
}

type Vars = Record<string, string | number>;
type I18nContextValue = {
    lang: string;
    dir: 'ltr' | 'rtl';
    setLang: (code: string) => void;
    t: (key: string, vars?: Vars) => string;
    // Translate dynamic content (e.g. template titles, life lessons) by key,
    // falling back to the given English value when no translation exists.
    tc: (key: string, fallback: string) => string;
};

// Turn a content value into a stable key segment, e.g. "Respect for nature" -> "respect_for_nature".
export function slugify(value: string): string {
    return value
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_|_$/g, '');
}

const I18nContext = createContext<I18nContextValue | null>(null);
const STORAGE_KEY = 'sw_lang';

function interpolate(template: string, vars?: Vars): string {
    if (!vars) {
        return template;
    }

    return template.replace(/\{(\w+)\}/g, (_, k) =>
        vars[k] != null ? String(vars[k]) : `{${k}}`,
    );
}

export function LanguageProvider({ children }: { children: ReactNode }) {
    const [lang, setLangState] = useState<string>(() => {
        if (typeof localStorage !== 'undefined') {
            const saved = localStorage.getItem(STORAGE_KEY);

            if (saved && LANGUAGES.some((l) => l.code === saved)) {
                return saved;
            }
        }

        return DEFAULT_LANG;
    });

    const dir = langDir(lang);

    useEffect(() => {
        document.documentElement.lang = lang;
        document.documentElement.dir = dir;
        // The app translates itself; block external page translators (e.g. Chrome's
        // Google Translate), which mutate text nodes and crash React reconciliation.
        document.documentElement.translate = false;
    }, [lang, dir]);

    const setLang = useCallback((code: string) => {
        setLangState(code);

        try {
            localStorage.setItem(STORAGE_KEY, code);
        } catch {
            /* ignore */
        }
    }, []);

    const t = useCallback(
        (key: string, vars?: Vars) => {
            const value =
                dicts[lang]?.[key] ?? dicts[DEFAULT_LANG]?.[key] ?? key;

            return interpolate(value, vars);
        },
        [lang],
    );

    const tc = useCallback(
        (key: string, fallback: string) => {
            const value = dicts[lang]?.[key] ?? dicts[DEFAULT_LANG]?.[key];

            return value != null ? value : fallback;
        },
        [lang],
    );

    return (
        <I18nContext.Provider value={{ lang, dir, setLang, t, tc }}>
            {children}
        </I18nContext.Provider>
    );
}

export function useI18n(): I18nContextValue {
    const ctx = useContext(I18nContext);

    if (!ctx) {
        throw new Error('useI18n must be used within a LanguageProvider');
    }

    return ctx;
}

// Convenience hook returning just the translate function.
export function useT(): (key: string, vars?: Vars) => string {
    return useI18n().t;
}

export { LANGUAGES, DEFAULT_LANG } from './languages';
