import { Link } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import {
    BookOpen,
    Sparkles,
    Heart,
    Layers,
    ArrowRight,
    Compass,
} from 'lucide-react';
import BookCover from '@/components/cubfable/book-cover';
import Starfield from '@/components/cubfable/starfield';
import { useT, useI18n, slugify } from '@/i18n';
import { fadeUp, staggerContainer, easeOutSoft } from '@/lib/motion';
import books from '@/routes/books';
import type { Template } from '@/types';

// One template presented as a "jewel" storybook: the hardcover BookCover object
// on the left, its enchanted framing (age, lessons, page count) on the right,
// with a clear Personalize CTA into the wizard.
function TemplateCard({ template }: { template: Template }) {
    const t = useT();
    const { tc } = useI18n();

    const title = tc(`tpl.${template.theme}.title`, template.title);
    const description = tc(
        `tpl.${template.theme}.description`,
        template.description,
    );
    const lessons = template.lifeLessons?.slice(0, 2) ?? [];

    return (
        <motion.div variants={fadeUp} className="h-full">
            <Link
                href={books.create(template.id)}
                aria-label={t('templates.personalizeAria', { title })}
                className="group/card block h-full rounded-3xl focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
            >
                <article className="relative flex h-full flex-col overflow-hidden rounded-3xl border border-card-border bg-card shadow-soft transition-all duration-500 ease-out group-hover/card:-translate-y-1.5 group-hover/card:shadow-lift">
                    {/* Cover stage: the storybook object on a soft twilight shelf */}
                    <div className="relative overflow-hidden bg-gradient-to-b from-secondary to-muted px-8 pt-8 pb-2">
                        <div
                            aria-hidden
                            className="pointer-events-none absolute inset-x-6 top-6 h-40 rounded-full bg-primary/15 blur-3xl transition-opacity duration-500 group-hover/card:bg-gold/20"
                        />
                        <BookCover
                            coverImageUrl={template.coverImageUrl}
                            className="relative z-10 mx-auto w-40 max-w-[45%] drop-shadow-xl"
                        />
                        {/* Page-count ribbon */}
                        <div className="absolute end-4 top-4 z-20 inline-flex items-center gap-1.5 rounded-full bg-background/85 px-3 py-1 font-display text-xs font-semibold text-foreground shadow-soft ring-1 ring-card-border backdrop-blur-sm">
                            <Layers
                                className="h-3.5 w-3.5 text-gold"
                                aria-hidden
                            />
                            {t('templates.pageCount', {
                                count: template.pageCount,
                            })}
                        </div>
                    </div>

                    {/* Story details */}
                    <div className="flex flex-1 flex-col p-6">
                        <div className="mb-3 flex flex-wrap items-center gap-2">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 font-display text-xs font-semibold text-primary">
                                <Sparkles className="h-3 w-3" aria-hidden />
                                {t('templates.ageRange', {
                                    min: template.ageMin,
                                    max: template.ageMax,
                                })}
                            </span>
                        </div>

                        <h3 className="mb-2 line-clamp-2 font-serif text-2xl leading-tight font-bold text-foreground">
                            {title}
                        </h3>

                        <p className="mb-5 line-clamp-2 flex-1 text-sm leading-relaxed text-muted-foreground">
                            {description}
                        </p>

                        {lessons.length > 0 && (
                            <div className="mb-6">
                                <p className="mb-2 font-display text-[0.68rem] font-semibold tracking-[0.16em] text-muted-foreground uppercase">
                                    {t('templates.lessonsLabel')}
                                </p>
                                <div className="flex flex-wrap gap-2">
                                    {lessons.map((lesson) => (
                                        <span
                                            key={lesson}
                                            className="inline-flex items-center gap-1.5 rounded-full bg-rose/10 px-2.5 py-1 text-xs font-medium text-rose"
                                        >
                                            <Heart
                                                className="h-3 w-3"
                                                aria-hidden
                                            />
                                            {tc(
                                                `lesson.${slugify(lesson)}`,
                                                lesson,
                                            )}
                                        </span>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="mt-auto flex w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 font-semibold text-primary-foreground shadow-soft transition-all duration-300 group-hover/card:bg-gold group-hover/card:text-gold-foreground group-hover/card:shadow-glow">
                            <span>{t('templates.personalizeButton')}</span>
                            <ArrowRight
                                className="h-4 w-4 transition-transform duration-300 group-hover/card:translate-x-0.5 rtl:group-hover/card:-translate-x-0.5"
                                aria-hidden
                            />
                        </div>
                    </div>
                </article>
            </Link>
        </motion.div>
    );
}

export default function Templates({ templates }: { templates: Template[] }) {
    const t = useT();
    const reduce = useReducedMotion();

    const isEmpty = templates.length === 0;

    return (
        <div className="relative min-h-[100dvh] bg-background">
            {/* Enchanted header */}
            <section className="relative overflow-hidden">
                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-primary/12 via-background to-background"
                />
                <Starfield
                    count={30}
                    aurora
                    className="opacity-70 dark:opacity-100"
                />

                <div className="relative z-10 container mx-auto px-4 pt-20 pb-10 md:pt-24">
                    <motion.div
                        initial={reduce ? false : { opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.6, ease: easeOutSoft }}
                        className="max-w-3xl"
                    >
                        <div className="mb-5 inline-flex items-center gap-2 rounded-full border border-gold/30 bg-gold/10 px-3.5 py-1.5 font-display text-sm font-semibold text-gold">
                            <Compass className="h-4 w-4" aria-hidden />
                            <span>{t('templates.eyebrow')}</span>
                        </div>

                        <h1 className="font-serif text-4xl leading-[1.08] font-bold text-foreground md:text-6xl">
                            {t('templates.heading')}{' '}
                            <span className="text-lamplight italic">
                                {t('templates.headingAccent')}
                            </span>
                        </h1>

                        <p className="mt-5 max-w-2xl text-lg leading-relaxed text-muted-foreground">
                            {t('templates.subheading')}
                        </p>

                        {templates.length > 0 && (
                            <p className="mt-6 inline-flex items-center gap-2 font-display text-sm font-medium text-muted-foreground">
                                <BookOpen
                                    className="h-4 w-4 text-primary"
                                    aria-hidden
                                />
                                {t('templates.count', {
                                    count: templates.length,
                                })}
                            </p>
                        )}
                    </motion.div>
                </div>
            </section>

            {/* Catalog */}
            <section className="container mx-auto px-4 pb-24">
                {isEmpty ? (
                    <div className="mx-auto max-w-lg rounded-3xl border border-card-border bg-card px-8 py-16 text-center shadow-soft">
                        <div className="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-secondary text-primary">
                            <BookOpen className="h-7 w-7" aria-hidden />
                        </div>
                        <h2 className="font-serif text-2xl font-bold text-foreground">
                            {t('templates.emptyTitle')}
                        </h2>
                        <p className="mx-auto mt-2 max-w-sm text-muted-foreground">
                            {t('templates.emptyDescription')}
                        </p>
                    </div>
                ) : (
                    <motion.div
                        variants={staggerContainer(0.08)}
                        initial={reduce ? false : 'hidden'}
                        animate="show"
                        className="grid grid-cols-1 gap-8 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        {templates.map((template) => (
                            <TemplateCard
                                key={template.id}
                                template={template}
                            />
                        ))}
                    </motion.div>
                )}
            </section>
        </div>
    );
}
