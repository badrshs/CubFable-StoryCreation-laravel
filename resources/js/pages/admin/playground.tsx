import { Head } from '@inertiajs/react';
import { Copy, FlaskConical, ImageIcon, Loader2, Type } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Providers = {
    textProvider: string;
    textModel: string;
    imageProvider: string;
    imageModel: string;
};

type Props = {
    templates: {
        id: number;
        title: string;
        theme: string;
        pageCount: number;
    }[];
    books: {
        id: number;
        childName: string;
        artStyle: string;
        status: string;
    }[];
    providers: Providers;
    artStyles: string[];
    ageRanges: string[];
    languages: string[];
};

type Prompts = {
    blueprint: string;
    sheet: string | null;
    cover: string;
    page: string;
};

async function post<T>(url: string, body: unknown): Promise<T> {
    const token = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((row) => row.startsWith('XSRF-TOKEN='))
            ?.split('=')[1] ?? '',
    );

    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': token,
            Accept: 'application/json',
        },
        body: JSON.stringify(body),
    });

    if (!response.ok) {
        const detail = (await response.json().catch(() => null)) as {
            message?: string;
        } | null;

        throw new Error(detail?.message ?? `HTTP ${response.status}`);
    }

    return (await response.json()) as T;
}

function PromptBlock({ title, prompt }: { title: string; prompt: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <Card>
            <CardHeader className="flex-row items-center justify-between">
                <CardTitle className="text-sm">{title}</CardTitle>
                <Button
                    size="sm"
                    variant="ghost"
                    onClick={() => {
                        void navigator.clipboard.writeText(prompt);
                        setCopied(true);
                        setTimeout(() => setCopied(false), 1500);
                    }}
                >
                    <Copy className="h-3.5 w-3.5" />
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </CardHeader>
            <CardContent>
                <pre className="max-h-72 overflow-auto rounded-lg bg-muted/40 p-3 text-xs whitespace-pre-wrap">
                    {prompt}
                </pre>
            </CardContent>
        </Card>
    );
}

