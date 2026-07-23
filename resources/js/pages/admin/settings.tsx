import { Head, router, useForm } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    Cloud,
    Eye,
    Globe,
    Loader2,
    MonitorSmartphone,
    RotateCcw,
    Sparkles,
    Zap,
} from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

// The admin area is owner-only, so its copy is intentionally English-only;
// the public app remains fully localized.

type SettingEntry = {
    value: string | number | boolean | null;
    default: string | number | boolean | null;
    overridden: boolean;
};

type Props = {
    settings: Record<string, SettingEntry>;
    pdfPageSizes: { key: string; label: string }[];
    replicateEngines: ReplicateEngineOption[];
    imageAspectRatios: string[];
    storyLanguages: { code: string; label: string }[];
    bundledFaces: string[];
};

type ReplicateEngineOption = {
    provider: string;
    model: string;
    label: string;
    description: string;
    cost: string;
    costDetail: string;
    supportsGroups: boolean;
    maxReferences: number;
};

const PROVIDER_OPTIONS = {
    text: ['openai', 'gemini', 'openrouter'],
    image: [
        'openai',
        'gemini',
        'openrouter',
        'flow',
        'grok',
        'piapi',
        'replicate',
    ],
};

// The one switch that matters day to day: which engine draws the images.
// Each preset maps to the underlying provider (+ model where it matters), so
// one click applies everywhere - generation, restyles, regenerations,
// playground. The Replicate catalog engines are prepended from the server,
// one preset per model.
type EnginePreset = {
    id: string;
    title: string;
    description: string;
    cost?: string;
    icon: React.ComponentType<{ className?: string }>;
    values: {
        image_provider: string;
        image_model_flow?: string;
        image_model_replicate?: string;
    };
};

const STATIC_ENGINE_PRESETS: EnginePreset[] = [
    {
        id: 'openrouter',
        title: 'OpenRouter',
        description: 'Grok Imagine via OpenRouter (paid, reliable)',
        icon: Cloud,
        values: { image_provider: 'openrouter' },
    },
    {
        id: 'browser-flow',
        title: 'Browser - Google Flow',
        description: 'Local browser gateway, free daily quota',
        icon: MonitorSmartphone,
        values: { image_provider: 'flow', image_model_flow: 'google-flow' },
    },
    {
        id: 'browser-grok',
        title: 'Browser - Grok Imagine',
        description: 'Local browser gateway, free daily quota',
        icon: MonitorSmartphone,
        values: { image_provider: 'flow', image_model_flow: 'grok-imagine' },
    },
    {
        id: 'gemini',
        title: 'Gemini',
        description: 'Google image model (paid API)',
        icon: Sparkles,
        values: { image_provider: 'gemini' },
    },
    {
        id: 'openai',
        title: 'OpenAI',
        description: 'gpt-image (paid API)',
        icon: Globe,
        values: { image_provider: 'openai' },
    },
    {
        id: 'xai',
        title: 'xAI direct',
        description: 'Cheapest paid option - needs XAI_API_KEY in .env',
        icon: Zap,
        values: { image_provider: 'grok' },
    },
    {
        id: 'piapi',
        title: 'PiAPI Flux Kontext',
        description: 'Flux Kontext edits with the reference photo (paid API)',
        icon: Sparkles,
        values: { image_provider: 'piapi' },
    },
];

function Field({
    label,
    hint,
    overridden,
    children,
}: {
    label: string;
    hint?: string;
    overridden?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div className="space-y-1.5">
            <Label className="flex items-center gap-2 text-sm">
                {label}
                {overridden && (
                    <span className="rounded-full bg-gold/15 px-2 py-0.5 text-[10px] font-semibold text-gold-foreground dark:text-gold">
                        override
                    </span>
                )}
            </Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
        </div>
    );
}

