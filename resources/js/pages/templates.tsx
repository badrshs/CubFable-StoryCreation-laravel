import { Link } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import { BookOpen, Compass, MoonStar, Search, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import Starfield from '@/components/cubfable/starfield';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { slugify, useI18n, useT } from '@/i18n';
import { easeOutSoft, fadeUp, staggerContainer } from '@/lib/motion';
import books from '@/routes/books';
import type { Template } from '@/types';

const AGE_BANDS = ['2-4', '4-6', '6-8', '8-10'] as const;

// One template as a physical book standing on the shelf: a page block behind
// the cover, a spine shadow on the binding edge, and a wooden plank segment
// beneath. Equal card heights keep every plank in a row meeting its neighbors,
// so each grid row reads as one continuous shelf.
function ShelfBook({ template }: { template: Template }) {
    const t = useT();
    const { tc } = useI18n();

    const title = tc(`tpl.${template.theme}.title`, template.title);

    return (
        <motion.div variants={fadeUp}>
            <Link
                href={books.create(template.id)}
                aria-label={t('templates.personalizeAria', { title })}
                className="group block rounded-xl focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <div className="relative px-3 pt-3">
                    {/* The book: lifts off the shelf on hover. */}
                    <div className="relative mx-auto w-full max-w-44 transition-transform duration-300 ease-out motion-safe:group-hover:-translate-y-2 motion-safe:group-hover:rotate-[-1.5deg] rtl:motion-safe:group-hover:rotate-[1.5deg]">
                        {/* Page block peeking past the fore-edge */}
                        <div
                            aria-hidden
                            className="absolute inset-y-[1.5%] -end-1 w-2 rounded-e-sm bg-[repeating-linear-gradient(to_bottom,#f3ead8_0px,#f3ead8_2px,#ddd2b8_3px)] shadow-sm"
                        />
                        <div className="relative aspect-[2/3] overflow-hidden rounded-s-[4px] rounded-e-md shadow-[0_10px_22px_-10px_rgba(20,16,50,0.55)] ring-1 ring-black/15 transition-shadow duration-300 group-hover:shadow-[0_20px_34px_-12px_rgba(20,16,50,0.65)] dark:ring-white/10">
                            {template.coverImageUrl &&
                            !template.coverImageUrl.startsWith('data:') ? (
                                <img
                                    src={template.coverImageUrl}
                                    alt=""
                                    loading="lazy"
                                    className="h-full w-full object-cover"
                                />
                            ) : (
                                <div className="flex h-full w-full items-center justify-center bg-gradient-to-b from-primary/70 to-primary p-3 text-center font-serif text-sm leading-snug font-bold text-primary-foreground">
                                    {title}
                                </div>
                            )}
                            {/* Binding: hinge shadow + spine highlight */}
                            <div
                                aria-hidden
                                className="absolute inset-y-0 start-0 w-[9%] bg-gradient-to-r from-black/35 via-black/10 to-transparent rtl:bg-gradient-to-l"
                            />
                            <div
                                aria-hidden
                                className="absolute inset-y-0 start-[9%] w-px bg-white/25"
                            />
                            {/* Soft top sheen, like lamplight on a glossy cover */}
                            <div
                                aria-hidden
                                className="absolute inset-x-0 top-0 h-1/4 bg-gradient-to-b from-white/12 to-transparent"
                            />
                        </div>
                        {/* Contact shadow where the book meets the plank */}
                        <div
                            aria-hidden
                            className="absolute -bottom-1.5 left-1/2 h-2.5 w-4/5 -translate-x-1/2 rounded-[50%] bg-black/35 blur-[5px] transition-opacity duration-300 group-hover:opacity-60 dark:bg-black/60"
                        />
                    </div>

                    {/* The shelf plank segment, extended past the card so the
                        planks in a row meet across the grid gap and read as
                        one continuous shelf. */}
                    <div
                        aria-hidden
                        className="relative -mx-5 mt-1 h-2.5 rounded-[2px] bg-gradient-to-b from-[#8a5a33] via-[#6f4526] to-[#53331c] shadow-[0_6px_10px_-4px_rgba(30,18,8,0.5)] dark:from-[#5d3c22] dark:via-[#4a2f1a] dark:to-[#331f10]"
                    >
                        <div className="absolute inset-x-0 top-0 h-px bg-[#c89a6b]/70 dark:bg-[#8a6642]/70" />
                    </div>
                </div>

                {/* Hand-written shop label under the shelf */}
                <div className="px-3 pt-3 pb-1 text-center">
                    <h3 className="line-clamp-2 min-h-[2.6rem] font-serif text-[0.95rem] leading-snug font-bold text-foreground transition-colors group-hover:text-gold-foreground dark:group-hover:text-gold">
                        {title}
                    </h3>
                    <p className="mt-1 font-display text-[0.68rem] font-semibold tracking-wide text-muted-foreground">
                        {t('templates.ageRange', {
                            min: template.ageMin,
                            max: template.ageMax,
                        })}
                        {' - '}
                        {t('templates.pageCount', { count: template.pageCount })}
                    </p>
                </div>
            </Link>
        </motion.div>
    );
}

export default function Templates({
    templates,
    themes,
}: {
    templates: Template[];
    themes: string[];
}) {
    const t = useT();
    const reduce = useReducedMotion();

    const [search, setSearch] = useState('');
    const [ageBand, setAgeBand] = useState<string>('all');
    const [theme, setTheme] = useState<string>('all');

    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();
        const [bandMin, bandMax] =
            ageBand === 'all'
                ? [0, 99]
                : (ageBand.split('-').map(Number) as [number, number]);

        return templates.filter((template) => {
            if (theme !== 'all' && template.theme !== theme) {
                return false;
            }

            if (template.ageMin > bandMax || template.ageMax < bandMin) {
                return false;
            }

            if (needle === '') {
                return true;
            }

            const haystack = [
                template.title,
                template.theme,
                ...(template.subjects ?? []),
            ]
                .join(' ')
                .toLowerCase();

            return haystack.includes(needle);
        });
    }, [templates, search, ageBand, theme]);

    return (
        <div className="relative min-h-[100dvh] bg-background">
            {/* Enchanted header, tightened: the wall below is the hero. */}
            <section className="relative overflow-hidden">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-primary/12 via-background to-background"
                />
                <Starfield
                    count={26}
                    aurora
                    className="opacity-70 dark:opacity-100"
                />

                <div className="relative z-10 container mx-auto px-4 pt-16 pb-6 md:pt-20">
                    <motion.div
                        initial={reduce ? false : { opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.6, ease: easeOutSoft }}
                        className="max-w-3xl"
                    >
                        <div className="mb-4 inline-flex items-center gap-2 rounded-full border border-gold/30 bg-gold/10 px-3.5 py-1.5 font-display text-sm font-semibold text-gold">
                            <Compass className="h-4 w-4" aria-hidden />
                            <span>{t('templates.eyebrow')}</span>
                        </div>

                        <h1 className="font-serif text-4xl leading-[1.08] font-bold text-foreground md:text-5xl">
                            {t('templates.heading')}{' '}
                            <span className="text-lamplight italic">
                                {t('templates.headingAccent')}
                            </span>
                        </h1>

                        <p className="mt-4 max-w-2xl leading-relaxed text-muted-foreground">
                            {t('templates.subheading')}
                        </p>
                    </motion.div>
                </div>
            </section>

            {/* Shop toolbar: search + age + theme, stays with you down the wall */}
            <div className="sticky top-16 z-40 border-y border-border/60 bg-background/85 backdrop-blur-lg">
                <div className="container mx-auto flex flex-wrap items-center gap-2.5 px-4 py-3">
                    <div className="relative min-w-52 flex-1 sm:max-w-xs">
                        <Search
                            className="absolute start-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden
                        />
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder={t('templates.searchPlaceholder')}
                            className="rounded-full ps-9"
                        />
                    </div>

                    <div
                        role="radiogroup"
                        aria-label={t('templates.ageFilterLabel')}
                        className="flex items-center gap-1"
                    >
                        {['all', ...AGE_BANDS].map((band) => (
                            <button
                                key={band}
                                type="button"
                                role="radio"
                                aria-checked={ageBand === band}
                                onClick={() => setAgeBand(band)}
                                className={`rounded-full px-3 py-1.5 font-display text-xs font-semibold transition-colors ${
                                    ageBand === band
                                        ? 'bg-gold text-gold-foreground shadow-soft'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                                }`}
                            >
                                {band === 'all'
                                    ? t('templates.ageAll')
                                    : band}
                            </button>
                        ))}
                    </div>

                    <Select value={theme} onValueChange={setTheme}>
                        <SelectTrigger className="w-44 rounded-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">
                                {t('templates.themeAll')}
                            </SelectItem>
                            {themes.map((option) => (
                                <SelectItem key={option} value={option}>
                                    {option.replace(/-/g, ' ')}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>

                    <p className="ms-auto inline-flex items-center gap-1.5 font-display text-xs font-medium text-muted-foreground">
                        <BookOpen className="h-3.5 w-3.5 text-primary" aria-hidden />
                        {t('templates.count', { count: visible.length })}
                    </p>
                </div>
            </div>

            {/* The library wall */}
            <section className="container mx-auto px-4 pt-10 pb-24">
                {visible.length === 0 ? (
                    <div className="mx-auto max-w-lg rounded-3xl border border-card-border bg-card px-8 py-16 text-center shadow-soft">
                        <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-secondary text-primary">
                            <MoonStar className="h-7 w-7" aria-hidden />
                        </div>
                        <h2 className="font-serif text-2xl font-bold text-foreground">
                            {t('templates.noMatchesTitle')}
                        </h2>
                        <p className="mx-auto mt-2 max-w-sm text-muted-foreground">
                            {t('templates.noMatchesDescription')}
                        </p>
                        <button
                            type="button"
                            onClick={() => {
                                setSearch('');
                                setAgeBand('all');
                                setTheme('all');
                            }}
                            className="mt-6 inline-flex items-center gap-2 rounded-full bg-primary px-5 py-2 font-semibold text-primary-foreground shadow-soft transition-colors hover:bg-gold hover:text-gold-foreground"
                        >
                            <Sparkles className="h-4 w-4" aria-hidden />
                            {t('templates.clearFilters')}
                        </button>
                    </div>
                ) : (
                    <motion.div
                        key={`${search}|${ageBand}|${theme}`}
                        variants={staggerContainer(0.04)}
                        initial={reduce ? false : 'hidden'}
                        animate="show"
                        className="grid grid-cols-2 gap-x-4 gap-y-10 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5"
                    >
                        {visible.map((template) => (
                            <ShelfBook key={template.id} template={template} />
                        ))}
                    </motion.div>
                )}
            </section>
        </div>
    );
}
