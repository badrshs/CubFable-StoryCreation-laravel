import { Link, router, usePoll } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import {
    AlertCircle,
    BookOpen,
    CheckCircle2,
    Loader2,
    Moon,
    Pencil,
    Plus,
    RefreshCw,
    Sparkles,
    Trash2,
} from 'lucide-react';
import type { MouseEvent, ReactNode } from 'react';
import { useEffect, useState } from 'react';
import BookCover from '@/components/cubfable/book-cover';
import Starfield from '@/components/cubfable/starfield';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { useT } from '@/i18n';
import { easeOutSoft, fadeUp, staggerContainer } from '@/lib/motion';
import bookRoutes from '@/routes/books';
import checkoutRoutes from '@/routes/checkout';
import templateRoutes from '@/routes/templates';
import type { Book } from '@/types';

// The gallery receives the async cover-redraw state plus live page progress
// so a book being woven can show exactly how far along it is.
type GalleryBook = Book & {
    coverStatus: string | null;
    pagesTotal: number;
    pagesDone: number;
};

type GalleryProps = {
    books: GalleryBook[];
};

// A book that is still being written/drawn shows the weaving treatment.
const IN_PROGRESS: ReadonlyArray<Book['status']> = ['pending', 'generating'];

// Four keepsakes per shelf; narrower screens scroll each shelf sideways,
// which is how one browses a real bookcase.
const SHELF_SIZE = 4;

function chunk<T>(items: T[], size: number): T[][] {
    const rows: T[][] = [];

    for (let i = 0; i < items.length; i += size) {
        rows.push(items.slice(i, i + size));
    }

    return rows;
}

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
            className={`inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 font-display text-[10px] font-semibold tracking-wide ${c.className}`}
        >
            {c.icon}
            {c.label}
        </span>
    );
}

