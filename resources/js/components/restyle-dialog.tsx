import { router } from '@inertiajs/react';
import { Check, Loader2, Palette } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { slugify, useI18n, useT } from '@/i18n';
import { ART_STYLES } from '@/lib/story-options';
import { restyle } from '@/routes/books';

// Pick a new illustration style for a finished book and re-render every image
// in it (the story is kept, so only the images are generated again). Used from
// the reader and the gallery.
export default function RestyleDialog({
    bookId,
    currentStyle,
    trigger,
}: {
    bookId: number;
    currentStyle: string;
    trigger?: React.ReactNode;
}) {
    const t = useT();
    const { tc } = useI18n();
    const [open, setOpen] = useState(false);
    const [style, setStyle] = useState(currentStyle);
    const [submitting, setSubmitting] = useState(false);

    const submit = () => {
        router.post(
            restyle.url({ id: bookId }),
            { artStyle: style },
            {
                preserveScroll: true,
                onStart: () => setSubmitting(true),
                onFinish: () => setSubmitting(false),
                onSuccess: () => setOpen(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {trigger ?? (
                    <Button
                        size="sm"
                        variant="ghost"
                        className="gap-2 rounded-full text-white/75 hover:bg-white/10 hover:text-white"
                    >
                        <Palette className="h-4 w-4" />
                        <span className="hidden sm:inline">
                            {t('restyle.button')}
                        </span>
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[85dvh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('restyle.title')}</DialogTitle>
                    <DialogDescription>
                        {t('restyle.description')}
                    </DialogDescription>
                </DialogHeader>

                <div
                    role="radiogroup"
                    aria-label={t('restyle.title')}
                    className="grid grid-cols-2 gap-3 sm:grid-cols-3"
                >
                    {ART_STYLES.map((s) => {
                        const selected = style === s;

                        return (
                            <button
                                key={s}
                                type="button"
                                role="radio"
                                aria-checked={selected}
                                onClick={() => setStyle(s)}
                                className={`group relative overflow-hidden rounded-2xl border bg-card text-start transition-all duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none ${
                                    selected
                                        ? 'border-gold shadow-glow'
                                        : 'border-card-border hover:-translate-y-0.5 hover:border-primary/50 hover:shadow-soft'
                                }`}
                            >
                                <span
                                    aria-hidden
                                    className="block aspect-[4/3] w-full overflow-hidden"
                                >
                                    <img
                                        src={`/images/art-styles/${s}.jpg`}
                                        alt=""
                                        loading="lazy"
                                        className="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.04]"
                                        onError={(e) => {
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
                                    {tc(`artStyle.${slugify(s)}`, s)}
                                    {s === currentStyle && (
                                        <span className="ms-1 text-muted-foreground">
                                            {t('restyle.current')}
                                        </span>
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

                <DialogFooter>
                    <Button
                        variant="gold"
                        disabled={submitting}
                        onClick={submit}
                        className="gap-2"
                    >
                        {submitting ? (
                            <Loader2
                                className="h-4 w-4 animate-spin"
                                aria-hidden
                            />
                        ) : (
                            <Palette className="h-4 w-4" aria-hidden />
                        )}
                        {t('restyle.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
