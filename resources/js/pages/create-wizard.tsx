import { router } from '@inertiajs/react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import {
    ArrowLeft,
    ArrowRight,
    BookHeart,
    Check,
    Loader2,
    Palette,
    Plus,
    Sparkles,
    Trash2,
    Type,
    Upload,
    Users,
    Wand2,
} from 'lucide-react';
import { useState } from 'react';
import Starfield from '@/components/cubfable/starfield';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { slugify, useI18n, useT } from '@/i18n';
import { downscaleImage } from '@/lib/downscale-image';
import {
    ART_STYLES,
    LESSONS,
    STORY_LANGUAGES,
    SUBJECTS,
    defaultStoryLanguage,
} from '@/lib/story-options';
import { store, update } from '@/routes/books';
import type {
    AgeRange,
    ArtStyle,
    Book,
    BookFont,
    Character,
    StoryLanguage,
    Template,
} from '@/types';

type CastRow = {
    characterId: number | null;
    name: string;
    relation: string;
    description: string;
    photoUrl: string | null;
};

const emptyRow = (): CastRow => ({
    characterId: null,
    name: '',
    relation: '',
    description: '',
    photoUrl: null,
});

// Each art style rendered as a swatch: a small gradient "chip" gives the tile a
// distinct feel without needing bundled artwork. Twilight-palette only.
const ART_STYLE_SWATCHES: Record<string, string> = {
    '3d-animation':
        'linear-gradient(135deg, hsl(249 72% 60%), hsl(199 82% 62%))',
    watercolor: 'linear-gradient(135deg, hsl(199 74% 70%), hsl(7 84% 78%))',
    geometric: 'linear-gradient(135deg, hsl(266 62% 62%), hsl(42 92% 64%))',
    'clay-animation':
        'linear-gradient(135deg, hsl(7 84% 74%), hsl(32 82% 66%))',
    'sticker-art': 'linear-gradient(135deg, hsl(42 95% 66%), hsl(320 62% 70%))',
    'comic-book': 'linear-gradient(135deg, hsl(220 70% 58%), hsl(42 95% 66%))',
    gouache: 'linear-gradient(135deg, hsl(160 46% 58%), hsl(199 74% 66%))',
    'soft-anime': 'linear-gradient(135deg, hsl(280 60% 74%), hsl(199 80% 74%))',
    'block-world': 'linear-gradient(135deg, hsl(140 42% 56%), hsl(42 92% 62%))',
    collage: 'linear-gradient(135deg, hsl(320 58% 68%), hsl(42 92% 64%))',
};

const STEP_META = [
    { key: 'hero', icon: BookHeart },
    { key: 'settings', icon: Palette },
    { key: 'cast', icon: Users },
] as const;

// Which wizard step owns each server-side validation error key, so a failed
// submit can jump back to the offending step.
const STEP_FOR_FIELD: Record<string, number> = {
    childName: 1,
    ageRange: 1,
    theme: 2,
    subject: 2,
    lifeLesson: 2,
    artStyle: 2,
    font: 2,
    language: 2,
    templateId: 1,
};

function stepForErrorKey(key: string): number {
    // The hero is submitted as the first cast entry.
    if (key.startsWith('characters.0.')) {
        return 1;
    }

    if (key.startsWith('characters')) {
        return 3;
    }

    return STEP_FOR_FIELD[key] ?? 1;
}

// When editing an unpaid draft the wizard receives the book with its cast
// (hero flagged isMain) and initializes every control from it.
type EditableBook = Book & {
    characters: (Character & { isMain?: boolean })[];
};

type CreateWizardProps = {
    template: Template;
    savedCharacters: Character[];
    book?: EditableBook;
};

// Only freshly uploaded photos travel as data URLs; an existing photo comes
// back from the server as a /storage URL and must not be re-submitted (the
// server keeps the stored file when photoUrl is absent).
function submittablePhoto(value: string | null): string | undefined {
    return value !== null && value.startsWith('data:') ? value : undefined;
}

