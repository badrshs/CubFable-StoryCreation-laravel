import { Head, router, useForm } from '@inertiajs/react';
import { Check, Loader2, Trash2, X } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

type TemplateData = {
    id: number;
    title: string;
    description: string;
    theme: string;
    ageMin: number;
    ageMax: number;
    pageCount: number;
    coverImageUrl: string | null;
    lifeLessons: string[];
    artStyles: string[];
    subjects: string[];
    fonts: string[];
    imagePrompt: string;
    booksCount: number;
};

type Props = {
    template: TemplateData | null;
    artStyleOptions: string[];
    fontOptions: string[];
    pageBounds: { min: number; max: number };
};

// Free-text chips (lessons, subjects): type and add. Enum chips (styles,
// fonts): toggle from the allowed set.
function ChipInput({
    label,
    values,
    onChange,
    error,
}: {
    label: string;
    values: string[];
    onChange: (next: string[]) => void;
    error?: string;
}) {
    const [draft, setDraft] = useState('');

    const add = () => {
        const value = draft.trim();

        if (value && !values.includes(value)) {
            onChange([...values, value]);
        }

        setDraft('');
    };

    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            <div className="flex flex-wrap gap-1.5">
                {values.map((value) => (
                    <Badge key={value} variant="secondary" className="gap-1">
                        {value}
                        <button
                            type="button"
                            onClick={() =>
                                onChange(values.filter((v) => v !== value))
                            }
                        >
                            <X className="h-3 w-3" />
                        </button>
                    </Badge>
                ))}
            </div>
            <div className="flex gap-2">
                <Input
                    value={draft}
                    onChange={(e) => setDraft(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            add();
                        }
                    }}
                    placeholder="type and press Enter"
                />
                <Button type="button" variant="outline" onClick={add}>
                    Add
                </Button>
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

