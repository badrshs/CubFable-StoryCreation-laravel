import { Link, router, usePoll } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import {
    AlertCircle,
    BookOpen,
    CheckCircle2,
    Loader2,
    Moon,
    Plus,
    RefreshCw,
    Sparkles,
    Wand2,
} from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import BookCover from '@/components/cubfable/book-cover';
import Starfield from '@/components/cubfable/starfield';
import { Button } from '@/components/ui/button';
import { useT } from '@/i18n';
import { easeOutSoft, fadeUp, staggerContainer } from '@/lib/motion';
import bookRoutes from '@/routes/books';
import checkoutRoutes from '@/routes/checkout';
import templateRoutes from '@/routes/templates';
import type { Book } from '@/types';

// The gallery also receives the async cover-regeneration state so a redraw
// queued on a previous visit still veils the cover.
type GalleryBook = Book & { coverStatus: string | null };

type GalleryProps = {
    books: GalleryBook[];
};

// A book that is still being written/drawn shows a gentle "in progress" state.
const IN_PROGRESS: ReadonlyArray<Book['status']> = ['pending', 'generating'];

// Kind, on-brand status wording. Colors come from tokens so both themes read
// intentionally (no hard-coded light-only palette).
function StatusBadge({ status }: { status: Book['status'] }) {
    const t = useT();
    const cfg: Record<
        string,
        { label: string; className: string; icon: ReactNode }
    > = {
        draft: {
            label: t('gallery.statusDraft'),
            className:
                'bg-muted text-muted-foreground border border-card-border',
            icon: <Moon className="h-3 w-3" />,
        },
        pending: {
            label: t('gallery.statusQueued'),
            className:
                'bg-gold/15 text-gold-foreground dark:text-gold border border-gold/40',
            icon: <Moon className="h-3 w-3" />,
        },
        generating: {
            label: t('gallery.statusCreating'),
            className: 'bg-primary/15 text-primary border border-primary/40',
            icon: <Loader2 className="h-3 w-3 animate-spin" />,
        },
        complete: {
            label: t('gallery.statusReady'),
            className:
                'bg-secondary text-secondary-foreground border border-card-border',
            icon: <CheckCircle2 className="h-3 w-3 text-gold" />,
        },
        failed: {
            label: t('gallery.statusFailed'),
            className: 'bg-rose/15 text-rose border border-rose/40',
            icon: <AlertCircle className="h-3 w-3" />,
        },
    };
    const c = cfg[status] ?? cfg.pending;

    return (
        <span
            className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 font-display text-[11px] font-semibold tracking-wide ${c.className}`}
        >
            {c.icon}
            {c.label}
        </span>
    );
}

// The starlit ledge each keepsake rests on: a slim luminous shelf with a
// grounded glow, replacing the old wooden plank with a twilight-world object.
function StarShelf() {
    return (
        <div className="relative mt-3 h-px w-full" aria-hidden>
            <div className="h-px w-full bg-gradient-to-r from-transparent via-gold/60 to-transparent" />
            <div className="mx-auto h-4 w-4/5 rounded-b-full bg-primary/25 blur-md dark:bg-black/40" />
        </div>
    );
}

// One keepsake in the library: its cover in a glowing niche, a live status,
// and a gold "redraw the cover" affordance revealed on hover/focus. Each book
// owns its own regeneration state.
function ShelfBook({ book, index }: { book: GalleryBook; index: number }) {
    const t = useT();
    const [regenerating, setRegenerating] = useState(false);

    // Redrawing covers both the in-flight request and the queued job reported
    // by the server on later reloads.
    const redrawing = regenerating || book.coverStatus === 'generating';

    const onRegenerate = (e: MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();

        if (redrawing) {
            return;
        }

        router.post(
            bookRoutes.regenerateCover.url(book.id),
            {},
            {
                preserveScroll: true,
                onStart: () => setRegenerating(true),
                onFinish: () => setRegenerating(false),
            },
        );
    };

    const inProgress = IN_PROGRESS.includes(book.status);
    const isDraft = String(book.status) === 'draft';

    return (
        <motion.li variants={fadeUp} className="group/card flex flex-col">
            <Link
                href={
                    isDraft
                        ? checkoutRoutes.show(book.id)
                        : bookRoutes.show(book.id)
                }
                aria-label={t('gallery.openStorybook', {
                    name: book.childName,
                })}
                className="relative block rounded-2xl focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                {/* Soft niche glow behind the cover */}
                <div
                    aria-hidden
                    className="pointer-events-none absolute -inset-2 rounded-[1.75rem] bg-gradient-to-b from-primary/10 to-transparent opacity-0 blur-lg transition-opacity duration-500 group-hover/card:opacity-100"
                    style={{ animationDelay: `${index * 0.15}s` }}
                />
                <div className="relative px-1.5">
                    <BookCover coverImageUrl={book.coverImageUrl} />

                    {/* Redraw-cover affordance (hover + keyboard focus reveal) */}
                    <button
                        type="button"
                        onClick={onRegenerate}
                        disabled={redrawing}
                        title={t('gallery.regenerateCover')}
                        aria-label={t('gallery.regenerateCover')}
                        className="hover:glow-gold absolute end-3 top-2 z-10 inline-flex items-center justify-center rounded-full bg-background/80 p-2 text-gold opacity-0 shadow-soft backdrop-blur-sm transition-all duration-300 group-hover/card:opacity-100 hover:bg-background focus-visible:opacity-100 disabled:cursor-wait disabled:opacity-100"
                    >
                        {redrawing ? (
                            <Loader2 className="h-4 w-4 animate-spin" />
                        ) : (
                            <RefreshCw className="h-4 w-4" />
                        )}
                    </button>

                    {/* While actively (re)drawing, veil the cover in twilight */}
                    {(redrawing || inProgress) && (
                        <div className="pointer-events-none absolute inset-0 mx-1.5 flex flex-col items-center justify-center gap-2 overflow-hidden rounded-l-[3px] rounded-r-[6px] bg-primary/70 text-primary-foreground backdrop-blur-[2px]">
                            <Starfield count={16} aurora={false} />
                            <Wand2 className="relative h-7 w-7 animate-float" />
                            <span className="relative px-3 text-center font-display text-xs font-semibold">
                                {redrawing
                                    ? t('gallery.redrawingCover')
                                    : t('gallery.weavingStory')}
                            </span>
                        </div>
                    )}
                </div>
            </Link>

            <StarShelf />

            <div className="flex items-center justify-between gap-2 px-1.5 pt-3">
                <div className="min-w-0">
                    <p className="truncate font-serif text-lg leading-tight font-semibold text-foreground">
                        {book.childName}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {t('gallery.agesLabel', { range: book.ageRange })}
                    </p>
                </div>
                <StatusBadge status={book.status} />
            </div>
        </motion.li>
    );
}

export default function Gallery({ books }: GalleryProps) {
    const t = useT();
    const reduceMotion = useReducedMotion();

    // Poll while anything is still being conjured (including a queued cover
    // redraw), then settle down.
    const { start, stop } = usePoll(
        4000,
        { only: ['books'] },
        { autoStart: false },
    );

    const shouldPoll = books.some(
        (b) => IN_PROGRESS.includes(b.status) || b.coverStatus === 'generating',
    );

    useEffect(() => {
        if (shouldPoll) {
            start();
        } else {
            stop();
        }
        // start/stop proxy one stable poll instance; only the condition matters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [shouldPoll]);

    const hasBooks = books.length > 0;
    const inProgressCount = books.filter((b) =>
        IN_PROGRESS.includes(b.status),
    ).length;

    return (
        <div className="bg-grain relative min-h-[100dvh] overflow-hidden bg-background">
            {/* Twilight sky wash + gentle stars set the keepsake-library mood */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-0 top-0 h-[36rem] bg-gradient-to-b from-primary/12 via-background/0 to-transparent dark:from-primary/25"
            />
            <Starfield count={reduceMotion ? 18 : 40} className="opacity-70" />

            <div className="relative container mx-auto max-w-6xl px-4 py-16">
                {/* Header */}
                <motion.header
                    initial={reduceMotion ? false : { opacity: 0, y: 18 }}
                    animate={{ opacity: 1, y: 0 }}
                    transition={{ duration: 0.6, ease: easeOutSoft }}
                    className="mb-14 flex flex-wrap items-end justify-between gap-6"
                >
                    <div>
                        <span className="mb-4 inline-flex items-center gap-2 rounded-full border border-gold/30 bg-gold/10 px-3 py-1.5 font-display text-sm font-semibold text-gold-foreground dark:text-gold">
                            <Sparkles className="h-4 w-4 text-gold" />
                            {t('gallery.libraryBadge')}
                        </span>
                        <h1 className="font-serif text-4xl leading-tight font-bold text-foreground md:text-5xl">
                            {t('gallery.heading')}
                        </h1>
                        <p className="mt-2 text-lg text-muted-foreground">
                            {hasBooks
                                ? t('gallery.bookCount', {
                                      count: books.length,
                                      plural: books.length !== 1 ? 's' : '',
                                  })
                                : t('gallery.emptyDescription')}
                            {inProgressCount > 0 && (
                                <span className="ms-2 inline-flex items-center gap-1.5 align-middle font-display text-sm font-semibold text-primary">
                                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                                    {t('gallery.conjuringCount', {
                                        count: inProgressCount,
                                    })}
                                </span>
                            )}
                        </p>
                    </div>

                    <Link href={templateRoutes.index()}>
                        <Button
                            variant="gold"
                            size="lg"
                            className="hidden gap-2 rounded-full sm:inline-flex"
                        >
                            <Plus className="h-4 w-4" />
                            {t('gallery.createNewBook')}
                        </Button>
                    </Link>
                </motion.header>

                {/* Empty state: a warm invitation to begin the first keepsake */}
                {!hasBooks && (
                    <motion.div
                        initial={
                            reduceMotion ? false : { opacity: 0, scale: 0.97 }
                        }
                        animate={{ opacity: 1, scale: 1 }}
                        transition={{ duration: 0.6, ease: easeOutSoft }}
                        className="relative mx-auto mt-6 max-w-xl overflow-hidden rounded-3xl border border-card-border bg-card/80 p-10 text-center shadow-lift backdrop-blur-sm"
                    >
                        <Starfield
                            count={reduceMotion ? 10 : 22}
                            aurora={false}
                            className="opacity-60"
                        />
                        <div className="relative">
                            <span className="glow-indigo mx-auto mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-primary/10 text-primary">
                                <BookOpen className="h-9 w-9 animate-float" />
                            </span>
                            <h2 className="font-serif text-3xl font-bold text-foreground">
                                {t('gallery.emptyTitle')}
                            </h2>
                            <p className="mx-auto mt-3 mb-8 max-w-md text-lg text-muted-foreground">
                                {t('gallery.emptyInvite')}
                            </p>
                            <Link href={templateRoutes.index()}>
                                <Button
                                    variant="gold"
                                    size="xl"
                                    className="gap-2 rounded-full"
                                >
                                    <Sparkles className="h-5 w-5" />
                                    {t('gallery.createFirstBook')}
                                </Button>
                            </Link>
                        </div>
                    </motion.div>
                )}

                {/* The library: a personal shelf of keepsakes under a twilight sky */}
                {hasBooks && (
                    <motion.ul
                        variants={staggerContainer(0.06)}
                        initial={reduceMotion ? false : 'hidden'}
                        animate="show"
                        className="grid grid-cols-2 gap-x-6 gap-y-12 sm:grid-cols-3 lg:grid-cols-4"
                    >
                        {books.map((book, i) => (
                            <ShelfBook key={book.id} book={book} index={i} />
                        ))}
                    </motion.ul>
                )}

                {/* Mobile CTA */}
                {hasBooks && (
                    <div className="mt-14 flex justify-center sm:hidden">
                        <Link href={templateRoutes.index()}>
                            <Button
                                variant="gold"
                                className="gap-2 rounded-full"
                            >
                                <Plus className="h-4 w-4" />
                                {t('gallery.createNewBook')}
                            </Button>
                        </Link>
                    </div>
                )}
            </div>
        </div>
    );
}