export default function CreateWizard({
    template,
    savedCharacters,
    book,
}: CreateWizardProps) {
    const t = useT();
    const { lang, tc } = useI18n();
    const reduceMotion = useReducedMotion();

    const editing = book !== undefined;
    const heroFromBook =
        book?.characters.find((c) => c.isMain) ?? book?.characters[0];
    const supportingFromBook =
        book?.characters.filter((c) => c !== heroFromBook) ?? [];

    const [step, setStep] = useState(1);
    const totalSteps = 3;

    // Hero (the main character)
    const [photoPreview, setPhotoPreview] = useState<string | null>(
        heroFromBook?.photoUrl ?? null,
    );
    const [childName, setChildName] = useState(book?.childName ?? '');
    const [heroCharacterId, setHeroCharacterId] = useState<number | null>(
        heroFromBook?.id ?? null,
    );

    const [ageRange, setAgeRange] = useState<AgeRange>(book?.ageRange ?? '4-6');
    const [theme, setTheme] = useState(book?.theme ?? '');
    const [subject, setSubject] = useState(book?.subject ?? '');
    const [lifeLesson, setLifeLesson] = useState(book?.lifeLesson ?? '');
    const [artStyle, setArtStyle] = useState<ArtStyle>(
        (book?.artStyle as ArtStyle) ?? 'watercolor',
    );
    const [font, setFont] = useState<BookFont>(
        (book?.font as BookFont) ?? 'classic',
    );
    // Story language defaults to the website language when the AI supports it,
    // otherwise English (e.g. an Arabic UI still produces an English story).
    const [storyLang, setStoryLang] = useState<StoryLanguage>(
        () => (book?.language as StoryLanguage) ?? defaultStoryLanguage(lang),
    );
    const [characters, setCharacters] = useState<CastRow[]>(() =>
        supportingFromBook.length > 0
            ? supportingFromBook.map((c) => ({
                  characterId: c.id,
                  name: c.name,
                  relation: c.role ?? '',
                  description: c.description ?? '',
                  photoUrl: c.photoUrl,
              }))
            : [emptyRow()],
    );

    const [submitting, setSubmitting] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    const handleHeroPhoto = async (e: React.ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];

        if (!file) {
            return;
        }

        setPhotoPreview(await downscaleImage(file));
        setHeroCharacterId(null); // a fresh photo means a new hero, not a reused one
    };

    const pickHero = (value: string) => {
        if (value === 'new') {
            setHeroCharacterId(null);

            return;
        }

        const c = savedCharacters.find((s) => String(s.id) === value);

        if (!c) {
            return;
        }

        setHeroCharacterId(c.id);
        setChildName(c.name);
        setPhotoPreview(c.photoUrl ?? null);
    };

    const handleAddCharacter = () => {
        if (characters.length < 5) {
            setCharacters([...characters, emptyRow()]);
        }
    };

    const handleRemoveCharacter = (index: number) => {
        setCharacters(characters.filter((_, i) => i !== index));
    };

    const updateCharacter = (index: number, patch: Partial<CastRow>) => {
        setCharacters((rows) =>
            rows.map((r, i) => (i === index ? { ...r, ...patch } : r)),
        );
    };

    const pickCharacter = (index: number, value: string) => {
        if (value === 'new') {
            updateCharacter(index, { characterId: null });

            return;
        }

        const c = savedCharacters.find((s) => String(s.id) === value);

        if (!c) {
            return;
        }

        updateCharacter(index, {
            characterId: c.id,
            name: c.name,
            relation: c.role ?? '',
            description: c.description ?? '',
            photoUrl: c.photoUrl ?? null,
        });
    };

    const handleCharacterPhoto = async (
        index: number,
        e: React.ChangeEvent<HTMLInputElement>,
    ) => {
        const file = e.target.files?.[0];

        if (!file) {
            return;
        }

        updateCharacter(index, {
            photoUrl: await downscaleImage(file),
            characterId: null,
        });
    };

    const handleSubmit = () => {
        const cast = [
            {
                characterId: heroCharacterId ?? undefined,
                name: childName,
                role: 'self',
                photoUrl: submittablePhoto(photoPreview),
                isMain: true,
            },
            ...characters
                .filter((c) => c.name.trim())
                .map((c) => ({
                    characterId: c.characterId ?? undefined,
                    name: c.name,
                    role: c.relation || undefined,
                    description: c.description || undefined,
                    photoUrl: submittablePhoto(c.photoUrl),
                    isMain: false,
                })),
        ];

        const payload = {
            templateId: template.id,
            childName,
            ageRange,
            theme: theme || template.theme,
            subject: subject || 'Adventure',
            lifeLesson: lifeLesson || (template.lifeLessons?.[0] ?? 'Kindness'),
            artStyle,
            font,
            language: storyLang,
            characters: cast,
        };

        const visitOptions = {
            onStart: () => {
                setSubmitting(true);
                setErrors({});
            },
            onError: (formErrors: Record<string, string>) => {
                setErrors(formErrors);
                const steps = Object.keys(formErrors).map(stepForErrorKey);

                if (steps.length > 0) {
                    setStep(Math.min(...steps));
                }
            },
            onFinish: () => {
                setSubmitting(false);
            },
        };

        if (editing && book) {
            router.patch(update.url({ id: book.id }), payload, visitOptions);
        } else {
            router.post(store.url(), payload, visitOptions);
        }
    };

    // A saved character chosen for the hero shows a "reused" hint.
    const reusedHero: Character | undefined =
        heroCharacterId != null
            ? savedCharacters.find((c) => c.id === heroCharacterId)
            : undefined;
    const castCount = characters.filter((c) => c.name.trim()).length;

    // The hero is submitted as characters[0]; surface its errors on step 1.
    const heroCastError = Object.entries(errors).find(([key]) =>
        key.startsWith('characters.0.'),
    )?.[1];

    // A supporting-cast row's index in the submitted payload (hero first, empty
    // rows filtered out), so its server errors land on the right card.
    const castErrorFor = (index: number): string | undefined => {
        if (!characters[index]?.name.trim()) {
            return undefined;
        }

        const payloadIndex =
            1 + characters.slice(0, index).filter((c) => c.name.trim()).length;

        return Object.entries(errors).find(([key]) =>
            key.startsWith(`characters.${payloadIndex}.`),
        )?.[1];
    };

    const stepEnter = reduceMotion
        ? {
              initial: { opacity: 0 },
              animate: { opacity: 1 },
              exit: { opacity: 0 },
          }
        : {
              initial: { opacity: 0, x: 24 },
              animate: { opacity: 1, x: 0 },
              exit: { opacity: 0, x: -24 },
          };

    return (
        <div className="container mx-auto max-w-3xl px-4 py-10 md:py-14">
            {/* Eyebrow + title: sets the keepsake, twilight-wonder tone */}
            <div className="mb-8 text-center">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-gold/15 px-3.5 py-1 font-display text-xs font-bold tracking-[0.18em] text-gold uppercase">
                    <Wand2 className="h-3.5 w-3.5" />{' '}
                    {editing
                        ? tc('wizard.editEyebrow', 'Edit your tale')
                        : tc('wizard.eyebrow', 'Personalize your tale')}
                </span>
                <h1 className="mt-3 font-serif text-3xl font-bold text-foreground md:text-4xl">
                    {tc(`tpl.${template.theme}.title`, template.title)}
                </h1>
            </div>

            {/* Elegant step indicator: numbered nodes joined by a filling thread */}
            <nav
                aria-label={tc('wizard.progressLabel', 'Wizard progress')}
                className="mb-10"
            >
                <ol className="flex items-center justify-center gap-2 sm:gap-3">
                    {STEP_META.map((meta, i) => {
                        const n = i + 1;
                        const done = step > n;
                        const active = step === n;
                        const Icon = meta.icon;

                        return (
                            <li
                                key={meta.key}
                                className="flex items-center gap-2 sm:gap-3"
                            >
                                <div className="flex flex-col items-center gap-1.5">
                                    <div
                                        className={`relative flex h-11 w-11 items-center justify-center rounded-full border transition-colors duration-300 ${
                                            active
                                                ? 'glow-indigo border-transparent bg-primary text-primary-foreground'
                                                : done
                                                  ? 'border-transparent bg-gold text-gold-foreground'
                                                  : 'border-card-border bg-card text-muted-foreground'
                                        }`}
                                        aria-current={
                                            active ? 'step' : undefined
                                        }
                                    >
                                        {done ? (
                                            <Check
                                                className="h-5 w-5"
                                                aria-hidden
                                            />
                                        ) : (
                                            <>
                                                <Icon
                                                    className="h-5 w-5"
                                                    aria-hidden
                                                />
                                                <span className="sr-only">
                                                    {n}
                                                </span>
                                            </>
                                        )}
                                        <span
                                            className={`absolute -end-1.5 -top-1.5 flex h-5 w-5 items-center justify-center rounded-full font-display text-[10px] font-bold ${
                                                active || done
                                                    ? 'bg-background text-foreground shadow-soft'
                                                    : 'bg-muted text-muted-foreground'
                                            }`}
                                        >
                                            {n}
                                        </span>
                                    </div>
                                    <span
                                        className={`hidden text-xs font-semibold sm:block ${
                                            active
                                                ? 'text-foreground'
                                                : 'text-muted-foreground'
                                        }`}
                                    >
                                        {tc(
                                            `wizard.stepLabel.${meta.key}`,
                                            STEP_LABEL_FALLBACK[meta.key],
                                        )}
                                    </span>
                                </div>
                                {i < STEP_META.length - 1 && (
                                    <div className="relative mb-5 h-0.5 w-8 overflow-hidden rounded-full bg-border sm:w-14">
                                        <motion.div
                                            className="absolute inset-y-0 start-0 bg-gold"
                                            initial={false}
                                            animate={{
                                                width: step > n ? '100%' : '0%',
                                            }}
                                            transition={{
                                                duration: reduceMotion
                                                    ? 0
                                                    : 0.45,
                                                ease: 'easeOut',
                                            }}
                                        />
                                    </div>
                                )}
                            </li>
                        );
                    })}
                </ol>
                <p className="mt-4 text-center font-display text-sm text-muted-foreground">
                    {t('wizard.stepOf', { step, total: totalSteps })}
                </p>
            </nav>

            {/* Card: the "book" surface. A twilight header band carries the starfield motif. */}
            <div className="bg-grain relative overflow-hidden rounded-[2rem] border border-card-border bg-card shadow-lift">
                <div className="relative overflow-hidden bg-primary px-8 py-6 text-primary-foreground">
                    <Starfield count={28} className="opacity-70" />
                    <div className="relative">
                        <h2 className="font-serif text-2xl font-bold md:text-3xl">
                            {STEP_TITLE(step, t)}
                        </h2>
                        <p className="mt-1 text-sm text-primary-foreground/80">
                            {STEP_SUBTITLE(step, t)}
                        </p>
                    </div>
                </div>

                <div className="p-6 md:p-10">
                    <AnimatePresence mode="wait">
                        <motion.div
                            key={step}
                            initial={stepEnter.initial}
                            animate={stepEnter.animate}
                            exit={stepEnter.exit}
                            transition={{
                                duration: reduceMotion ? 0 : 0.32,
                                ease: [0.22, 1, 0.36, 1],
                            }}
                        >
                            {/* STEP 1 - THE HERO */}
                            {step === 1 && (
                                <div className="space-y-6">
                                    {savedCharacters.length > 0 && (
                                        <div className="space-y-2">
                                            <Label htmlFor="hero-reuse">
                                                {t(
                                                    'wizard.reuseSavedCharacterOptional',
                                                )}
                                            </Label>
                                            <Select
                                                value={
                                                    heroCharacterId
                                                        ? String(
                                                              heroCharacterId,
                                                          )
                                                        : 'new'
                                                }
                                                onValueChange={pickHero}
                                            >
                                                <SelectTrigger
                                                    id="hero-reuse"
                                                    className="h-11 rounded-xl"
                                                >
                                                    <SelectValue
                                                        placeholder={t(
                                                            'wizard.createNew',
                                                        )}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="new">
                                                        {t('wizard.createNew')}
                                                    </SelectItem>
                                                    {savedCharacters.map(
                                                        (c) => (
                                                            <SelectItem
                                                                key={c.id}
                                                                value={String(
                                                                    c.id,
                                                                )}
                                                            >
                                                                {c.name}
                                                                {c.role
                                                                    ? ` (${c.role})`
                                                                    : ''}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                    )}

                                    {/* The hero card: photo becomes the "child as the hero" portrait */}
                                    <div className="rounded-3xl border border-gold/40 bg-gold/5 p-5 md:p-7">
                                        <span className="inline-flex items-center gap-1.5 rounded-full bg-gold/20 px-3 py-1 font-display text-xs font-bold text-gold">
                                            <Sparkles className="h-3.5 w-3.5" />{' '}
                                            {t('wizard.mainCharacter')}
                                        </span>

                                        <div className="mt-5 flex flex-col items-center gap-6 sm:flex-row sm:items-start">
                                            <div className="flex flex-col items-center gap-2">
                                                <label className="group relative block h-32 w-32 shrink-0 cursor-pointer overflow-hidden rounded-full border-2 border-dashed border-gold/50 bg-card transition-colors focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background hover:border-gold hover:bg-gold/10">
                                                    <input
                                                        type="file"
                                                        accept="image/*"
                                                        onChange={
                                                            handleHeroPhoto
                                                        }
                                                        aria-label={t(
                                                            'wizard.uploadFacePhoto',
                                                        )}
                                                        className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                                                    />
                                                    {photoPreview ? (
                                                        <img
                                                            src={photoPreview}
                                                            alt={
                                                                childName
                                                                    ? t(
                                                                          'wizard.heroName',
                                                                      ) +
                                                                      ': ' +
                                                                      childName
                                                                    : t(
                                                                          'wizard.photo',
                                                                      )
                                                            }
                                                            className="h-full w-full object-cover"
                                                        />
                                                    ) : (
                                                        <div className="flex h-full w-full flex-col items-center justify-center text-gold">
                                                            <Upload
                                                                className="mb-1.5 h-7 w-7"
                                                                aria-hidden
                                                            />
                                                            <span className="text-[11px] font-semibold">
                                                                {t(
                                                                    'wizard.photo',
                                                                )}
                                                            </span>
                                                        </div>
                                                    )}
                                                    {photoPreview && (
                                                        <div className="absolute inset-x-0 bottom-0 bg-primary/80 py-1 text-center text-[10px] font-medium text-primary-foreground opacity-0 transition-opacity group-hover:opacity-100">
                                                            {t(
                                                                'wizard.clickToChangePhoto',
                                                            )}
                                                        </div>
                                                    )}
                                                </label>
                                                {reusedHero && (
                                                    <span className="text-[11px] font-medium text-gold">
                                                        {tc(
                                                            'wizard.reusedBadge',
                                                            'Reused character',
                                                        )}
                                                    </span>
                                                )}
                                            </div>

                                            <div className="w-full flex-1 space-y-4">
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="childName">
                                                        {t('wizard.heroName')}
                                                    </Label>
                                                    <Input
                                                        id="childName"
                                                        placeholder={t(
                                                            'wizard.heroNamePlaceholder',
                                                        )}
                                                        className="h-12 rounded-xl text-base"
                                                        value={childName}
                                                        onChange={(e) =>
                                                            setChildName(
                                                                e.target.value,
                                                            )
                                                        }
                                                    />
                                                    {errors.childName && (
                                                        <p className="text-xs text-destructive">
                                                            {errors.childName}
                                                        </p>
                                                    )}
                                                </div>
                                                <div className="space-y-1.5">
                                                    <Label htmlFor="ageRange">
                                                        {t('wizard.ageRange')}
                                                    </Label>
                                                    <Select
                                                        value={ageRange}
                                                        onValueChange={(v) =>
                                                            setAgeRange(
                                                                v as AgeRange,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger
                                                            id="ageRange"
                                                            className="h-12 rounded-xl text-base"
                                                        >
                                                            <SelectValue
                                                                placeholder={t(
                                                                    'wizard.selectAgeRange',
                                                                )}
                                                            />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="2-4">
                                                                {t(
                                                                    'wizard.age2to4',
                                                                )}
                                                            </SelectItem>
                                                            <SelectItem value="4-6">
                                                                {t(
                                                                    'wizard.age4to6',
                                                                )}
                                                            </SelectItem>
                                                            <SelectItem value="6-8">
                                                                {t(
                                                                    'wizard.age6to8',
                                                                )}
                                                            </SelectItem>
                                                            <SelectItem value="8-10">
                                                                {t(
                                                                    'wizard.age8to10',
                                                                )}
                                                            </SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    {errors.ageRange && (
                                                        <p className="text-xs text-destructive">
                                                            {errors.ageRange}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>
                                        </div>

                                        <p className="mt-4 text-xs text-muted-foreground">
                                            {t('wizard.heroSubtitle')}
                                        </p>
                                        {heroCastError && (
                                            <p className="mt-2 text-xs text-destructive">
                                                {heroCastError}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            )}

                            {/* STEP 2 - STORY SETTINGS */}
                            {step === 2 && (
                                <div className="space-y-8">
                                    {/* Art style as visual swatches */}
                                    <fieldset className="space-y-3">
                                        <legend className="mb-1 flex items-center gap-2 font-serif text-lg font-bold text-foreground">
                                            <Palette
                                                className="h-5 w-5 text-primary"
                                                aria-hidden
                                            />{' '}
                                            {t('wizard.illustrationStyle')}
                                        </legend>
                                        <div
                                            role="radiogroup"
                                            aria-label={t(
                                                'wizard.illustrationStyle',
                                            )}
                                            className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4"
                                        >
                                            {ART_STYLES.map((style) => {
                                                const selected =
                                                    artStyle === style;

                                                return (
                                                    <button
                                                        key={style}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={selected}
                                                        onClick={() =>
                                                            setArtStyle(style)
                                                        }
                                                        className={`group relative overflow-hidden rounded-2xl border bg-card text-start transition-all duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none ${
                                                            selected
                                                                ? 'border-gold shadow-glow'
                                                                : 'border-card-border hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-soft'
                                                        }`}
                                                    >
                                                        {/* Real example art: the same scene rendered in this
                                                            style. Falls back to the gradient swatch if the
                                                            image is missing. */}
                                                        <span
                                                            aria-hidden
                                                            className="block aspect-[4/3] w-full overflow-hidden"
                                                            style={{
                                                                backgroundImage:
                                                                    ART_STYLE_SWATCHES[
                                                                        style
                                                                    ] ??
                                                                    ART_STYLE_SWATCHES.watercolor,
                                                            }}
                                                        >
                                                            <img
                                                                src={`/images/art-styles/${style}.jpg`}
                                                                alt=""
                                                                loading="lazy"
                                                                className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.04]"
                                                                onError={(
                                                                    e,
                                                                ) => {
                                                                    e.currentTarget.style.display =
                                                                        'none';
                                                                }}
                                                            />
                                                        </span>
                                                        <span
                                                            className={`block px-2.5 py-2 text-xs font-semibold ${
                                                                selected
                                                                    ? 'text-gold-foreground dark:text-gold'
                                                                    : 'text-foreground'
                                                            }`}
                                                        >
                                                            {tc(
                                                                `artStyle.${slugify(style)}`,
                                                                style,
                                                            )}
                                                        </span>
                                                        {selected && (
                                                            <span className="absolute end-2 top-2 flex h-6 w-6 items-center justify-center rounded-full bg-gold text-gold-foreground shadow-soft">
                                                                <Check
                                                                    className="h-3.5 w-3.5"
                                                                    aria-hidden
                                                                />
                                                            </span>
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        {errors.artStyle && (
                                            <p className="text-xs text-destructive">
                                                {errors.artStyle}
                                            </p>
                                        )}
                                    </fieldset>

                                    {/* Subject as friendly chips */}
                                    <fieldset className="space-y-3">
                                        <legend className="mb-1 font-serif text-lg font-bold text-foreground">
                                            {t('wizard.subject')}
                                        </legend>
                                        <div
                                            role="radiogroup"
                                            aria-label={t('wizard.subject')}
                                            className="flex flex-wrap gap-2"
                                        >
                                            {SUBJECTS.map((s) => {
                                                const selected = subject === s;

                                                return (
                                                    <button
                                                        key={s}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={selected}
                                                        onClick={() =>
                                                            setSubject(
                                                                selected
                                                                    ? ''
                                                                    : s,
                                                            )
                                                        }
                                                        className={`rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none ${
                                                            selected
                                                                ? 'border-transparent bg-primary text-primary-foreground shadow-soft'
                                                                : 'border-card-border bg-card text-foreground hover:border-primary/50 hover:bg-primary/5'
                                                        }`}
                                                    >
                                                        {tc(
                                                            `subject.${slugify(s)}`,
                                                            s,
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {t('wizard.subjectHelp')}
                                        </p>
                                        {errors.subject && (
                                            <p className="text-xs text-destructive">
                                                {errors.subject}
                                            </p>
                                        )}
                                    </fieldset>

                                    {/* Life lesson as friendly chips */}
                                    <fieldset className="space-y-3">
                                        <legend className="mb-1 font-serif text-lg font-bold text-foreground">
                                            {t('wizard.lifeLesson')}
                                        </legend>
                                        <div
                                            role="radiogroup"
                                            aria-label={t('wizard.lifeLesson')}
                                            className="flex flex-wrap gap-2"
                                        >
                                            {LESSONS.map((l) => {
                                                const selected =
                                                    lifeLesson === l;

                                                return (
                                                    <button
                                                        key={l}
                                                        type="button"
                                                        role="radio"
                                                        aria-checked={selected}
                                                        onClick={() =>
                                                            setLifeLesson(
                                                                selected
                                                                    ? ''
                                                                    : l,
                                                            )
                                                        }
                                                        className={`rounded-full border px-3.5 py-1.5 text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none ${
                                                            selected
                                                                ? 'border-transparent bg-rose text-primary-foreground shadow-soft'
                                                                : 'border-card-border bg-card text-foreground hover:border-rose/50 hover:bg-rose/5'
                                                        }`}
                                                    >
                                                        {tc(
                                                            `lesson.${slugify(l)}`,
                                                            l,
                                                        )}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                        {errors.lifeLesson && (
                                            <p className="text-xs text-destructive">
                                                {errors.lifeLesson}
                                            </p>
                                        )}
                                    </fieldset>

                                    {/* Story world, typography, language */}
                                    <div className="grid gap-5 md:grid-cols-2">
                                        <div className="space-y-2 md:col-span-2">
                                            <Label htmlFor="theme">
                                                {t('wizard.theme')}
                                            </Label>
                                            <Input
                                                id="theme"
                                                placeholder={template.theme}
                                                className="h-12 rounded-xl"
                                                value={theme}
                                                onChange={(e) =>
                                                    setTheme(e.target.value)
                                                }
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                {t('wizard.themeHelp')}
                                            </p>
                                            {errors.theme && (
                                                <p className="text-xs text-destructive">
                                                    {errors.theme}
                                                </p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label
                                                htmlFor="font"
                                                className="flex items-center gap-1.5"
                                            >
                                                <Type
                                                    className="h-4 w-4 text-muted-foreground"
                                                    aria-hidden
                                                />{' '}
                                                {t('wizard.typography')}
                                            </Label>
                                            <Select
                                                value={font}
                                                onValueChange={(v) =>
                                                    setFont(v as BookFont)
                                                }
                                            >
                                                <SelectTrigger
                                                    id="font"
                                                    className="h-12 rounded-xl"
                                                >
                                                    <SelectValue
                                                        placeholder={t(
                                                            'wizard.selectFont',
                                                        )}
                                                    />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    <SelectItem value="classic">
                                                        {t(
                                                            'wizard.fontClassic',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="playful">
                                                        {t(
                                                            'wizard.fontPlayful',
                                                        )}
                                                    </SelectItem>
                                                    <SelectItem value="handwritten">
                                                        {t(
                                                            'wizard.fontHandwritten',
                                                        )}
                                                    </SelectItem>
                                                </SelectContent>
                                            </Select>
                                            {errors.font && (
                                                <p className="text-xs text-destructive">
                                                    {errors.font}
                                                </p>
                                            )}
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="storyLang">
                                                {t('wizard.storyLanguage')}
                                            </Label>
                                            <Select
                                                value={storyLang}
                                                onValueChange={(v) =>
                                                    setStoryLang(
                                                        v as StoryLanguage,
                                                    )
                                                }
                                            >
                                                <SelectTrigger
                                                    id="storyLang"
                                                    className="h-12 rounded-xl"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {STORY_LANGUAGES.map(
                                                        (l) => (
                                                            <SelectItem
                                                                key={l.code}
                                                                value={l.code}
                                                            >
                                                                {l.native}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                            <p className="text-xs text-muted-foreground">
                                                {t('wizard.storyLanguageHelp')}
                                            </p>
                                            {errors.language && (
                                                <p className="text-xs text-destructive">
                                                    {errors.language}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            )}

                            {/* STEP 3 - SUPPORTING CAST + REVIEW */}
                            {step === 3 && (
                                <div className="space-y-8">
                                    <div className="space-y-4">
                                        <div>
                                            <h3 className="font-serif text-xl font-bold text-foreground">
                                                {t(
                                                    'wizard.supportingCastTitle',
                                                )}
                                            </h3>
                                            <p className="text-sm text-muted-foreground">
                                                {t(
                                                    'wizard.supportingCastSubtitle',
                                                )}
                                            </p>
                                            {errors.characters && (
                                                <p className="mt-1 text-xs text-destructive">
                                                    {errors.characters}
                                                </p>
                                            )}
                                        </div>

                                        {characters.map((char, index) => (
                                            <div
                                                key={index}
                                                className="space-y-4 rounded-2xl border border-card-border bg-muted/40 p-4"
                                            >
                                                <div className="flex items-start gap-4">
                                                    <label className="group relative block h-20 w-20 shrink-0 cursor-pointer overflow-hidden rounded-2xl border-2 border-dashed border-border bg-card transition-colors focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2 focus-within:ring-offset-background hover:border-primary/50 hover:bg-primary/5">
                                                        <input
                                                            type="file"
                                                            accept="image/*"
                                                            onChange={(e) =>
                                                                handleCharacterPhoto(
                                                                    index,
                                                                    e,
                                                                )
                                                            }
                                                            aria-label={t(
                                                                'wizard.photo',
                                                            )}
                                                            className="absolute inset-0 h-full w-full cursor-pointer opacity-0"
                                                        />
                                                        {char.photoUrl ? (
                                                            <img
                                                                src={
                                                                    char.photoUrl
                                                                }
                                                                alt={
                                                                    char.name ||
                                                                    t(
                                                                        'wizard.photo',
                                                                    )
                                                                }
                                                                className="h-full w-full object-cover"
                                                            />
                                                        ) : (
                                                            <div className="flex h-full w-full flex-col items-center justify-center text-muted-foreground">
                                                                <Upload
                                                                    className="mb-1 h-5 w-5"
                                                                    aria-hidden
                                                                />
                                                                <span className="text-[10px] font-medium">
                                                                    {t(
                                                                        'wizard.photo',
                                                                    )}
                                                                </span>
                                                            </div>
                                                        )}
                                                    </label>

                                                    <div className="grid flex-1 grid-cols-2 gap-3">
                                                        <div className="space-y-1">
                                                            <Label className="text-xs">
                                                                {t(
                                                                    'wizard.castName',
                                                                )}
                                                            </Label>
                                                            <Input
                                                                placeholder={t(
                                                                    'wizard.castNamePlaceholder',
                                                                )}
                                                                value={
                                                                    char.name
                                                                }
                                                                onChange={(e) =>
                                                                    updateCharacter(
                                                                        index,
                                                                        {
                                                                            name: e
                                                                                .target
                                                                                .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                        <div className="space-y-1">
                                                            <Label className="text-xs">
                                                                {t(
                                                                    'wizard.castRelation',
                                                                )}
                                                            </Label>
                                                            <Input
                                                                placeholder={t(
                                                                    'wizard.castRelationPlaceholder',
                                                                )}
                                                                value={
                                                                    char.relation
                                                                }
                                                                onChange={(e) =>
                                                                    updateCharacter(
                                                                        index,
                                                                        {
                                                                            relation:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                        <div className="col-span-2 space-y-1">
                                                            <Label className="text-xs">
                                                                {t(
                                                                    'wizard.castDescription',
                                                                )}
                                                            </Label>
                                                            <Input
                                                                placeholder={t(
                                                                    'wizard.castDescriptionPlaceholder',
                                                                )}
                                                                value={
                                                                    char.description
                                                                }
                                                                onChange={(e) =>
                                                                    updateCharacter(
                                                                        index,
                                                                        {
                                                                            description:
                                                                                e
                                                                                    .target
                                                                                    .value,
                                                                        },
                                                                    )
                                                                }
                                                            />
                                                        </div>
                                                    </div>

                                                    {characters.length > 1 && (
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            aria-label={t(
                                                                'wizard.newCharacter',
                                                            )}
                                                            className="rounded-full text-destructive hover:bg-destructive/10 hover:text-destructive"
                                                            onClick={() =>
                                                                handleRemoveCharacter(
                                                                    index,
                                                                )
                                                            }
                                                        >
                                                            <Trash2
                                                                className="h-4 w-4"
                                                                aria-hidden
                                                            />
                                                        </Button>
                                                    )}
                                                </div>

                                                {savedCharacters.length > 0 && (
                                                    <Select
                                                        value={
                                                            char.characterId
                                                                ? String(
                                                                      char.characterId,
                                                                  )
                                                                : 'new'
                                                        }
                                                        onValueChange={(v) =>
                                                            pickCharacter(
                                                                index,
                                                                v,
                                                            )
                                                        }
                                                    >
                                                        <SelectTrigger className="h-10 rounded-xl text-sm">
                                                            <SelectValue
                                                                placeholder={t(
                                                                    'wizard.reuseSavedCharacter',
                                                                )}
                                                            />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="new">
                                                                {t(
                                                                    'wizard.newCharacter',
                                                                )}
                                                            </SelectItem>
                                                            {savedCharacters.map(
                                                                (c) => (
                                                                    <SelectItem
                                                                        key={
                                                                            c.id
                                                                        }
                                                                        value={String(
                                                                            c.id,
                                                                        )}
                                                                    >
                                                                        {c.name}
                                                                        {c.role
                                                                            ? ` (${c.role})`
                                                                            : ''}
                                                                    </SelectItem>
                                                                ),
                                                            )}
                                                        </SelectContent>
                                                    </Select>
                                                )}

                                                {castErrorFor(index) && (
                                                    <p className="text-xs text-destructive">
                                                        {castErrorFor(index)}
                                                    </p>
                                                )}
                                            </div>
                                        ))}

                                        {characters.length < 5 && (
                                            <Button
                                                variant="outline"
                                                className="h-12 w-full rounded-xl border-dashed"
                                                onClick={handleAddCharacter}
                                            >
                                                <Plus
                                                    className="me-2 h-4 w-4"
                                                    aria-hidden
                                                />{' '}
                                                {t('wizard.addCharacter')}
                                            </Button>
                                        )}
                                    </div>

                                    {/* Polished review affordance */}
                                    <div className="rounded-3xl border border-gold/40 bg-gradient-to-b from-gold/10 to-transparent p-6 md:p-7">
                                        <span className="font-display text-xs font-bold tracking-[0.18em] text-gold uppercase">
                                            {tc(
                                                'wizard.reviewEyebrow',
                                                'Final look',
                                            )}
                                        </span>
                                        <div className="mt-4 flex items-center gap-5 border-b border-border/60 pb-5">
                                            {photoPreview ? (
                                                <img
                                                    src={photoPreview}
                                                    alt={
                                                        childName ||
                                                        t(
                                                            'wizard.defaultHeroName',
                                                        )
                                                    }
                                                    className="h-20 w-20 rounded-full border-2 border-background object-cover shadow-soft"
                                                />
                                            ) : (
                                                <div className="flex h-20 w-20 items-center justify-center rounded-full bg-primary/15 font-serif text-2xl font-bold text-primary">
                                                    {childName.charAt(0) ||
                                                        t(
                                                            'wizard.unknownInitial',
                                                        )}
                                                </div>
                                            )}
                                            <div>
                                                <h3 className="font-serif text-2xl font-bold text-foreground">
                                                    {childName ||
                                                        t(
                                                            'wizard.defaultHeroName',
                                                        )}
                                                </h3>
                                                <p className="text-muted-foreground">
                                                    {t('wizard.ages', {
                                                        ageRange,
                                                    })}
                                                </p>
                                            </div>
                                        </div>

                                        <dl className="mt-5 grid grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <dt className="mb-1 block text-muted-foreground">
                                                    {t(
                                                        'wizard.summaryStoryTemplate',
                                                    )}
                                                </dt>
                                                <dd className="font-medium text-foreground">
                                                    {tc(
                                                        `tpl.${template.theme}.title`,
                                                        template.title,
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="mb-1 block text-muted-foreground">
                                                    {t(
                                                        'wizard.summaryArtStyle',
                                                    )}
                                                </dt>
                                                <dd className="font-medium text-foreground">
                                                    {tc(
                                                        `artStyle.${slugify(artStyle)}`,
                                                        artStyle,
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="mb-1 block text-muted-foreground">
                                                    {t(
                                                        'wizard.summaryLifeLesson',
                                                    )}
                                                </dt>
                                                <dd className="font-medium text-foreground">
                                                    {tc(
                                                        `lesson.${slugify(lifeLesson || template.lifeLessons?.[0] || '')}`,
                                                        lifeLesson ||
                                                            template
                                                                .lifeLessons?.[0] ||
                                                            '',
                                                    )}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt className="mb-1 block text-muted-foreground">
                                                    {t(
                                                        'wizard.summarySupportingCast',
                                                    )}
                                                </dt>
                                                <dd className="font-medium text-foreground">
                                                    {t('wizard.castAdded', {
                                                        count: castCount,
                                                    })}
                                                </dd>
                                            </div>
                                        </dl>
                                    </div>
                                </div>
                            )}
                        </motion.div>
                    </AnimatePresence>

                    {errors.templateId && (
                        <p className="mt-4 text-xs text-destructive">
                            {errors.templateId}
                        </p>
                    )}

                    {/* Navigation */}
                    <div className="mt-10 flex items-center justify-between border-t border-border/60 pt-6">
                        <Button
                            variant="ghost"
                            onClick={() => setStep(Math.max(1, step - 1))}
                            disabled={step === 1 || submitting}
                            className="rounded-full text-base"
                        >
                            <ArrowLeft
                                className="me-2 h-4 w-4 rtl:rotate-180"
                                aria-hidden
                            />{' '}
                            {t('wizard.back')}
                        </Button>

                        {step < totalSteps ? (
                            <Button
                                onClick={() => setStep(step + 1)}
                                disabled={step === 1 && !childName}
                                size="lg"
                                className="rounded-full px-8 text-base"
                            >
                                {t('wizard.continue')}{' '}
                                <ArrowRight
                                    className="ms-2 h-4 w-4 rtl:rotate-180"
                                    aria-hidden
                                />
                            </Button>
                        ) : (
                            <Button
                                variant="gold"
                                onClick={handleSubmit}
                                disabled={submitting || !childName}
                                size="lg"
                                className="glow-gold rounded-full px-8 text-base"
                            >
                                {submitting ? (
                                    <>
                                        <Loader2
                                            className="me-2 h-4 w-4 animate-spin"
                                            aria-hidden
                                        />{' '}
                                        {t('wizard.weavingMagic')}
                                    </>
                                ) : (
                                    <>
                                        <Sparkles
                                            className="me-2 h-4 w-4"
                                            aria-hidden
                                        />{' '}
                                        {t('wizard.continueToCheckout')}
                                    </>
                                )}
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

// English fallbacks for the compact step labels under each indicator node.
const STEP_LABEL_FALLBACK: Record<(typeof STEP_META)[number]['key'], string> = {
    hero: 'The Hero',
    settings: 'The Story',
    cast: 'The Cast',
};

// The card header title per step reuses existing wizard keys.
function STEP_TITLE(
    step: number,
    t: (k: string, v?: Record<string, string | number>) => string,
): string {
    if (step === 1) {
        return t('wizard.heroQuestion');
    }

    if (step === 2) {
        return t('wizard.styleTitle');
    }

    return t('wizard.readyTitle');
}

function STEP_SUBTITLE(
    step: number,
    t: (k: string, v?: Record<string, string | number>) => string,
): string {
    if (step === 1) {
        return t('wizard.castStepSubtitle');
    }

    if (step === 2) {
        return t('wizard.styleSubtitle');
    }

    return t('wizard.readySubtitle');
}