function ToggleChips({
    label,
    options,
    values,
    onChange,
    error,
}: {
    label: string;
    options: string[];
    values: string[];
    onChange: (next: string[]) => void;
    error?: string;
}) {
    return (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            <div className="flex flex-wrap gap-1.5">
                {options.map((option) => {
                    const active = values.includes(option);

                    return (
                        <button
                            key={option}
                            type="button"
                            onClick={() =>
                                onChange(
                                    active
                                        ? values.filter((v) => v !== option)
                                        : [...values, option],
                                )
                            }
                            className={`rounded-full border px-3 py-1 text-xs font-medium transition-colors ${
                                active
                                    ? 'border-gold bg-gold/15 text-gold-foreground dark:text-gold'
                                    : 'border-card-border text-muted-foreground hover:border-primary/50'
                            }`}
                        >
                            {option}
                        </button>
                    );
                })}
            </div>
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

export default function AdminTemplateForm({
    template,
    artStyleOptions,
    fontOptions,
    pageBounds,
}: Props) {
    const form = useForm({
        title: template?.title ?? '',
        description: template?.description ?? '',
        theme: template?.theme ?? '',
        age_min: template?.ageMin ?? 3,
        age_max: template?.ageMax ?? 8,
        page_count: template?.pageCount ?? pageBounds.min,
        cover_image_url: template?.coverImageUrl ?? '',
        life_lessons: template?.lifeLessons ?? [],
        art_styles: template?.artStyles ?? [],
        subjects: template?.subjects ?? [],
        fonts: template?.fonts ?? [],
        image_prompt: template?.imagePrompt ?? '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        if (template) {
            form.put(`/admin/templates/${template.id}`, {
                preserveScroll: true,
            });
        } else {
            form.post('/admin/templates');
        }
    };

    const field = (
        key:
            | 'title'
            | 'theme'
            | 'cover_image_url',
        label: string,
        hint?: string,
    ) => (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            <Input
                value={String(form.data[key] ?? '')}
                onChange={(e) => form.setData(key, e.target.value)}
            />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {form.errors[key] && (
                <p className="text-xs text-destructive">{form.errors[key]}</p>
            )}
        </div>
    );

    const numberField = (
        key: 'age_min' | 'age_max' | 'page_count',
        label: string,
        hint?: string,
    ) => (
        <div className="space-y-1.5">
            <Label>{label}</Label>
            <Input
                type="number"
                value={String(form.data[key] ?? '')}
                onChange={(e) => form.setData(key, Number(e.target.value))}
            />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {form.errors[key] && (
                <p className="text-xs text-destructive">{form.errors[key]}</p>
            )}
        </div>
    );

    return (
        <>
            <Head
                title={`${template ? 'Edit' : 'New'} template - Admin`}
            />
            <form onSubmit={submit} className="space-y-6 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="font-serif text-2xl font-semibold">
                            {template
                                ? `Edit: ${template.title}`
                                : 'New template'}
                        </h1>
                        {template && (
                            <p className="text-sm text-muted-foreground">
                                {template.booksCount} book(s) created from this
                                template.
                            </p>
                        )}
                    </div>
                    <div className="flex items-center gap-2">
                        {template && (
                            <Button
                                type="button"
                                variant="destructive"
                                disabled={template.booksCount > 0}
                                title={
                                    template.booksCount > 0
                                        ? 'Templates with books cannot be deleted'
                                        : undefined
                                }
                                onClick={() =>
                                    router.delete(
                                        `/admin/templates/${template.id}`,
                                    )
                                }
                            >
                                <Trash2 className="h-4 w-4" /> Delete
                            </Button>
                        )}
                        <Button type="submit" disabled={form.processing}>
                            {form.processing ? (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            ) : form.recentlySuccessful ? (
                                <Check className="h-4 w-4" />
                            ) : null}
                            Save template
                        </Button>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Story</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {field('title', 'Title')}
                            <div className="space-y-1.5">
                                <Label>Description</Label>
                                <Textarea
                                    rows={3}
                                    value={form.data.description}
                                    onChange={(e) =>
                                        form.setData(
                                            'description',
                                            e.target.value,
                                        )
                                    }
                                />
                                {form.errors.description && (
                                    <p className="text-xs text-destructive">
                                        {form.errors.description}
                                    </p>
                                )}
                            </div>
                            {field(
                                'theme',
                                'Theme',
                                'The world the story happens in (e.g. forest, pirates).',
                            )}
                            <div className="grid grid-cols-3 gap-4">
                                {numberField('age_min', 'Age min')}
                                {numberField('age_max', 'Age max')}
                                {numberField(
                                    'page_count',
                                    'Pages',
                                    `${pageBounds.min}-${pageBounds.max} (admin setting)`,
                                )}
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Cover</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {field(
                                'cover_image_url',
                                'Cover image URL',
                                'Real covers live at /images/templates/{slug}.jpg; a data: SVG placeholder is also fine.',
                            )}
                            {form.data.cover_image_url && (
                                <div className="h-40 w-32 overflow-hidden rounded-lg border border-card-border">
                                    <img
                                        src={form.data.cover_image_url}
                                        alt=""
                                        className="h-full w-full object-cover"
                                    />
                                </div>
                            )}
                            <div className="space-y-1.5">
                                <Label>Cover image prompt</Label>
                                <Textarea
                                    rows={4}
                                    value={form.data.image_prompt}
                                    onChange={(e) =>
                                        form.setData(
                                            'image_prompt',
                                            e.target.value,
                                        )
                                    }
                                />
                                <p className="text-xs text-muted-foreground">
                                    Used to generate the template's cover art.
                                </p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Wizard options</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <ChipInput
                                label="Life lessons"
                                values={form.data.life_lessons}
                                onChange={(next) =>
                                    form.setData('life_lessons', next)
                                }
                                error={form.errors.life_lessons}
                            />
                            <ChipInput
                                label="Subjects"
                                values={form.data.subjects}
                                onChange={(next) =>
                                    form.setData('subjects', next)
                                }
                                error={form.errors.subjects}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Presentation</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <ToggleChips
                                label="Art styles"
                                options={artStyleOptions}
                                values={form.data.art_styles}
                                onChange={(next) =>
                                    form.setData('art_styles', next)
                                }
                                error={form.errors.art_styles}
                            />
                            <ToggleChips
                                label="Fonts"
                                options={fontOptions}
                                values={form.data.fonts}
                                onChange={(next) => form.setData('fonts', next)}
                                error={form.errors.fonts}
                            />
                        </CardContent>
                    </Card>
                </div>
            </form>
        </>
    );
}