export default function AdminSettings({
    settings,
    pdfPageSizes,
    replicateEngines,
    imageAspectRatios,
    storyLanguages,
    bundledFaces,
}: Props) {
    const initial: Record<string, string | number | boolean> = {};

    for (const [key, entry] of Object.entries(settings)) {
        initial[key] = (entry.value ?? '') as string | number | boolean;
    }

    const form = useForm(initial);
    const [showAdvanced, setShowAdvanced] = useState(false);
    const [applying, setApplying] = useState<string | null>(null);
    const [savingPdfSize, setSavingPdfSize] = useState(false);
    const [previewBookId, setPreviewBookId] = useState('');
    const [previewVariant, setPreviewVariant] = useState('home');
    // The Replicate model field starts in custom (free text) mode only when
    // the stored value is not a catalog engine.
    const [replicateCustomModel, setReplicateCustomModel] = useState(
        !replicateEngines.some(
            (engine) =>
                engine.model === String(settings.image_model_replicate?.value),
        ),
    );

    // One preset card per Replicate catalog engine (primary lineup), then
    // the other providers.
    const enginePresets: EnginePreset[] = [
        ...replicateEngines.map((engine): EnginePreset => ({
            id: `replicate:${engine.model}`,
            title: `Replicate ${engine.label}`,
            description: engine.description,
            cost: engine.costDetail,
            icon: Cloud,
            values: {
                image_provider: 'replicate',
                image_model_replicate: engine.model,
            },
        })),
        ...STATIC_ENGINE_PRESETS,
    ];

    const savePdfSize = () => {
        router.put(
            '/admin/settings',
            { ...form.data },
            {
                preserveScroll: true,
                onStart: () => setSavingPdfSize(true),
                onFinish: () => setSavingPdfSize(false),
            },
        );
    };

    const openPdfPreview = () => {
        const query = new URLSearchParams({
            bookId: previewBookId,
            size: String(form.data.pdf_page_size ?? ''),
            variant: previewVariant,
            fit: String(form.data.pdf_image_fit ?? 'crop'),
            font: String(form.data.pdf_font_default ?? ''),
        });

        window.open(`/admin/settings/pdf-preview?${query}`, '_blank');
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put('/admin/settings', { preserveScroll: true });
    };

    const activePresetId =
        enginePresets.find((preset) => {
            if (
                preset.values.image_provider !== settings.image_provider?.value
            ) {
                return false;
            }

            if (
                preset.values.image_model_flow !== undefined &&
                preset.values.image_model_flow !==
                    settings.image_model_flow?.value
            ) {
                return false;
            }

            return (
                preset.values.image_model_replicate === undefined ||
                preset.values.image_model_replicate ===
                    settings.image_model_replicate?.value
            );
        })?.id ?? null;

    // The dedicated cover engine, encoded as one select value: 'default'
    // (same as main), 'replicate:<model>' (a catalog engine), or a bare
    // provider (that provider's configured model).
    const coverEngineValue = (() => {
        const provider = String(settings.cover_image_provider?.value ?? '');
        const model = String(settings.cover_image_model?.value ?? '');

        if (provider === '') {
            return 'default';
        }

        if (provider === 'replicate' && model !== '') {
            return `replicate:${model}`;
        }

        return provider;
    })();
    const [savingCoverEngine, setSavingCoverEngine] = useState(false);

    const saveCoverEngine = (value: string) => {
        const provider =
            value === 'default'
                ? ''
                : value.startsWith('replicate:')
                  ? 'replicate'
                  : value;
        const model = value.startsWith('replicate:')
            ? value.slice('replicate:'.length)
            : '';

        form.setData({
            ...form.data,
            cover_image_provider: provider,
            cover_image_model: model,
        });
        router.put(
            '/admin/settings',
            {
                ...form.data,
                cover_image_provider: provider,
                cover_image_model: model,
            },
            {
                preserveScroll: true,
                onStart: () => setSavingCoverEngine(true),
                onFinish: () => setSavingCoverEngine(false),
            },
        );
    };

    // The dedicated portrait (character sheet) engine, same encoding as the
    // cover engine select.
    const portraitEngineValue = (() => {
        const provider = String(settings.portrait_image_provider?.value ?? '');
        const model = String(settings.portrait_image_model?.value ?? '');

        if (provider === '') {
            return 'default';
        }

        if (provider === 'replicate' && model !== '') {
            return `replicate:${model}`;
        }

        return provider;
    })();
    const [savingPortraitEngine, setSavingPortraitEngine] = useState(false);

    const savePortraitEngine = (value: string) => {
        const provider =
            value === 'default'
                ? ''
                : value.startsWith('replicate:')
                  ? 'replicate'
                  : value;
        const model = value.startsWith('replicate:')
            ? value.slice('replicate:'.length)
            : '';

        form.setData({
            ...form.data,
            portrait_image_provider: provider,
            portrait_image_model: model,
        });
        router.put(
            '/admin/settings',
            {
                ...form.data,
                portrait_image_provider: provider,
                portrait_image_model: model,
            },
            {
                preserveScroll: true,
                onStart: () => setSavingPortraitEngine(true),
                onFinish: () => setSavingPortraitEngine(false),
            },
        );
    };

    // The portrait engine only matters in sheet mode; flipping the identity
    // anchor from here closes the trap of configuring an engine that never
    // runs.
    const [savingIdentity, setSavingIdentity] = useState(false);

    const switchToSheetMode = () => {
        form.setData('identity_reference', 'sheet');
        router.put(
            '/admin/settings',
            { ...form.data, identity_reference: 'sheet' },
            {
                preserveScroll: true,
                onStart: () => setSavingIdentity(true),
                onFinish: () => setSavingIdentity(false),
            },
        );
    };

    const applyPreset = (preset: EnginePreset) => {
        if (preset.values.image_model_replicate !== undefined) {
            setReplicateCustomModel(false);
            form.setData(
                'image_model_replicate',
                preset.values.image_model_replicate,
            );
        }

        router.put(
            '/admin/settings',
            { ...form.data, ...preset.values },
            {
                preserveScroll: true,
                onStart: () => setApplying(preset.id),
                onFinish: () => setApplying(null),
            },
        );
    };

    const text = (key: string, label: string, hint?: string) => (
        <Field label={label} hint={hint} overridden={settings[key]?.overridden}>
            <Input
                value={String(form.data[key] ?? '')}
                onChange={(e) => form.setData(key, e.target.value)}
            />
            {form.errors[key] && (
                <p className="text-xs text-destructive">{form.errors[key]}</p>
            )}
        </Field>
    );

    const number = (key: string, label: string, hint?: string) => (
        <Field label={label} hint={hint} overridden={settings[key]?.overridden}>
            <Input
                type="number"
                value={String(form.data[key] ?? '')}
                onChange={(e) => form.setData(key, Number(e.target.value))}
            />
            {form.errors[key] && (
                <p className="text-xs text-destructive">{form.errors[key]}</p>
            )}
        </Field>
    );

    const select = (
        key: string,
        label: string,
        options: string[],
        hint?: string,
    ) => (
        <Field label={label} hint={hint} overridden={settings[key]?.overridden}>
            <Select
                value={String(form.data[key] ?? '')}
                onValueChange={(value) => form.setData(key, value)}
            >
                <SelectTrigger>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map((option) => (
                        <SelectItem key={option} value={option}>
                            {option}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            {form.errors[key] && (
                <p className="text-xs text-destructive">{form.errors[key]}</p>
            )}
        </Field>
    );

    return (
        <>
            <Head title="Settings - Admin" />
            <div className="space-y-6 p-6">
                <div>
                    <h1 className="font-serif text-2xl font-semibold">
                        Settings
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Changes apply everywhere immediately - generation,
                        restyles and the playground pick them up on the next
                        job.
                    </p>
                </div>

                {/* The core switch: which engine draws the images. */}
                <Card className="border-gold/40">
                    <CardHeader>
                        <CardTitle>Image engine</CardTitle>
                        <CardDescription>
                            One click switches every image the app makes.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                            {enginePresets.map((preset) => {
                                const active = activePresetId === preset.id;
                                const Icon = preset.icon;

                                return (
                                    <button
                                        key={preset.id}
                                        type="button"
                                        onClick={() => applyPreset(preset)}
                                        disabled={applying !== null}
                                        className={`flex items-start gap-3 rounded-xl border p-4 text-start transition-all ${
                                            active
                                                ? 'border-gold bg-gold/10 shadow-glow'
                                                : 'border-card-border hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-soft'
                                        }`}
                                    >
                                        <span
                                            className={`mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg ${active ? 'bg-gold text-gold-foreground' : 'bg-primary/10 text-primary'}`}
                                        >
                                            {applying === preset.id ? (
                                                <Loader2 className="h-4 w-4 animate-spin" />
                                            ) : (
                                                <Icon className="h-4 w-4" />
                                            )}
                                        </span>
                                        <span>
                                            <span className="flex items-center gap-2 font-semibold">
                                                {preset.title}
                                                {active && (
                                                    <Check className="h-4 w-4 text-gold-foreground dark:text-gold" />
                                                )}
                                            </span>
                                            <span className="mt-0.5 block text-xs text-muted-foreground">
                                                {preset.description}
                                            </span>
                                            {preset.cost && (
                                                <span className="mt-1 block text-xs font-medium text-gold-foreground dark:text-gold">
                                                    {preset.cost}
                                                </span>
                                            )}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>

                        {/* The cover can run on a pricier model than the
                            pages: it is the one image that sells the book. */}
                        <div className="mt-4 rounded-xl border border-card-border bg-muted/20 p-4">
                            <Field
                                label="Cover engine"
                                hint="The cover only. 'Same as main engine' follows the cards above; a per-run override on a book page still wins."
                                overridden={
                                    settings.cover_image_provider?.overridden
                                }
                            >
                                <Select
                                    value={coverEngineValue}
                                    onValueChange={saveCoverEngine}
                                    disabled={savingCoverEngine}
                                >
                                    <SelectTrigger className="max-w-xl">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="default">
                                            Same as main engine
                                        </SelectItem>
                                        {replicateEngines.map((engine) => (
                                            <SelectItem
                                                key={engine.model}
                                                value={`replicate:${engine.model}`}
                                            >
                                                Replicate {engine.label} (
                                                {engine.costDetail})
                                            </SelectItem>
                                        ))}
                                        {[
                                            'openai',
                                            'gemini',
                                            'openrouter',
                                            'flow',
                                            'grok',
                                            'piapi',
                                            'replicate',
                                        ].map((provider) => (
                                            <SelectItem
                                                key={provider}
                                                value={provider}
                                            >
                                                {provider} (configured model)
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>

                        {/* The character portrait (sheet) is the ONE
                            photo-to-illustration jump every other image
                            inherits, so it can run on the best stylizer
                            while pages run on a consistency engine. Drawn
                            once per character and style, then reused. */}
                        <div className="mt-4 rounded-xl border border-card-border bg-muted/20 p-4">
                            {String(form.data.identity_reference ?? '') ===
                                'photo' && (
                                <div className="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-lg border border-amber-300/60 bg-amber-50 px-3 py-2 dark:border-amber-900/50 dark:bg-amber-950/40">
                                    <p className="text-xs text-amber-900 dark:text-amber-200">
                                        Portraits are OFF: the identity anchor
                                        is set to &quot;photo&quot;, so the raw
                                        photo travels with every image and no
                                        portrait is ever drawn. The engine
                                        below does nothing until sheet mode is
                                        on.
                                    </p>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={switchToSheetMode}
                                        disabled={savingIdentity}
                                    >
                                        {savingIdentity && (
                                            <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                        )}
                                        Switch to sheet mode
                                    </Button>
                                </div>
                            )}
                            <Field
                                label="Portrait engine"
                                hint="The character sheet only: drawn once per character and art style, then reused by every book. 'Same as main engine' follows the cards above."
                                overridden={
                                    settings.portrait_image_provider
                                        ?.overridden
                                }
                            >
                                <Select
                                    value={portraitEngineValue}
                                    onValueChange={savePortraitEngine}
                                    disabled={savingPortraitEngine}
                                >
                                    <SelectTrigger className="max-w-xl">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="default">
                                            Same as main engine
                                        </SelectItem>
                                        {replicateEngines.map((engine) => (
                                            <SelectItem
                                                key={engine.model}
                                                value={`replicate:${engine.model}`}
                                            >
                                                Replicate {engine.label} (
                                                {engine.costDetail})
                                            </SelectItem>
                                        ))}
                                        {[
                                            'openai',
                                            'gemini',
                                            'openrouter',
                                            'flow',
                                            'grok',
                                            'piapi',
                                            'replicate',
                                        ].map((provider) => (
                                            <SelectItem
                                                key={provider}
                                                value={provider}
                                            >
                                                {provider} (configured model)
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                        </div>
                    </CardContent>
                </Card>

                {/* The PDF trim size, with a real-book preview at any preset
                    before committing to one. */}
                <Card>
                    <CardHeader>
                        <CardTitle>Storybook PDF</CardTitle>
                        <CardDescription>
                            The page size every downloaded PDF is composed at.
                            Preview a real book at any size first; nothing is
                            saved until you hit Save size.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <div className="flex flex-wrap items-end gap-3">
                            <div className="min-w-64 flex-1">
                                <Field
                                    label="Page size"
                                    overridden={
                                        settings.pdf_page_size?.overridden
                                    }
                                >
                                    <Select
                                        value={String(
                                            form.data.pdf_page_size ?? '',
                                        )}
                                        onValueChange={(value) =>
                                            form.setData('pdf_page_size', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {pdfPageSizes.map((size) => (
                                                <SelectItem
                                                    key={size.key}
                                                    value={size.key}
                                                >
                                                    {size.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.errors.pdf_page_size && (
                                        <p className="text-xs text-destructive">
                                            {form.errors.pdf_page_size}
                                        </p>
                                    )}
                                </Field>
                            </div>
                            <div className="min-w-56">
                                <Field
                                    label="Image fit"
                                    overridden={
                                        settings.pdf_image_fit?.overridden
                                    }
                                >
                                    <Select
                                        value={String(
                                            form.data.pdf_image_fit ?? 'crop',
                                        )}
                                        onValueChange={(value) =>
                                            form.setData('pdf_image_fit', value)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="crop">
                                                Fill page (edges cropped)
                                            </SelectItem>
                                            <SelectItem value="full">
                                                Full image above text
                                            </SelectItem>
                                            <SelectItem value="overlay">
                                                Full-page image, text overlay
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                    {form.errors.pdf_image_fit && (
                                        <p className="text-xs text-destructive">
                                            {form.errors.pdf_image_fit}
                                        </p>
                                    )}
                                </Field>
                            </div>
                            <Button
                                type="button"
                                onClick={savePdfSize}
                                disabled={savingPdfSize}
                            >
                                {savingPdfSize ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Check className="h-4 w-4" />
                                )}
                                Save PDF settings
                            </Button>
                        </div>

                        {/* Story fonts: one default for every language, plus
                            per-language overrides. */}
                        <div className="rounded-xl border border-card-border bg-muted/20 p-4">
                            <div className="mb-3 max-w-md">
                                <Field
                                    label="Default story font (all languages)"
                                    hint={`Empty or "auto" keeps the automatic per-style faces. Bundled: ${bundledFaces.join(', ')}. Anything else is fetched from Google Fonts by family name (e.g. Cairo).`}
                                    overridden={
                                        settings.pdf_font_default?.overridden
                                    }
                                >
                                    <Input
                                        placeholder="auto"
                                        value={String(
                                            form.data.pdf_font_default ?? '',
                                        )}
                                        onChange={(e) =>
                                            form.setData(
                                                'pdf_font_default',
                                                e.target.value,
                                            )
                                        }
                                    />
                                    {form.errors.pdf_font_default && (
                                        <p className="text-xs text-destructive">
                                            {form.errors.pdf_font_default}
                                        </p>
                                    )}
                                </Field>
                            </div>
                            <p className="mb-2 text-xs text-muted-foreground">
                                Per-language overrides (win over the default;
                                empty inherits it). Make sure the font supports
                                that language's script.
                            </p>
                            <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                                {storyLanguages.map((language) => (
                                    <Field
                                        key={language.code}
                                        label={language.label}
                                        overridden={
                                            settings[
                                                `pdf_font_${language.code}`
                                            ]?.overridden
                                        }
                                    >
                                        <Input
                                            placeholder="default"
                                            value={String(
                                                form.data[
                                                    `pdf_font_${language.code}`
                                                ] ?? '',
                                            )}
                                            onChange={(e) =>
                                                form.setData(
                                                    `pdf_font_${language.code}`,
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </Field>
                                ))}
                            </div>
                        </div>

                        <div className="flex flex-wrap items-end gap-3 rounded-xl border border-card-border bg-muted/20 p-4">
                            <Field
                                label="Try it with book id"
                                hint="Opens the real PDF in a new tab at the selected size."
                            >
                                <Input
                                    type="number"
                                    min={1}
                                    placeholder="e.g. 26"
                                    className="w-32"
                                    value={previewBookId}
                                    onChange={(e) =>
                                        setPreviewBookId(e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Variant">
                                <Select
                                    value={previewVariant}
                                    onValueChange={setPreviewVariant}
                                >
                                    <SelectTrigger className="w-44">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="home">
                                            home (clean)
                                        </SelectItem>
                                        <SelectItem value="print">
                                            print (bleed + marks)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Button
                                type="button"
                                variant="outline"
                                disabled={previewBookId === ''}
                                onClick={openPdfPreview}
                            >
                                <Eye className="h-4 w-4" /> Preview PDF
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <button
                    type="button"
                    onClick={() => setShowAdvanced((value) => !value)}
                    className="flex items-center gap-2 font-display text-sm font-semibold text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ChevronDown
                        className={`h-4 w-4 transition-transform ${showAdvanced ? 'rotate-180' : ''}`}
                    />
                    Advanced settings
                </button>

                {showAdvanced && (
                    <form onSubmit={submit} className="space-y-6">
                        <div className="flex justify-end">
                            <Button type="submit" disabled={form.processing}>
                                {form.processing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : form.recentlySuccessful ? (
                                    <Check className="h-4 w-4" />
                                ) : null}
                                Save settings
                            </Button>
                        </div>

                        <div className="grid gap-6 lg:grid-cols-2">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Generation</CardTitle>
                                    <CardDescription>
                                        Page bounds and how the images are
                                        rendered.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid grid-cols-2 gap-4">
                                        {number('pages_min', 'Pages minimum')}
                                        {number('pages_max', 'Pages maximum')}
                                    </div>
                                    {select(
                                        'image_aspect_ratio',
                                        'Image aspect ratio',
                                        imageAspectRatios,
                                        'Every page and cover generates at this ratio. The character sheet stays portrait regardless.',
                                    )}
                                    {select(
                                        'image_quality',
                                        'Image quality',
                                        ['standard', 'high', 'max'],
                                        'Resolution tier for Replicate engines: standard = smallest, high = ~2K, max = the largest the model offers (slower, pricier).',
                                    )}
                                    <Field
                                        label="Group page generation"
                                        hint="Render all pages as one coherent set when the model supports it (Seedream); others fall back to page-by-page."
                                        overridden={
                                            settings.image_group_generation
                                                ?.overridden
                                        }
                                    >
                                        <label className="flex cursor-pointer items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={Boolean(
                                                    form.data
                                                        .image_group_generation,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'image_group_generation',
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            Generate the whole book in one
                                            request
                                        </label>
                                    </Field>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Providers</CardTitle>
                                    <CardDescription>
                                        Who writes text and draws images.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {select(
                                        'text_provider',
                                        'Text provider',
                                        PROVIDER_OPTIONS.text,
                                    )}
                                    {select(
                                        'image_provider',
                                        'Image provider',
                                        PROVIDER_OPTIONS.image,
                                    )}
                                    {select(
                                        'identity_reference',
                                        'Identity anchor',
                                        ['photo', 'sheet'],
                                        'photo: the uploaded photo travels with every image. sheet: one stylized character sheet anchors the book.',
                                    )}
                                    {number(
                                        'max_image_references',
                                        'Max reference images per request',
                                        '0 = unlimited.',
                                    )}
                                    {text(
                                        'image_fallback_engines',
                                        'Content-flag fallback engines',
                                        'Tried in order when an engine refuses an image as sensitive: provider:model, comma-separated. Round 1 keeps the original prompt across the whole chain; the prompt is rewritten once only after every engine refused. Empty disables the chain.',
                                    )}
                                    {select(
                                        'photo_upload_quality',
                                        'Photo upload quality',
                                        ['original', 'optimized'],
                                        'original: the untouched file reaches the image models (best likeness). optimized: browser downscale to 768px (smaller requests).',
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Text models</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {text('text_model_openai', 'OpenAI')}
                                    {text('text_model_gemini', 'Gemini')}
                                    {text(
                                        'text_model_openrouter',
                                        'OpenRouter',
                                    )}
                                    {text(
                                        'vision_model_openrouter',
                                        'OpenRouter vision override',
                                        'Set when the text model cannot read images (e.g. DeepSeek). Empty follows the text model.',
                                    )}
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Image models</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {text('image_model_openai', 'OpenAI')}
                                    {text('image_model_gemini', 'Gemini')}
                                    {text(
                                        'image_model_openrouter',
                                        'OpenRouter',
                                    )}
                                    {text('image_model_flow', 'Flow gateway')}
                                    {text('image_model_grok', 'xAI Grok')}
                                    {text('image_model_piapi', 'PiAPI Flux')}
                                    <Field
                                        label="Replicate"
                                        hint="Catalog engines have verified parameters and pricing; Custom accepts any owner/model slug (schema-driven)."
                                        overridden={
                                            settings.image_model_replicate
                                                ?.overridden
                                        }
                                    >
                                        <Select
                                            value={
                                                replicateCustomModel
                                                    ? 'custom'
                                                    : String(
                                                          form.data
                                                              .image_model_replicate ??
                                                              '',
                                                      )
                                            }
                                            onValueChange={(value) => {
                                                if (value === 'custom') {
                                                    setReplicateCustomModel(
                                                        true,
                                                    );

                                                    return;
                                                }

                                                setReplicateCustomModel(false);
                                                form.setData(
                                                    'image_model_replicate',
                                                    value,
                                                );
                                            }}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {replicateEngines.map(
                                                    (engine) => (
                                                        <SelectItem
                                                            key={engine.model}
                                                            value={engine.model}
                                                        >
                                                            {engine.label} (
                                                            {engine.costDetail})
                                                        </SelectItem>
                                                    ),
                                                )}
                                                <SelectItem value="custom">
                                                    Custom...
                                                </SelectItem>
                                            </SelectContent>
                                        </Select>
                                        {replicateCustomModel && (
                                            <Input
                                                placeholder="owner/model"
                                                value={String(
                                                    form.data
                                                        .image_model_replicate ??
                                                        '',
                                                )}
                                                onChange={(e) =>
                                                    form.setData(
                                                        'image_model_replicate',
                                                        e.target.value,
                                                    )
                                                }
                                            />
                                        )}
                                        {form.errors.image_model_replicate && (
                                            <p className="text-xs text-destructive">
                                                {
                                                    form.errors
                                                        .image_model_replicate
                                                }
                                            </p>
                                        )}
                                    </Field>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardHeader>
                                    <CardTitle>Store</CardTitle>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    {select(
                                        'payment_provider',
                                        'Payment provider',
                                        ['stripe', 'paddle'],
                                        'Used for new checkouts; in-flight orders finish on the provider they started with.',
                                    )}
                                    <div className="grid grid-cols-2 gap-4">
                                        {number(
                                            'price_cents',
                                            'Price (cents)',
                                            'Charged once per book.',
                                        )}
                                        {select('price_currency', 'Currency', [
                                            'eur',
                                            'usd',
                                            'gbp',
                                            'try',
                                        ])}
                                    </div>
                                    <Field
                                        label="Registration open"
                                        overridden={
                                            settings.registration_open
                                                ?.overridden
                                        }
                                    >
                                        <label className="flex cursor-pointer items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={Boolean(
                                                    form.data.registration_open,
                                                )}
                                                onCheckedChange={(checked) =>
                                                    form.setData(
                                                        'registration_open',
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            New accounts can sign up
                                        </label>
                                    </Field>
                                </CardContent>
                            </Card>
                        </div>

                        <p className="flex items-center gap-2 text-xs text-muted-foreground">
                            <RotateCcw className="h-3.5 w-3.5" />
                            Fields marked "override" differ from .env; the env
                            value is what you get if the override is removed
                            from the database.
                        </p>
                    </form>
                )}
            </div>
        </>
    );
}
