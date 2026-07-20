import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    FileText,
    HeartPulse,
    Loader2,
    Palette,
    Play,
    RotateCcw,
    Square,
    Trash2,
    X,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type JournalEntry = {
    id: number;
    purpose: string;
    pageNumber: number | null;
    attempt: number;
    variant: string;
    accepted: boolean;
    prompt: string;
    createdAt: string;
};

type Props = {
    book: {
        id: number;
        childName: string;
        userEmail: string;
        ageRange: string;
        theme: string;
        subject: string;
        lifeLesson: string;
        artStyle: string;
        language: string;
        status: string;
        paid: boolean;
        coverImageUrl: string | null;
        coverStatus: string | null;
        storyBible: Record<string, unknown> | null;
        createdAt: string;
        pages: {
            pageNumber: number;
            status: string;
            imageUrl: string | null;
        }[];
    };
    journal: JournalEntry[];
    artStyles: string[];
    versions: ImageVersionItem[];
    engines: {
        currentProvider: string;
        models: Record<string, string>;
        replicate: ReplicateEngineOption[];
        coverProvider: string;
        coverModel: string;
    };
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

type ImageVersionItem = {
    id: number;
    slot: string;
    url: string;
    active: boolean;
    engine: string;
    prompt: string;
    createdAt: string;
};

function JournalRow({ entry }: { entry: JournalEntry }) {
    const [open, setOpen] = useState(false);

    const label =
        entry.purpose === 'page'
            ? `page ${entry.pageNumber ?? '?'}`
            : entry.purpose;

    return (
        <div className="rounded-lg border border-card-border">
            <button
                type="button"
                onClick={() => setOpen((value) => !value)}
                className="flex w-full items-center gap-3 p-3 text-start text-sm"
            >
                <ChevronDown
                    className={`h-4 w-4 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`}
                />
                <span className="font-medium">{label}</span>
                <span className="text-xs text-muted-foreground">
                    attempt {entry.attempt} - {entry.variant}
                </span>
                {entry.accepted && (
                    <Badge className="ms-auto border-0 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400">
                        <Check className="me-1 h-3 w-3" /> accepted
                    </Badge>
                )}
            </button>
            {open && (
                <pre className="overflow-x-auto border-t border-card-border bg-muted/30 p-3 text-xs whitespace-pre-wrap">
                    {entry.prompt}
                </pre>
            )}
        </div>
    );
}

type GalleryItem = { url: string; caption: string; prompt?: string };

/**
 * Full-screen viewer for the book's images: click-through with prev/next
 * buttons, arrow keys and Escape.
 */
function ImageLightbox({
    items,
    index,
    onClose,
    onNavigate,
}: {
    items: GalleryItem[];
    index: number;
    onClose: () => void;
    onNavigate: (index: number) => void;
}) {
    const [showPrompt, setShowPrompt] = useState(false);

    const step = useCallback(
        (delta: number) =>
            onNavigate((index + delta + items.length) % items.length),
        [index, items.length, onNavigate],
    );

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                onClose();
            }

            if (e.key === 'ArrowRight') {
                step(1);
            }

            if (e.key === 'ArrowLeft') {
                step(-1);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [onClose, step]);

    const item = items[index];

    if (!item) {
        return null;
    }

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/90 p-4"
            onClick={onClose}
        >
            <button
                type="button"
                aria-label="Close"
                onClick={onClose}
                className="absolute end-4 top-4 rounded-full bg-white/10 p-2 text-white transition-colors hover:bg-white/25"
            >
                <X className="h-5 w-5" />
            </button>

            {items.length > 1 && (
                <button
                    type="button"
                    aria-label="Previous image"
                    onClick={(e) => {
                        e.stopPropagation();
                        step(-1);
                    }}
                    className="absolute start-4 rounded-full bg-white/10 p-3 text-white transition-colors hover:bg-white/25"
                >
                    <ChevronLeft className="h-6 w-6" />
                </button>
            )}

            <figure
                className="flex max-h-full flex-col items-center gap-3"
                onClick={(e) => e.stopPropagation()}
            >
                <img
                    src={item.url}
                    alt={item.caption}
                    className={`max-w-[92vw] rounded-lg object-contain shadow-2xl ${showPrompt && item.prompt ? 'max-h-[52vh]' : 'max-h-[86vh]'}`}
                />
                <figcaption className="flex items-center gap-3 text-sm text-white/85">
                    {item.caption} - {index + 1}/{items.length}
                    {item.prompt && (
                        <button
                            type="button"
                            onClick={() => setShowPrompt((value) => !value)}
                            className="rounded-full bg-white/10 px-3 py-1 text-xs text-white transition-colors hover:bg-white/25"
                        >
                            {showPrompt ? 'Hide prompt' : 'Prompt'}
                        </button>
                    )}
                </figcaption>
                {showPrompt && item.prompt && (
                    <pre className="max-h-[30vh] max-w-[92vw] overflow-auto rounded-lg bg-white/10 p-4 text-xs leading-relaxed whitespace-pre-wrap text-white/90">
                        {item.prompt}
                    </pre>
                )}
            </figure>

            {items.length > 1 && (
                <button
                    type="button"
                    aria-label="Next image"
                    onClick={(e) => {
                        e.stopPropagation();
                        step(1);
                    }}
                    className="absolute end-4 rounded-full bg-white/10 p-3 text-white transition-colors hover:bg-white/25"
                >
                    <ChevronRight className="h-6 w-6" />
                </button>
            )}
        </div>
    );
}