// The soft golden ribbon that replaces the old full-cover veil: the artwork
// stays visible, and a slim caption + progress thread sits along the book's
// bottom edge like a woven bookmark.
function WeavingRibbon({
    icon,
    caption,
    percent,
}: {
    icon: ReactNode;
    caption: string;
    percent: number | null;
}) {
    return (
        <div className="pointer-events-none absolute inset-x-0 bottom-0 overflow-hidden rounded-l-[3px] rounded-r-[6px]">
            <div className="bg-gradient-to-t from-black/80 via-black/45 to-transparent px-3 pt-7 pb-2.5 text-white">
                <p className="flex items-center gap-1.5 font-display text-[11px] leading-tight font-semibold">
                    {icon}
                    <span className="truncate">{caption}</span>
                </p>
                <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-white/20">
                    {percent === null ? (
                        <div className="h-full w-full animate-shimmer [background-image:linear-gradient(90deg,transparent_20%,hsl(var(--gold))_50%,transparent_80%)] bg-[length:220%_100%]" />
                    ) : (
                        <div
                            className="h-full rounded-full bg-gold transition-[width] duration-700 ease-out"
                            style={{ width: `${Math.max(percent, 4)}%` }}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

// A gentle golden shimmer sweeping across the book face while its art is
// being (re)drawn - light moving over the cover, not a box hiding it.
function ShimmerSweep() {
    return (
        <div
            aria-hidden
            className="pointer-events-none absolute inset-0 animate-shimmer rounded-l-[3px] rounded-r-[6px] [background-image:linear-gradient(105deg,transparent_38%,hsl(var(--gold)/0.18)_50%,transparent_62%)] bg-[length:220%_100%]"
        />
    );
}

// Before the cover exists there is nothing to veil: show the book being
// woven instead - a linen blank with sparkles, a shimmer of lamplight and
// the live page-progress thread.
function LoomBook({
    caption,
    percent,
}: {
    caption: string;
    percent: number | null;
}) {
    return (
        <div className="group/book relative">
            <div
                className="relative aspect-[3/4] w-full overflow-hidden rounded-l-[3px] rounded-r-[6px] border border-card-border bg-gradient-to-br from-card via-secondary/60 to-card"
                style={{
                    boxShadow:
                        '2px 0 0 hsl(var(--card-border)), 4px 0 0 hsl(var(--card)), 14px 18px 26px -12px rgba(28,18,6,0.4)',
                }}
            >
                {/* Cloth binding down the spine, echoing the finished books */}
                <div className="absolute inset-y-0 left-0 w-[6%] bg-gradient-to-r from-black/15 to-transparent" />
                <div className="pointer-events-none absolute inset-[7px] rounded-[3px] border border-gold/25" />

                <div className="flex h-full w-full items-center justify-center">
                    <span className="flex h-14 w-14 items-center justify-center rounded-full bg-gold/10 text-gold">
                        <Sparkles className="h-7 w-7 animate-float" />
                    </span>
                </div>

                <ShimmerSweep />
                <WeavingRibbon
                    icon={<Sparkles className="h-3 w-3 text-gold" />}
                    caption={caption}
                    percent={percent}
                />
            </div>
        </div>
    );
}

// The shelf plank itself: a lit gold edge, a grained wooden face and the
// soft shadow the books cast beneath it.
function ShelfPlank() {
    return (
        <div
            aria-hidden
            className="absolute inset-x-0 bottom-16 z-0 h-5 select-none"
        >
            <div className="h-[3px] w-full rounded-full bg-gradient-to-r from-shelf-edge/20 via-gold/50 to-shelf-edge/20" />
            <div className="relative h-[14px] rounded-b-[5px] bg-gradient-to-b from-shelf-edge via-shelf to-shelf shadow-[0_12px_20px_-8px_rgba(15,8,30,0.5)]">
                <div className="absolute inset-0 rounded-b-[5px] [background-image:repeating-linear-gradient(90deg,rgba(0,0,0,0.18)_0px,rgba(0,0,0,0.18)_1px,transparent_1px,transparent_11px)] opacity-35" />
            </div>
            <div className="mx-8 mt-0.5 h-2 rounded-full bg-black/20 blur-md dark:bg-black/45" />
        </div>
    );
}

// One keepsake standing on the shelf, with its shelf-edge label underneath.
function ShelfBook({ book }: { book: GalleryBook }) {
    const t = useT();
    const [regenerating, setRegenerating] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

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

    const weaving = IN_PROGRESS.includes(book.status);
    const isDraft = String(book.status) === 'draft';
    const hasCover = book.coverImageUrl !== null;

    const percent =
        book.pagesTotal > 0
            ? Math.round((book.pagesDone / book.pagesTotal) * 100)
            : null;
    const weavingCaption =
        book.pagesTotal > 0
            ? t('gallery.weavingPage', {
                  page: Math.min(book.pagesDone + 1, book.pagesTotal),
                  total: book.pagesTotal,
              })
            : t('gallery.weavingStory');

    return (
        <motion.li
            variants={fadeUp}
            className="group/card z-10 flex w-36 shrink-0 snap-start flex-col sm:w-40 md:w-44"
        >
            <Link
                href={
                    isDraft
                        ? checkoutRoutes.show(book.id)
                        : bookRoutes.show(book.id)
                }
                aria-label={t('gallery.openStorybook', {
                    name: book.childName,
                })}
                className="relative -mb-0.5 block rounded-sm focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                {weaving && !hasCover ? (
                    <LoomBook caption={weavingCaption} percent={percent} />
                ) : (
                    <div
                        className={`relative ${isDraft ? 'opacity-90 saturate-[0.75]' : ''}`}
                    >
                        <BookCover coverImageUrl={book.coverImageUrl} />

                        {/* Redraw-cover affordance (hover + keyboard focus reveal) */}
                        {String(book.status) === 'complete' && (
                            <button
                                type="button"
                                onClick={onRegenerate}
                                disabled={redrawing}
                                title={t('gallery.regenerateCover')}
                                aria-label={t('gallery.regenerateCover')}
                                className="hover:glow-gold absolute end-2 top-2 z-10 inline-flex items-center justify-center rounded-full bg-background/80 p-2 text-gold opacity-0 shadow-soft backdrop-blur-sm transition-all duration-300 group-hover/card:opacity-100 hover:bg-background focus-visible:opacity-100 disabled:cursor-wait disabled:opacity-100"
                            >
                                {redrawing ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    <RefreshCw className="h-4 w-4" />
                                )}
                            </button>
                        )}

                        {/* The story is still being woven behind this cover */}
                        {weaving && (
                            <>
                                <ShimmerSweep />
                                <WeavingRibbon
                                    icon={
                                        <Sparkles className="h-3 w-3 text-gold" />
                                    }
                                    caption={weavingCaption}
                                    percent={percent}
                                />
                            </>
                        )}

                        {/* The cover art is being redrawn */}
                        {!weaving && redrawing && (
                            <>
                                <ShimmerSweep />
                                <WeavingRibbon
                                    icon={
                                        <Loader2 className="h-3 w-3 animate-spin text-gold" />
                                    }
                                    caption={t('gallery.redrawingCover')}
                                    percent={null}
                                />
                            </>
                        )}

                        {/* Awaiting checkout: dimmed, with a clear next step */}
                        {isDraft && (
                            <div className="pointer-events-none absolute inset-x-0 bottom-0 overflow-hidden rounded-l-[3px] rounded-r-[6px]">
                                <div className="bg-gradient-to-t from-black/80 via-black/45 to-transparent px-3 pt-7 pb-2.5 text-white">
                                    <p className="flex items-center gap-1.5 font-display text-[11px] leading-tight font-semibold">
                                        <Moon className="h-3 w-3 text-gold" />
                                        <span className="truncate">
                                            {t('gallery.statusDraft')}
                                        </span>
                                    </p>
                                    <p className="mt-0.5 truncate text-[10px] text-white/75">
                                        {t('gallery.finishCheckout')}
                                    </p>
                                </div>
                            </div>
                        )}

                        {/* A draft is still the reader's to shape: edit or
                            discard it right from the shelf (always visible,
                            since drafts have no hover-discoverable actions) */}
                        {isDraft && (
                            <div className="absolute end-2 top-2 z-10 flex flex-col gap-1.5">
                                <Link
                                    href={bookRoutes.edit(book.id)}
                                    onClick={(e) => e.stopPropagation()}
                                    title={t('gallery.editDraft')}
                                    aria-label={t('gallery.editDraft')}
                                    className="hover:glow-gold inline-flex items-center justify-center rounded-full bg-background/80 p-2 text-gold shadow-soft backdrop-blur-sm transition-all duration-300 hover:bg-background focus-visible:ring-2 focus-visible:ring-gold focus-visible:outline-none"
                                >
                                    <Pencil className="h-4 w-4" />
                                </Link>
                                <button
                                    type="button"
                                    onClick={(e) => {
                                        e.preventDefault();
                                        e.stopPropagation();
                                        setConfirmingDelete(true);
                                    }}
                                    title={t('gallery.deleteDraft')}
                                    aria-label={t('gallery.deleteDraft')}
                                    className="inline-flex items-center justify-center rounded-full bg-background/80 p-2 text-rose shadow-soft backdrop-blur-sm transition-all duration-300 hover:bg-background focus-visible:ring-2 focus-visible:ring-rose focus-visible:outline-none"
                                >
                                    <Trash2 className="h-4 w-4" />
                                </button>
                            </div>
                        )}
                    </div>
                )}
            </Link>

            {/* Draft delete confirmation */}
            {isDraft && (
                <AlertDialog
                    open={confirmingDelete}
                    onOpenChange={setConfirmingDelete}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                {t('gallery.deleteDraftTitle')}
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                {t('gallery.deleteDraftBody')}
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={deleting}>
                                {t('library.cancel')}
                            </AlertDialogCancel>
                            <AlertDialogAction
                                disabled={deleting}
                                onClick={(e) => {
                                    e.preventDefault();
                                    router.delete(
                                        bookRoutes.destroy.url(book.id),
                                        {
                                            preserveScroll: true,
                                            onStart: () => setDeleting(true),
                                            onFinish: () => {
                                                setDeleting(false);
                                                setConfirmingDelete(false);
                                            },
                                        },
                                    );
                                }}
                                className="bg-rose text-rose-foreground hover:bg-rose/90"
                            >
                                {deleting ? (
                                    <Loader2 className="h-4 w-4 animate-spin" />
                                ) : (
                                    t('library.confirmDelete')
                                )}
                            </AlertDialogAction>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
            )}

            {/* Plank zone spacer: the shelf runs through here */}
            <div aria-hidden className="h-5" />

            {/* Shelf-edge label */}
            <div className="flex h-16 min-w-0 flex-col gap-0.5 pt-3">
                <div className="flex items-center justify-between gap-2">
                    <p className="truncate font-serif text-base leading-tight font-semibold text-foreground">
                        {book.childName}
                    </p>
                    <StatusBadge status={book.status} />
                </div>
                <p className="truncate text-xs text-muted-foreground">
                    {t('gallery.agesLabel', { range: book.ageRange })}
                </p>
            </div>
        </motion.li>
    );
}

// An open slot at the end of the last shelf: the standing invitation to
// begin the next keepsake.
function AddTaleSlot() {
    const t = useT();

    return (
        <li className="z-10 flex w-36 shrink-0 snap-start flex-col sm:w-40 md:w-44">
            <Link
                href={templateRoutes.index()}
                className="group/slot relative -mb-0.5 block rounded-sm focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <div className="flex aspect-[3/4] w-full items-center justify-center rounded-l-[3px] rounded-r-[6px] border-2 border-dashed border-gold/35 bg-gold/5 transition-colors duration-300 group-hover/slot:border-gold/60 group-hover/slot:bg-gold/10">
                    <span className="flex h-12 w-12 items-center justify-center rounded-full bg-gold/15 text-gold transition-transform duration-300 group-hover/slot:scale-110">
                        <Plus className="h-6 w-6" />
                    </span>
                </div>
            </Link>
            <div aria-hidden className="h-5" />
            <div className="flex h-16 items-start pt-3">
                <p className="truncate font-display text-sm font-semibold text-gold-foreground dark:text-gold">
                    {t('gallery.addAnotherTale')}
                </p>
            </div>
        </li>
    );
}

// One shelf of the bookcase: lamplight pooling from above, books standing
// on a continuous plank, labels along the shelf edge. Narrow screens scroll
// each shelf sideways with snap points.
function Shelf({ books, isLast }: { books: GalleryBook[]; isLast: boolean }) {
    return (
        <li className="relative">
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-10 top-0 h-44 bg-[radial-gradient(60%_100%_at_50%_0%,hsl(var(--gold)/0.10),transparent_70%)]"
            />
            <div className="[scrollbar-width:thin] overflow-x-auto pb-1">
                <div className="relative w-max min-w-full">
                    <motion.ol
                        variants={staggerContainer(0.06)}
                        initial="hidden"
                        animate="show"
                        className="flex snap-x items-end gap-6 px-6 pt-10 sm:px-10"
                    >
                        {books.map((book) => (
                            <ShelfBook key={book.id} book={book} />
                        ))}
                        {isLast && <AddTaleSlot />}
                    </motion.ol>
                    <ShelfPlank />
                </div>
            </div>
        </li>
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
                    className="mb-12 flex flex-wrap items-end justify-between gap-6"
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

                {/* The bookcase: shelves of keepsakes under pooled lamplight */}
                {hasBooks && (
                    <motion.div
                        initial={reduceMotion ? false : { opacity: 0, y: 14 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{
                            duration: 0.55,
                            ease: easeOutSoft,
                            delay: 0.1,
                        }}
                        className="relative overflow-hidden rounded-[2rem] border border-card-border bg-card/40 shadow-lift backdrop-blur-sm"
                    >
                        {/* Case top rail */}
                        <div
                            aria-hidden
                            className="h-2 w-full bg-gradient-to-b from-shelf-edge/80 to-shelf/60"
                        />
                        <ul className="space-y-2 pt-2 pb-6">
                            {chunk(books, SHELF_SIZE).map((row, i, rows) => (
                                <Shelf
                                    key={row[0].id}
                                    books={row}
                                    isLast={i === rows.length - 1}
                                />
                            ))}
                        </ul>
                    </motion.div>
                )}

                {/* Mobile CTA */}
                {hasBooks && (
                    <div className="mt-12 flex justify-center sm:hidden">
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