export default function AdminPlayground({
    templates,
    books,
    providers,
    artStyles,
    ageRanges,
    languages,
}: Props) {
    const [mode, setMode] = useState<'book' | 'sample'>(
        books.length > 0 ? 'book' : 'sample',
    );
    const [bookId, setBookId] = useState(books[0]?.id ?? 0);
    const [templateId, setTemplateId] = useState(templates[0]?.id ?? 0);
    const [childName, setChildName] = useState('Luna');
    const [ageRange, setAgeRange] = useState('4-6');
    const [artStyle, setArtStyle] = useState(artStyles[0] ?? 'storybook');
    const [language, setLanguage] = useState('en');

    const [prompts, setPrompts] = useState<Prompts | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [textResult, setTextResult] = useState<string | null>(null);
    const [imageResult, setImageResult] = useState<string | null>(null);

    const preview = async () => {
        setBusy('preview');
        setError(null);

        try {
            const result = await post<{ prompts: Prompts }>(
                '/admin/playground/preview',
                mode === 'book'
                    ? { bookId }
                    : { templateId, childName, ageRange, artStyle, language },
            );

            setPrompts(result.prompts);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Preview failed');
        } finally {
            setBusy(null);
        }
    };

    const runText = async () => {
        if (!prompts) {
            return;
        }

        setBusy('text');
        setError(null);

        try {
            const result = await post<{ content: string }>(
                '/admin/playground/run-text',
                { prompt: prompts.blueprint },
            );

            setTextResult(result.content);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Text run failed');
        } finally {
            setBusy(null);
        }
    };

    const runImage = async () => {
        if (!prompts) {
            return;
        }

        setBusy('image');
        setError(null);

        try {
            const result = await post<{ dataUrl: string }>(
                '/admin/playground/run-image',
                { prompt: prompts.cover, size: '1024x1536' },
            );

            setImageResult(result.dataUrl);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Image run failed');
        } finally {
            setBusy(null);
        }
    };

    return (
        <>
            <Head title="Playground - Admin" />
            <div className="space-y-6 p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="flex items-center gap-2 font-serif text-2xl font-semibold">
                            <FlaskConical className="h-5 w-5" /> Prompt
                            playground
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            Preview is free; the run buttons make one real, paid
                            provider call.
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-1.5">
                        <Badge variant="secondary">
                            text: {providers.textProvider}/{providers.textModel}
                        </Badge>
                        <Badge variant="secondary">
                            image: {providers.imageProvider}/
                            {providers.imageModel}
                        </Badge>
                    </div>
                </div>

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-4 p-5">
                        <div className="space-y-1.5">
                            <Label>Source</Label>
                            <Select
                                value={mode}
                                onValueChange={(value) =>
                                    setMode(value as 'book' | 'sample')
                                }
                            >
                                <SelectTrigger className="w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="book">
                                        Existing book
                                    </SelectItem>
                                    <SelectItem value="sample">
                                        Sample inputs
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        {mode === 'book' ? (
                            <div className="space-y-1.5">
                                <Label>Book</Label>
                                <Select
                                    value={String(bookId)}
                                    onValueChange={(value) =>
                                        setBookId(Number(value))
                                    }
                                >
                                    <SelectTrigger className="w-64">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {books.map((book) => (
                                            <SelectItem
                                                key={book.id}
                                                value={String(book.id)}
                                            >
                                                #{book.id} {book.childName} (
                                                {book.artStyle}, {book.status})
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        ) : (
                            <>
                                <div className="space-y-1.5">
                                    <Label>Template</Label>
                                    <Select
                                        value={String(templateId)}
                                        onValueChange={(value) =>
                                            setTemplateId(Number(value))
                                        }
                                    >
                                        <SelectTrigger className="w-56">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {templates.map((template) => (
                                                <SelectItem
                                                    key={template.id}
                                                    value={String(template.id)}
                                                >
                                                    {template.title}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Child</Label>
                                    <Input
                                        className="w-32"
                                        value={childName}
                                        onChange={(e) =>
                                            setChildName(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Age</Label>
                                    <Select
                                        value={ageRange}
                                        onValueChange={setAgeRange}
                                    >
                                        <SelectTrigger className="w-24">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {ageRanges.map((range) => (
                                                <SelectItem
                                                    key={range}
                                                    value={range}
                                                >
                                                    {range}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Style</Label>
                                    <Select
                                        value={artStyle}
                                        onValueChange={setArtStyle}
                                    >
                                        <SelectTrigger className="w-40">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {artStyles.map((style) => (
                                                <SelectItem
                                                    key={style}
                                                    value={style}
                                                >
                                                    {style}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div className="space-y-1.5">
                                    <Label>Language</Label>
                                    <Select
                                        value={language}
                                        onValueChange={setLanguage}
                                    >
                                        <SelectTrigger className="w-24">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {languages.map((lang) => (
                                                <SelectItem
                                                    key={lang}
                                                    value={lang}
                                                >
                                                    {lang}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                            </>
                        )}

                        <Button
                            onClick={() => void preview()}
                            disabled={busy !== null}
                        >
                            {busy === 'preview' && (
                                <Loader2 className="h-4 w-4 animate-spin" />
                            )}
                            Preview prompts (free)
                        </Button>
                    </CardContent>
                </Card>

                {error && <p className="text-sm text-destructive">{error}</p>}

                {prompts && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                onClick={() => void runText()}
                                disabled={busy !== null}
                            >
                                {busy === 'text' ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <Type className="h-4 w-4" />
                                )}
                                Run text test (paid)
                            </Button>
                            <Button
                                variant="outline"
                                onClick={() => void runImage()}
                                disabled={busy !== null}
                            >
                                {busy === 'image' ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <ImageIcon className="h-4 w-4" />
                                )}
                                Run image test on cover prompt (paid)
                            </Button>
                        </div>

                        <div className="grid gap-4 lg:grid-cols-2">
                            <PromptBlock
                                title="Story blueprint (text model)"
                                prompt={prompts.blueprint}
                            />
                            <PromptBlock
                                title="Cover (image model)"
                                prompt={prompts.cover}
                            />
                            {prompts.sheet && (
                                <PromptBlock
                                    title="Character sheet (image model)"
                                    prompt={prompts.sheet}
                                />
                            )}
                            <PromptBlock
                                title="Page (image model)"
                                prompt={prompts.page}
                            />
                        </div>
                    </>
                )}

                {(textResult || imageResult) && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        {textResult && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-sm">
                                        Text result
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <pre className="max-h-96 overflow-auto rounded-lg bg-muted/40 p-3 text-xs whitespace-pre-wrap">
                                        {textResult}
                                    </pre>
                                </CardContent>
                            </Card>
                        )}
                        {imageResult && (
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-sm">
                                        Image result
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <img
                                        src={imageResult}
                                        alt="Playground result"
                                        className="max-h-96 rounded-lg border border-card-border"
                                    />
                                </CardContent>
                            </Card>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}