export default function AdminBookShow({
    book,
    journal,
    artStyles,
    versions,
    engines,
}: Props) {
    const { errors } = usePage().props as { errors: Record<string, string> };
    const [restyleTo, setRestyleTo] = useState(book.artStyle);
    const [lightbox, setLightbox] = useState<{
        items: GalleryItem[];
        index: number;
    } | null>(null);

    // The single regenerate popup: which image is being regenerated, plus the
    // one-off art style and Replicate model chosen for it. Both default to
    // "as the book is now" / "current settings". Replicate models only.
    const [regenTarget, setRegenTarget] = useState<string | null>(null);
    const [regenStyle, setRegenStyle] = useState(book.artStyle);
    const [regenModel, setRegenModel] = useState('default');

    const replicateModels = engines.replicate ?? [];

    const openRegenerate = (target: string) => {
        setRegenStyle(book.artStyle);
        setRegenModel('default');
        setRegenTarget(target);
    };

    const submitRegenerate = () => {
        if (regenTarget === null) {
            return;
        }

        const chosen = replicateModels.find((m) => m.model === regenModel);

        act('images/regenerate', {
            target: regenTarget,
            ...(chosen
                ? { provider: chosen.provider, model: chosen.model }
                : {}),
            ...(regenStyle !== book.artStyle ? { artStyle: regenStyle } : {}),
        });

        setRegenTarget(null);
    };

    // One shared confirmation for every generation-triggering action, always
    // naming the exact engine + model that will run.
    const [confirmAction, setConfirmAction] = useState<{
        title: string;
        description: string;
        run: () => void;
    } | null>(null);

    // The engine label for the current stored settings.
    const settingsEngineLabel = `${engines.currentProvider} - ${engines.models[engines.currentProvider] ?? ''}`;

    // While anything is generating, keep the page fresh so status changes
    // (generating -> complete/failed) and new versions show up on their own.
    const anythingGenerating =
        book.status === 'generating' ||
        book.coverStatus === 'generating' ||
        book.pages.some((page) => page.status === 'generating');

    useEffect(() => {
        if (!anythingGenerating) {
            return;
        }

        const timer = setInterval(
            () => router.reload({ only: ['book', 'versions'] }),
            5000,
        );

        return () => clearInterval(timer);
    }, [anythingGenerating]);
    const canRescue = ['pending', 'generating', 'failed'].includes(book.status);

    // The version record behind a slot's ACTIVE image (undefined for images
    // from before version tracking).
    const activeVersionFor = (slot: string): ImageVersionItem | undefined =>
        versions.find((v) => v.slot === slot && v.active);

    const engineFor = (slot: string): string => {
        const engine = activeVersionFor(slot)?.engine ?? '';

        return engine !== '' ? ` - ${engine}` : '';
    };

    // Every image on the book, in reading order, for the lightbox, each
    // captioned with the model that generated it and carrying its prompt.
    const gallery: GalleryItem[] = [
        ...(book.coverImageUrl
            ? [
                  {
                      url: book.coverImageUrl,
                      caption: `Cover${engineFor('cover')}`,
                      prompt: activeVersionFor('cover')?.prompt,
                  },
              ]
            : []),
        ...book.pages
            .filter((page) => page.imageUrl !== null)
            .map((page) => ({
                url: String(page.imageUrl),
                caption: `Page ${page.pageNumber}${engineFor(`page-${page.pageNumber}`)}`,
                prompt: activeVersionFor(`page-${page.pageNumber}`)?.prompt,
            })),
    ];

    const openImage = (url: string) => {
        const index = gallery.findIndex((item) => item.url === url);

        if (index !== -1) {
            setLightbox({ items: gallery, index });
        }
    };

    // Versions grouped per slot, covers first, then sheet, then pages in order.
    const slotOrder = (slot: string) =>
        slot === 'cover' ? -2 : slot === 'sheet' ? -1 : Number(slot.slice(5));
    const versionSlots = [...new Set(versions.map((v) => v.slot))].sort(
        (a, b) => slotOrder(a) - slotOrder(b),
    );

    const openVersion = (slot: string, id: number) => {
        const items = versions
            .filter((v) => v.slot === slot)
            .map((v) => ({
                url: v.url,
                caption: `${slot} - ${v.createdAt}${v.engine ? ` - ${v.engine}` : ''}${v.active ? ' (active)' : ''}`,
                prompt: v.prompt,
            }));
        const index = versions
            .filter((v) => v.slot === slot)
            .findIndex((v) => v.id === id);

        if (index !== -1) {
            setLightbox({ items, index });
        }
    };

    const act = (action: string, data: Record<string, string> = {}) => {
        router.post(`/admin/books/${book.id}/${action}`, data, {
            preserveScroll: true,
        });
    };

    return (
        <>
            <Head title={`Book #${book.id} - Admin`} />
            <div className="space-y-6 p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="font-serif text-2xl font-semibold">
                            #{book.id} - {book.childName}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {book.userEmail} - {book.artStyle} - {book.language}{' '}
                            - {book.status}
                            {book.paid ? ' - paid' : ''} - {book.createdAt}
                        </p>
                        {errors.book && (
                            <p className="mt-1 text-sm text-destructive">
                                {errors.book}
                            </p>
                        )}
                        {errors.artStyle && (
                            <p className="mt-1 text-sm text-destructive">
                                {errors.artStyle}
                            </p>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild size="sm" variant="outline">
                            <Link href={`/books/${book.id}`}>
                                <BookOpen className="h-4 w-4" /> Reader
                            </Link>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <a
                                href={`/admin/books/${book.id}/log`}
                                target="_blank"
                                rel="noopener"
                            >
                                <FileText className="h-4 w-4" /> Log
                            </a>
                        </Button>
                        {anythingGenerating && (
                            <Button
                                size="sm"
                                variant="destructive"
                                onClick={() =>
                                    setConfirmAction({
                                        title: `Stop book #${book.id}?`,
                                        description:
                                            'The pipeline halts before generating the next image (the one in flight still finishes). The book flips to failed and Resume continues it later, keeping every finished image.',
                                        run: () => act('stop'),
                                    })
                                }
                            >
                                <Square className="h-4 w-4" /> Stop
                            </Button>
                        )}
                        {canRescue && (
                            <Button
                                size="sm"
                                onClick={() =>
                                    setConfirmAction({
                                        title: `Resume book #${book.id}?`,
                                        description: `Missing images will be generated with ${settingsEngineLabel}. Finished images are kept.`,
                                        run: () => act('resume'),
                                    })
                                }
                            >
                                <Play className="h-4 w-4" /> Resume
                            </Button>
                        )}
                        {/* Full restart works on any status; Resume above is
                            the keep-progress variant. */}
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setConfirmAction({
                                    title: `Restart book #${book.id} from scratch?`,
                                    description: `The story and all image pointers are wiped (old images stay as versions), then a full new run generates everything with ${settingsEngineLabel}. Use Resume instead to keep finished images.`,
                                    run: () => act('restart'),
                                })
                            }
                        >
                            <RotateCcw className="h-4 w-4" /> Restart
                        </Button>
                        {book.status !== 'complete' && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => act('heal')}
                            >
                                <HeartPulse className="h-4 w-4" /> Mark complete
                            </Button>
                        )}
                        <div className="flex items-center gap-1">
                            <Select value={restyleTo} onValueChange={setRestyleTo}>
                                <SelectTrigger className="h-9 w-40">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {artStyles.map((style) => (
                                        <SelectItem key={style} value={style}>
                                            {style}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    setConfirmAction({
                                        title: `Restyle book #${book.id} to ${restyleTo}?`,
                                        description: `Every image regenerates in the ${restyleTo} style with ${settingsEngineLabel}. The story is kept, and current images stay as versions.${anythingGenerating ? ' The pipeline running right now is stopped first; the restyle starts after it halts.' : ''}`,
                                        run: () =>
                                            act('restyle', {
                                                artStyle: restyleTo,
                                            }),
                                    })
                                }
                            >
                                <Palette className="h-4 w-4" /> Restyle
                            </Button>
                        </div>
                        <AlertDialog>
                            <AlertDialogTrigger asChild>
                                <Button size="sm" variant="destructive">
                                    <Trash2 className="h-4 w-4" /> Delete
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>
                                        Delete book #{book.id}?
                                    </AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Removes the book, its pages and every
                                        generated image. This cannot be undone.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>
                                        Cancel
                                    </AlertDialogCancel>
                                    <AlertDialogAction
                                        onClick={() =>
                                            router.delete(
                                                `/admin/books/${book.id}`,
                                            )
                                        }
                                    >
                                        Delete
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Story</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            <p>
                                <span className="text-muted-foreground">
                                    Theme:
                                </span>{' '}
                                {book.theme}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    Subject:
                                </span>{' '}
                                {book.subject}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    Lesson:
                                </span>{' '}
                                {book.lifeLesson}
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    Age:
                                </span>{' '}
                                {book.ageRange}
                            </p>
                            {book.storyBible?.subtitle != null && (
                                <p>
                                    <span className="text-muted-foreground">
                                        Subtitle:
                                    </span>{' '}
                                    {String(book.storyBible.subtitle)}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <CardTitle>Pages</CardTitle>
                                <CardDescription>
                                    Click the refresh icon on any image to
                                    regenerate it, choosing a style and Replicate
                                    model just for that image.
                                </CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="flex flex-wrap gap-2">
                                {book.coverImageUrl && (
                                    <div className="w-28">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                openImage(
                                                    String(book.coverImageUrl),
                                                )
                                            }
                                            className="block aspect-[3/2] w-full cursor-zoom-in overflow-hidden rounded-md border border-gold/50 bg-muted focus-visible:ring-2 focus-visible:ring-gold focus-visible:outline-none"
                                        >
                                            <img
                                                src={book.coverImageUrl}
                                                alt="Cover"
                                                className="h-full w-full object-cover transition-transform hover:scale-105"
                                            />
                                        </button>
                                        <p className="mt-1 flex items-center justify-center gap-1 text-center text-xs text-muted-foreground">
                                            cover
                                            {book.coverStatus !== null &&
                                                ` - ${book.coverStatus}`}
                                            <button
                                                type="button"
                                                title="Regenerate cover"
                                                aria-label="Regenerate cover"
                                                disabled={
                                                    book.coverStatus ===
                                                    'generating'
                                                }
                                                onClick={() =>
                                                    openRegenerate('cover')
                                                }
                                                className="rounded p-0.5 text-gold hover:bg-gold/15 disabled:cursor-wait"
                                            >
                                                {book.coverStatus ===
                                                'generating' ? (
                                                    <Loader2 className="h-3 w-3 animate-spin" />
                                                ) : (
                                                    <RotateCcw className="h-3 w-3" />
                                                )}
                                            </button>
                                        </p>
                                    </div>
                                )}
                                {book.pages.map((page) => (
                                    <div key={page.pageNumber} className="w-28">
                                        <div className="aspect-[3/2] overflow-hidden rounded-md border border-card-border bg-muted">
                                            {page.imageUrl && (
                                                <button
                                                    type="button"
                                                    onClick={() =>
                                                        openImage(
                                                            String(
                                                                page.imageUrl,
                                                            ),
                                                        )
                                                    }
                                                    className="block h-full w-full cursor-zoom-in focus-visible:ring-2 focus-visible:ring-gold focus-visible:outline-none"
                                                >
                                                    <img
                                                        src={page.imageUrl}
                                                        alt={`Page ${page.pageNumber}`}
                                                        className="h-full w-full object-cover transition-transform hover:scale-105"
                                                    />
                                                </button>
                                            )}
                                        </div>
                                        <p className="mt-1 flex items-center justify-center gap-1 text-center text-xs text-muted-foreground">
                                            p{page.pageNumber} - {page.status}
                                            <button
                                                type="button"
                                                title={`Regenerate page ${page.pageNumber}`}
                                                aria-label={`Regenerate page ${page.pageNumber}`}
                                                disabled={
                                                    page.status === 'generating'
                                                }
                                                onClick={() =>
                                                    openRegenerate(
                                                        `page-${page.pageNumber}`,
                                                    )
                                                }
                                                className="rounded p-0.5 text-gold hover:bg-gold/15 disabled:cursor-wait"
                                            >
                                                {page.status ===
                                                'generating' ? (
                                                    <Loader2 className="h-3 w-3 animate-spin" />
                                                ) : (
                                                    <RotateCcw className="h-3 w-3" />
                                                )}
                                            </button>
                                        </p>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {versions.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Image versions ({versions.length})
                            </CardTitle>
                            <CardDescription>
                                Every image ever generated for this book stays
                                available. Restore points a slot back at an
                                older file; nothing is deleted.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {versionSlots.map((slot) => (
                                <div key={slot}>
                                    <p className="mb-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                        {slot}
                                    </p>
                                    <div className="flex flex-wrap gap-2">
                                        {versions
                                            .filter((v) => v.slot === slot)
                                            .map((version) => (
                                                <div
                                                    key={version.id}
                                                    className="w-24"
                                                >
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            openVersion(
                                                                slot,
                                                                version.id,
                                                            )
                                                        }
                                                        className={`block aspect-[3/2] w-full cursor-zoom-in overflow-hidden rounded-md bg-muted focus-visible:ring-2 focus-visible:ring-gold focus-visible:outline-none ${
                                                            version.active
                                                                ? 'ring-2 ring-gold'
                                                                : 'border border-card-border'
                                                        }`}
                                                    >
                                                        <img
                                                            src={version.url}
                                                            alt={`${slot} version ${version.id}`}
                                                            className="h-full w-full object-cover transition-transform hover:scale-105"
                                                        />
                                                    </button>
                                                    {version.engine !== '' && (
                                                        <p
                                                            title={
                                                                version.engine
                                                            }
                                                            className="mt-0.5 truncate text-center text-[9px] text-muted-foreground"
                                                        >
                                                            {version.engine}
                                                        </p>
                                                    )}
                                                    {version.active ? (
                                                        <p className="mt-0.5 text-center text-[10px] font-semibold text-gold">
                                                            active
                                                        </p>
                                                    ) : (
                                                        <button
                                                            type="button"
                                                            onClick={() =>
                                                                act(
                                                                    'images/restore',
                                                                    {
                                                                        versionId:
                                                                            String(
                                                                                version.id,
                                                                            ),
                                                                    },
                                                                )
                                                            }
                                                            className="mt-0.5 w-full rounded text-center text-[10px] text-muted-foreground hover:bg-gold/15 hover:text-gold"
                                                        >
                                                            Restore
                                                        </button>
                                                    )}
                                                </div>
                                            ))}
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle>
                            Prompt journal ({journal.length} attempts)
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {journal.map((entry) => (
                            <JournalRow key={entry.id} entry={entry} />
                        ))}
                        {journal.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No prompts recorded for this book.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>

            {/* Per-image regenerate: pick a one-off art style and Replicate
                model for just this cover/page. */}
            <Dialog
                open={regenTarget !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRegenTarget(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Regenerate{' '}
                            {regenTarget === 'cover'
                                ? 'the cover'
                                : regenTarget?.replace('page-', 'page ')}
                        </DialogTitle>
                        <DialogDescription>
                            Only this image is regenerated. The book keeps its
                            saved style, every other image is untouched, and the
                            current image stays available as a version.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-4 py-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="regen-style">Art style</Label>
                            <Select
                                value={regenStyle}
                                onValueChange={setRegenStyle}
                            >
                                <SelectTrigger id="regen-style">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {artStyles.map((style) => (
                                        <SelectItem key={style} value={style}>
                                            {style}
                                            {style === book.artStyle
                                                ? ' (book style)'
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-1.5">
                            <Label htmlFor="regen-model">Replicate model</Label>
                            <Select
                                value={regenModel}
                                onValueChange={setRegenModel}
                            >
                                <SelectTrigger id="regen-model">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="default">
                                        Current settings ({settingsEngineLabel})
                                    </SelectItem>
                                    {replicateModels.map((option) => (
                                        <SelectItem
                                            key={option.model}
                                            value={option.model}
                                        >
                                            {option.label} ({option.cost})
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setRegenTarget(null)}
                        >
                            Cancel
                        </Button>
                        <Button variant="gold" onClick={submitRegenerate}>
                            <RotateCcw className="h-4 w-4" /> Generate
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* The shared "are you sure, with THIS engine" gate for every
                generation-triggering action. */}
            <AlertDialog
                open={confirmAction !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setConfirmAction(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {confirmAction?.title}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {confirmAction?.description}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                confirmAction?.run();
                                setConfirmAction(null);
                            }}
                        >
                            Confirm
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            {lightbox !== null && (
                <ImageLightbox
                    items={lightbox.items}
                    index={lightbox.index}
                    onClose={() => setLightbox(null)}
                    onNavigate={(index) =>
                        setLightbox({ items: lightbox.items, index })
                    }
                />
            )}
        </>
    );
}
