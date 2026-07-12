import { Link, router, useForm, useHttp, usePoll } from '@inertiajs/react';
import { AnimatePresence, motion, useReducedMotion } from 'framer-motion';
import {
    ArrowLeft,
    Check,
    ChevronLeft,
    ChevronRight,
    Download,
    Edit3,
    Loader2,
    Moon,
    RefreshCw,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import Starfield from '@/components/cubfable/starfield';
import RestyleDialog from '@/components/restyle-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Textarea } from '@/components/ui/textarea';
import { useT } from '@/i18n';
import { download, index as booksIndex } from '@/routes/books';
import { reconcile, show as checkoutShow } from '@/routes/checkout';
import { regenerate, update } from '@/routes/pages';
import type { BookWithPages } from '@/types';

const NIGHT_BG =
    'radial-gradient(130% 90% at 50% -10%, #241d5a 0%, #14112f 55%, #0c0a22 100%)';

function StatusBadge({ status }: { status: string }) {
    const t = useT();
    const styles: Record<string, string> = {
        pending: 'bg-gold/15 text-gold-foreground ring-gold/30',
        generating: 'bg-primary/15 text-primary ring-primary/30',
        complete:
            'bg-emerald-500/15 text-emerald-600 ring-emerald-500/30 dark:text-emerald-300',
        failed: 'bg-destructive/15 text-destructive ring-destructive/30',
    };
    const label =
        status === 'generating'
            ? t('reader.statusGenerating')
            : status === 'complete'
              ? t('reader.statusComplete')
              : status === 'failed'
                ? t('reader.statusFailed')
                : t('reader.statusPending');

    return (
        <span
            className={`rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ${styles[status] ?? styles.pending}`}
        >
            {label}
        </span>
    );
}

type ReaderProps = {
    book: BookWithPages;
};

export default function Reader({ book }: ReaderProps) {
    const t = useT();
    const reduce = useReducedMotion();

    const [currentPage, setCurrentPage] = useState(0);
    const [direction, setDirection] = useState<1 | -1>(1);
    const [editingPageId, setEditingPageId] = useState<number | null>(null);
    const [regeneratingPageId, setRegeneratingPageId] = useState<number | null>(
        null,
    );

    const editForm = useForm({ text: '' });

    // Refresh the `book` prop every 3s while generation is in flight. The
    // partial reload keeps this component mounted, so the current spread, flip
    // direction and any open edit survive each poll.
    const inProgress =
        book.status === 'pending' ||
        book.status === 'generating' ||
        book.pages.some(
            (p) => p.status === 'pending' || p.status === 'generating',
        );
    const { start, stop } = usePoll(
        3000,
        { only: ['book'] },
        { autoStart: false },
    );

    // Toggle only on transitions: Poll.start() resets the interval timer and
    // `start`/`stop` get new identities each render, so calling start() on
    // every render would keep pushing the next refresh away.
    const pollingRef = useRef(false);
    useEffect(() => {
        if (inProgress === pollingRef.current) {
            return;
        }

        pollingRef.current = inProgress;

        if (inProgress) {
            start();
        } else {
            stop();
        }
    }, [inProgress, start, stop]);

    // Reconcile payment on load: for a draft book whose payment already succeeded
    // (webhook delayed or not wired), the server re-checks with Stripe and unlocks
    // it. Runs once per book; a "finalizing" state is shown meanwhile so the user is
    // not shown (and bounced back through) the checkout CTA after they have paid.
    const [finalizingPayment, setFinalizingPayment] = useState(
        book.status === 'draft',
    );
    const reconciledRef = useRef(false);
    const { post: postReconcile } = useHttp<
        Record<string, never>,
        { status?: string }
    >({});

    useEffect(() => {
        if (book.status !== 'draft' || reconciledRef.current) {
            return;
        }

        reconciledRef.current = true;
        postReconcile(reconcile.url(book.id), {
            onSuccess: (data) => {
                if (data && String(data.status) !== 'draft') {
                    router.reload({ only: ['book'] });
                }
            },
            onFinish: () => setFinalizingPayment(false),
        });
    }, [book.status, book.id, postReconcile]);

    const pages = book.pages;
    const page = pages[currentPage];

    const goTo = (idx: number) => {
        if (idx < 0 || idx >= pages.length) {
            return;
        }

        setDirection(idx > currentPage ? 1 : -1);
        setCurrentPage(idx);
        setEditingPageId(null);
    };

    const handleEdit = (pageId: number, text: string) => {
        setEditingPageId(pageId);
        editForm.setData('text', text);
        editForm.clearErrors();
    };

    const handleSave = () => {
        if (editingPageId === null) {
            return;
        }

        editForm.patch(update.url({ id: book.id, pageId: editingPageId }), {
            preserveScroll: true,
            onSuccess: () => setEditingPageId(null),
        });
    };

    const handleRegenerate = (pageId: number) => {
        router.post(
            regenerate.url({ id: book.id, pageId }),
            {},
            {
                preserveScroll: true,
                onStart: () => setRegeneratingPageId(pageId),
                onFinish: () => setRegeneratingPageId(null),
            },
        );
    };

    // ---------- Awaiting payment (draft) ----------
    if (book.status === 'draft') {
        return (
            <div
                className="relative flex min-h-[100dvh] flex-col items-center justify-center gap-6 overflow-hidden px-4"
                style={{ background: NIGHT_BG }}
            >
                <Starfield count={40} aurora />
                {finalizingPayment ? (
                    <div className="relative z-10 flex flex-col items-center gap-4">
                        <Loader2 className="h-11 w-11 animate-spin text-gold" />
                        <p className="font-serif text-xl text-white/90">
                            {t('reader.openingStorybook')}
                        </p>
                    </div>
                ) : (
                    <div className="relative z-10 flex max-w-md flex-col items-center gap-6 text-center">
                        <div className="flex h-20 w-20 items-center justify-center rounded-full bg-gold/15 ring-1 ring-gold/30">
                            <Moon className="h-9 w-9 text-gold" />
                        </div>
                        <div>
                            <h2 className="font-serif text-3xl font-semibold text-white">
                                {t('checkout.heading')}
                            </h2>
                            <p className="mx-auto mt-3 max-w-sm text-white/70">
                                {t('checkout.subheading')}
                            </p>
                        </div>
                        <div className="flex flex-wrap items-center justify-center gap-3">
                            <Link href={checkoutShow(book.id)}>
                                <Button
                                    variant="gold"
                                    size="lg"
                                    className="rounded-full"
                                >
                                    {t('wizard.continueToCheckout')}
                                </Button>
                            </Link>
                            <Link href={booksIndex()}>
                                <Button
                                    variant="outline"
                                    size="lg"
                                    className="rounded-full"
                                >
                                    {t('reader.backToMyBooks')}
                                </Button>
                            </Link>
                        </div>
                    </div>
                )}
            </div>
        );
    }

    // ---------- Still being created ----------
    if (book.status === 'pending' || book.status === 'generating') {
        const done = pages.filter((p) => p.status === 'complete').length;

        return (
            <div
                className="relative flex min-h-[100dvh] flex-col items-center justify-center gap-8 overflow-hidden px-4"
                style={{ background: NIGHT_BG }}
            >
                <Starfield count={60} aurora />
                <div className="relative z-10 flex flex-col items-center gap-8">
                    <motion.div
                        animate={reduce ? undefined : { scale: [1, 1.06, 1] }}
                        transition={{ duration: 2.4, repeat: Infinity }}
                        className="flex h-24 w-24 items-center justify-center rounded-full bg-gold/15 ring-1 ring-gold/30"
                    >
                        <Loader2 className="h-11 w-11 animate-spin text-gold" />
                    </motion.div>
                    <div className="text-center">
                        <h2 className="font-serif text-3xl font-semibold text-white">
                            {t('reader.creatingStorybook', {
                                name: book.childName,
                            })}
                        </h2>
                        <p className="mx-auto mt-3 max-w-md text-white/70">
                            {t('reader.creatingDescription')}
                        </p>
                    </div>
                    {pages.length > 0 && (
                        <div className="flex max-w-sm flex-wrap justify-center gap-2">
                            {pages.map((p, i) => (
                                <div
                                    key={p.id}
                                    className={`flex h-10 w-10 items-center justify-center rounded-xl text-sm font-semibold transition-all ${
                                        p.status === 'complete'
                                            ? 'bg-gold text-gold-foreground'
                                            : p.status === 'generating'
                                              ? 'animate-pulse bg-primary/40 text-white'
                                              : 'bg-white/10 text-white/50'
                                    }`}
                                >
                                    {i + 1}
                                </div>
                            ))}
                        </div>
                    )}
                    {pages.length > 0 && (
                        <p className="text-sm text-white/50">
                            {t('reader.pagesReady', {
                                done,
                                total: pages.length,
                            })}
                        </p>
                    )}
                    <Link href={booksIndex()}>
                        <Button
                            variant="outline"
                            className="rounded-full border-white/25 bg-white/5 text-white hover:bg-white/10"
                        >
                            {t('reader.backToMyBooks')}
                        </Button>
                    </Link>
                </div>
            </div>
        );
    }

    const variants = {
        enter: (dir: number) => ({
            x: dir > 0 ? 90 : -90,
            opacity: 0,
            rotateY: dir > 0 ? 6 : -6,
        }),
        center: { x: 0, opacity: 1, rotateY: 0 },
        exit: (dir: number) => ({
            x: dir > 0 ? -90 : 90,
            opacity: 0,
            rotateY: dir > 0 ? -6 : 6,
        }),
    };

    return (
        <div
            className="relative flex min-h-[100dvh] flex-col overflow-hidden"
            style={{ background: NIGHT_BG }}
        >
            <Starfield count={46} aurora />

            {/* Lamplight glow above the book */}
            <div
                aria-hidden
                className="pointer-events-none absolute top-0 left-1/2 h-64 w-[42rem] max-w-full -translate-x-1/2 blur-3xl"
                style={{
                    background:
                        'radial-gradient(closest-side, hsl(40 90% 60% / 0.28), transparent)',
                }}
            />

            {/* Top bar */}
            <div className="relative z-10 flex items-center justify-between gap-3 border-b border-white/10 px-4 py-4 sm:px-6">
                <Link href={booksIndex()}>
                    <Button
                        variant="ghost"
                        size="sm"
                        className="gap-2 rounded-full text-white/75 hover:bg-white/10 hover:text-white"
                    >
                        <ArrowLeft className="h-4 w-4 rtl:rotate-180" />
                        <span className="hidden sm:inline">
                            {t('reader.myBooks')}
                        </span>
                    </Button>
                </Link>
                <h1 className="truncate px-2 text-center font-serif text-lg font-medium text-white">
                    {t('reader.storybookTitle', { name: book.childName })}
                </h1>
                <div className="flex items-center gap-1.5">
                    {book.status === 'complete' && (
                        <RestyleDialog
                            bookId={book.id}
                            currentStyle={book.artStyle}
                        />
                    )}
                    {/* The server composes the PDF; plain same-origin anchors
                        (never Inertia visits) send the session cookie and let
                        the browser save the file. Two variants: print-ready
                        (bleed + crop marks) and home (trim only). */}
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                size="sm"
                                variant="gold"
                                className="gap-2 rounded-full"
                            >
                                <Download className="h-4 w-4" />
                                <span className="hidden sm:inline">
                                    {t('reader.downloadPdf')}
                                </span>
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuItem asChild>
                                <a
                                    href={download.url(book.id, {
                                        query: { variant: 'print' },
                                    })}
                                    rel="noopener"
                                    className="cursor-pointer"
                                >
                                    {t('reader.downloadPrint')}
                                </a>
                            </DropdownMenuItem>
                            <DropdownMenuItem asChild>
                                <a
                                    href={download.url(book.id, {
                                        query: { variant: 'home' },
                                    })}
                                    rel="noopener"
                                    className="cursor-pointer"
                                >
                                    {t('reader.downloadHome')}
                                </a>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            {/* Book spread */}
            <div className="relative z-10 flex flex-1 items-center justify-center px-3 py-8 [perspective:2000px] sm:py-10">
                <div className="w-full max-w-5xl">
                    <div
                        className="rounded-[16px] p-2.5 sm:p-3"
                        style={{
                            background:
                                'linear-gradient(135deg,#2a2170,#171338)',
                            boxShadow:
                                '0 50px 90px -30px rgba(0,0,0,0.85), inset 0 0 0 1px rgba(242,178,62,0.12)',
                        }}
                    >
                        <AnimatePresence mode="wait" custom={direction}>
                            {page && (
                                <motion.div
                                    key={page.id}
                                    custom={direction}
                                    variants={variants}
                                    initial="enter"
                                    animate="center"
                                    exit="exit"
                                    transition={{
                                        duration: reduce ? 0 : 0.4,
                                        ease: 'easeInOut',
                                    }}
                                    className="relative grid overflow-hidden rounded-[9px] lg:grid-cols-2"
                                    style={{
                                        background: '#FBF3E3',
                                        minHeight: 520,
                                    }}
                                >
                                    {/* Illustration page */}
                                    <div className="relative flex items-center justify-center p-5 sm:p-6">
                                        {/* 3:4 matches the generated art exactly; older books
                                            (landscape or 9:16 eras) letterbox on the dark mat
                                            instead of being cropped. */}
                                        <div className="relative aspect-[3/4] w-full overflow-hidden rounded-[6px] bg-[#1a1440] shadow-[inset_0_2px_8px_rgba(0,0,0,0.3)] ring-1 ring-black/10">
                                            {page.status === 'generating' ||
                                            page.status === 'pending' ? (
                                                <div className="absolute inset-0 flex flex-col items-center justify-center gap-4">
                                                    <Loader2 className="h-10 w-10 animate-spin text-gold" />
                                                    <p className="text-sm text-white/60">
                                                        {t(
                                                            'reader.drawingIllustration',
                                                        )}
                                                    </p>
                                                </div>
                                            ) : page.imageUrl ? (
                                                <img
                                                    src={page.imageUrl}
                                                    alt={t('reader.pageAlt', {
                                                        number: page.pageNumber,
                                                    })}
                                                    className="h-full w-full object-contain"
                                                />
                                            ) : (
                                                <div className="absolute inset-0 flex items-center justify-center">
                                                    <p className="text-sm text-white/40">
                                                        {t(
                                                            'reader.noIllustration',
                                                        )}
                                                    </p>
                                                </div>
                                            )}
                                        </div>
                                        <span className="absolute start-6 bottom-3 font-serif text-xs text-[#a08a63]">
                                            {page.pageNumber}
                                        </span>
                                    </div>

                                    {/* Binding crease */}
                                    <div
                                        className="pointer-events-none absolute inset-y-0 left-1/2 hidden w-12 -translate-x-1/2 lg:block"
                                        style={{
                                            background:
                                                'linear-gradient(90deg, transparent, rgba(74,63,160,0.12) 42%, rgba(74,63,160,0.24) 50%, rgba(74,63,160,0.12) 58%, transparent)',
                                        }}
                                    />

                                    {/* Text page */}
                                    <div className="relative flex flex-col justify-between p-8 lg:p-12">
                                        <div className="flex flex-1 flex-col justify-center">
                                            {editingPageId === page.id ? (
                                                <div className="flex flex-col gap-4">
                                                    <Textarea
                                                        value={
                                                            editForm.data.text
                                                        }
                                                        onChange={(e) =>
                                                            editForm.setData(
                                                                'text',
                                                                e.target.value,
                                                            )
                                                        }
                                                        dir="auto"
                                                        className="min-h-[160px] resize-none border-primary/30 bg-white font-serif text-lg leading-relaxed text-[#3a2a1a] focus-visible:border-primary"
                                                        autoFocus
                                                    />
                                                    {editForm.errors.text && (
                                                        <p className="text-sm font-medium text-destructive">
                                                            {
                                                                editForm.errors
                                                                    .text
                                                            }
                                                        </p>
                                                    )}
                                                    <div className="flex gap-2">
                                                        <Button
                                                            size="sm"
                                                            onClick={handleSave}
                                                            className="gap-1 rounded-full"
                                                            disabled={
                                                                editForm.processing
                                                            }
                                                        >
                                                            {editForm.processing ? (
                                                                <Loader2 className="h-3 w-3 animate-spin" />
                                                            ) : (
                                                                <Check className="h-3 w-3" />
                                                            )}
                                                            {t('reader.save')}
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setEditingPageId(
                                                                    null,
                                                                )
                                                            }
                                                            className="gap-1 rounded-full"
                                                        >
                                                            <X className="h-3 w-3" />
                                                            {t('reader.cancel')}
                                                        </Button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <p
                                                    dir="auto"
                                                    className="font-serif text-xl leading-relaxed text-[#3a2a1a] lg:text-2xl"
                                                >
                                                    {page.text}
                                                </p>
                                            )}
                                        </div>

                                        {editingPageId !== page.id && (
                                            <div className="mt-8 flex items-center gap-3 border-t border-[#e0cfa8] pt-6">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="gap-2 rounded-full text-xs"
                                                    onClick={() =>
                                                        handleEdit(
                                                            page.id,
                                                            page.text,
                                                        )
                                                    }
                                                >
                                                    <Edit3 className="h-3 w-3" />
                                                    {t('reader.editText')}
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="gap-2 rounded-full text-xs"
                                                    onClick={() =>
                                                        handleRegenerate(
                                                            page.id,
                                                        )
                                                    }
                                                    disabled={
                                                        regeneratingPageId !==
                                                            null ||
                                                        page.status ===
                                                            'generating'
                                                    }
                                                >
                                                    {regeneratingPageId !==
                                                        null ||
                                                    page.status ===
                                                        'generating' ? (
                                                        <Loader2 className="h-3 w-3 animate-spin" />
                                                    ) : (
                                                        <RefreshCw className="h-3 w-3" />
                                                    )}
                                                    {t('reader.regenerateArt')}
                                                </Button>
                                                <div className="ms-auto">
                                                    <StatusBadge
                                                        status={page.status}
                                                    />
                                                </div>
                                            </div>
                                        )}

                                        <span className="absolute end-6 bottom-3 font-serif text-xs text-[#a08a63]">
                                            {t('reader.pageOf', {
                                                number: page.pageNumber,
                                                total: pages.length,
                                            })}
                                        </span>
                                    </div>
                                </motion.div>
                            )}
                        </AnimatePresence>
                    </div>
                </div>
            </div>

            {/* Page navigator: arrows + thumbnail strip */}
            <div className="relative z-10 flex items-center justify-center gap-3 px-4 pb-8 sm:gap-5">
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-11 w-11 shrink-0 rounded-full bg-white/10 text-white hover:bg-white/20 disabled:opacity-30"
                    onClick={() => goTo(currentPage - 1)}
                    disabled={currentPage === 0}
                    aria-label={t('reader.previousPage')}
                >
                    <ChevronLeft className="h-6 w-6 rtl:rotate-180" />
                </Button>

                <div className="flex max-w-full gap-2 overflow-x-auto rounded-2xl bg-black/20 p-2 ring-1 ring-white/10">
                    {pages.map((p, i) => (
                        <button
                            key={p.id}
                            onClick={() => goTo(i)}
                            aria-label={t('reader.goToPage', {
                                number: p.pageNumber,
                            })}
                            aria-current={i === currentPage}
                            className={`relative h-12 w-12 shrink-0 overflow-hidden rounded-lg ring-2 transition-all ${
                                i === currentPage
                                    ? 'ring-gold'
                                    : 'opacity-60 ring-transparent hover:opacity-100'
                            }`}
                        >
                            {p.imageUrl ? (
                                <img
                                    src={p.imageUrl}
                                    alt=""
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                <span className="flex h-full w-full items-center justify-center bg-white/10 font-display text-xs text-white/70">
                                    {p.pageNumber}
                                </span>
                            )}
                        </button>
                    ))}
                </div>

                <Button
                    variant="ghost"
                    size="icon"
                    className="h-11 w-11 shrink-0 rounded-full bg-white/10 text-white hover:bg-white/20 disabled:opacity-30"
                    onClick={() => goTo(currentPage + 1)}
                    disabled={currentPage >= pages.length - 1}
                    aria-label={t('reader.nextPage')}
                >
                    <ChevronRight className="h-6 w-6 rtl:rotate-180" />
                </Button>
            </div>
        </div>
    );
}
