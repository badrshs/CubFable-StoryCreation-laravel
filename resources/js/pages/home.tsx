import { Link } from '@inertiajs/react';
import { motion } from 'framer-motion';
import {
    ArrowRight,
    Camera,
    Wand2,
    BookOpen,
    Sparkles,
    Star,
    Heart,
    Globe,
    Download,
    Palette,
    Moon,
} from 'lucide-react';
import OpeningBook from '@/components/cubfable/opening-book';
import Starfield from '@/components/cubfable/starfield';
import { Button } from '@/components/ui/button';
import { useT } from '@/i18n';
import { fadeUp, staggerContainer, revealOnView } from '@/lib/motion';
import { ART_STYLES } from '@/lib/story-options';
import books from '@/routes/books';
import templates from '@/routes/templates';

type HomeProps = {
    stats: {
        totalBooks: number;
        completedBooks: number;
    };
};

function prettyStyle(slug: string): string {
    return slug
        .split('-')
        .map((w) =>
            w === '3d' ? '3D' : w.charAt(0).toUpperCase() + w.slice(1),
        )
        .join(' ');
}

export default function Home({ stats }: HomeProps) {
    const t = useT();

    const steps = [
        { icon: Camera, key: 'step1' },
        { icon: Wand2, key: 'step2' },
        { icon: BookOpen, key: 'step3' },
    ];

    const features = [
        { icon: Heart, key: 'feat1' },
        { icon: Palette, key: 'feat2' },
        { icon: Globe, key: 'feat3' },
        { icon: Download, key: 'feat4' },
    ];

    return (
        <div className="flex w-full flex-col overflow-x-clip">
            {/* ============================ HERO ============================ */}
            <section className="relative overflow-hidden">
                <div className="pointer-events-none absolute inset-0 -z-10">
                    <div className="absolute -start-24 -top-40 h-[28rem] w-[28rem] animate-float rounded-full bg-primary/25 blur-3xl" />
                    <div
                        className="absolute -end-24 top-10 h-[26rem] w-[26rem] animate-float rounded-full bg-gold/25 blur-3xl"
                        style={{ animationDelay: '-3s' }}
                    />
                    <div className="absolute start-1/3 -bottom-24 h-80 w-80 rounded-full bg-rose/20 blur-3xl" />
                    <div className="bg-grain absolute inset-0 opacity-50" />
                </div>

                <div className="container mx-auto grid items-center gap-14 ps-6 pe-4 pt-16 pb-24 md:ps-12 lg:grid-cols-[1.05fr_0.95fr] lg:ps-24 lg:pe-8 lg:pt-24">
                    <motion.div
                        variants={staggerContainer(0.1)}
                        initial="hidden"
                        animate="show"
                        className="flex flex-col items-start gap-6"
                    >
                        <motion.span
                            variants={fadeUp}
                            className="inline-flex items-center gap-2 rounded-full border border-gold/30 bg-gold/10 px-4 py-1.5 text-sm font-semibold text-gold-foreground"
                        >
                            <Moon className="h-4 w-4 text-gold" />
                            <span className="text-foreground/80">
                                {t('home.eyebrow')}
                            </span>
                        </motion.span>

                        <motion.h1
                            variants={fadeUp}
                            className="text-[2.75rem] leading-[1.05] font-semibold text-foreground sm:text-6xl lg:text-[4.25rem]"
                        >
                            {t('home.heroTitleA')}{' '}
                            <span className="relative whitespace-nowrap text-primary italic">
                                {t('home.heroTitleEm')}
                                <svg
                                    className="absolute start-0 -bottom-2 h-3 w-full text-gold"
                                    viewBox="0 0 200 12"
                                    fill="none"
                                    preserveAspectRatio="none"
                                    aria-hidden
                                >
                                    <path
                                        d="M2 8 Q60 2 100 6 T198 5"
                                        stroke="currentColor"
                                        strokeWidth="3.5"
                                        strokeLinecap="round"
                                    />
                                </svg>
                            </span>{' '}
                            {t('home.heroTitleB')}
                        </motion.h1>

                        <motion.p
                            variants={fadeUp}
                            className="max-w-lg text-lg leading-relaxed text-muted-foreground"
                        >
                            {t('home.heroSubtitle')}
                        </motion.p>

                        <motion.div
                            variants={fadeUp}
                            className="flex flex-col gap-3 pt-2 sm:flex-row"
                        >
                            <Link href={templates.index()}>
                                <Button
                                    variant="gold"
                                    size="xl"
                                    className="rounded-full"
                                >
                                    {t('home.ctaCreate')}
                                    <ArrowRight className="h-5 w-5 rtl:rotate-180" />
                                </Button>
                            </Link>
                            <Link href={books.index()}>
                                <Button
                                    variant="outline"
                                    size="xl"
                                    className="rounded-full"
                                >
                                    {t('home.ctaMyBooks')}
                                </Button>
                            </Link>
                        </motion.div>

                        <motion.div
                            variants={fadeUp}
                            className="mt-6 flex items-center gap-6 border-t border-border/60 pt-6"
                        >
                            <div className="flex flex-col">
                                <span className="font-display text-3xl font-bold text-foreground">
                                    {stats.totalBooks.toLocaleString()}
                                </span>
                                <span className="text-sm font-medium text-muted-foreground">
                                    {t('home.statStories')}
                                </span>
                            </div>
                            <div className="h-12 w-px bg-border" />
                            <div className="flex flex-col">
                                <div className="mb-1 flex gap-0.5">
                                    {[0, 1, 2, 3, 4].map((i) => (
                                        <Star
                                            key={i}
                                            className="h-5 w-5 fill-gold text-gold"
                                        />
                                    ))}
                                </div>
                                <span className="text-sm font-medium text-muted-foreground">
                                    {t('home.statLoved')}
                                </span>
                            </div>
                        </motion.div>
                    </motion.div>

                    <div className="relative">
                        <OpeningBook className="mx-auto" />
                    </div>
                </div>
            </section>

            {/* ========================= HOW IT WORKS ========================= */}
            <section className="relative py-24">
                <div className="container mx-auto px-4">
                    <motion.div
                        {...revealOnView}
                        className="mx-auto mb-16 max-w-2xl text-center"
                    >
                        <p className="font-display text-sm font-bold tracking-[0.2em] text-primary uppercase">
                            {t('home.howEyebrow')}
                        </p>
                        <h2 className="mt-3 text-4xl font-semibold text-foreground md:text-5xl">
                            {t('home.howTitle')}
                        </h2>
                        <p className="mt-4 text-lg text-muted-foreground">
                            {t('home.howSubtitle')}
                        </p>
                    </motion.div>

                    <motion.ol
                        variants={staggerContainer(0.14)}
                        initial="hidden"
                        whileInView="show"
                        viewport={{ once: true, amount: 0.2 }}
                        className="grid gap-6 md:grid-cols-3"
                    >
                        {steps.map((step, i) => (
                            <motion.li
                                key={step.key}
                                variants={fadeUp}
                                className="group relative flex flex-col items-start gap-4 rounded-3xl border border-card-border bg-card p-8 shadow-soft transition-transform hover:-translate-y-1.5"
                            >
                                <span className="absolute end-6 top-6 font-display text-5xl font-bold text-primary/10 transition-colors group-hover:text-gold/25">
                                    {String(i + 1).padStart(2, '0')}
                                </span>
                                <div className="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary/12 text-primary transition-transform group-hover:-translate-y-1">
                                    <step.icon className="h-7 w-7" />
                                </div>
                                <h3 className="text-2xl font-semibold text-foreground">
                                    {t(`home.${step.key}Title`)}
                                </h3>
                                <p className="text-muted-foreground">
                                    {t(`home.${step.key}Desc`)}
                                </p>
                            </motion.li>
                        ))}
                    </motion.ol>
                </div>
            </section>

            {/* ===================== ART STYLES SHOWCASE (night band) ===================== */}
            <section
                className="relative overflow-hidden py-24 text-white"
                style={{
                    background: 'linear-gradient(180deg,#1a1544,#12112b)',
                }}
            >
                <Starfield count={50} aurora />
                <div className="relative z-10 container mx-auto px-4">
                    <motion.div
                        {...revealOnView}
                        className="mx-auto mb-12 max-w-2xl text-center"
                    >
                        <p className="font-display text-sm font-bold tracking-[0.2em] text-gold uppercase">
                            {t('home.stylesEyebrow')}
                        </p>
                        <h2 className="mt-3 text-4xl font-semibold text-white md:text-5xl">
                            {t('home.stylesTitle')}
                        </h2>
                        <p className="mt-4 text-lg text-white/70">
                            {t('home.stylesSubtitle')}
                        </p>
                    </motion.div>

                    <motion.div
                        variants={staggerContainer(0.05)}
                        initial="hidden"
                        whileInView="show"
                        viewport={{ once: true, amount: 0.2 }}
                        className="mx-auto flex max-w-4xl flex-wrap justify-center gap-3"
                    >
                        {ART_STYLES.map((style) => (
                            <motion.span
                                key={style}
                                variants={fadeUp}
                                className="rounded-full border border-white/15 bg-white/5 px-5 py-2.5 text-sm font-semibold text-white/85 backdrop-blur-sm transition-colors hover:border-gold/50 hover:text-white"
                            >
                                {prettyStyle(style)}
                            </motion.span>
                        ))}
                    </motion.div>
                </div>
            </section>

            {/* ========================= FEATURES ========================= */}
            <section className="py-24">
                <div className="container mx-auto px-4">
                    <motion.div
                        {...revealOnView}
                        className="mx-auto mb-16 max-w-2xl text-center"
                    >
                        <p className="font-display text-sm font-bold tracking-[0.2em] text-primary uppercase">
                            {t('home.whyEyebrow')}
                        </p>
                        <h2 className="mt-3 text-4xl font-semibold text-foreground md:text-5xl">
                            {t('home.whyTitle')}
                        </h2>
                    </motion.div>

                    <motion.div
                        variants={staggerContainer(0.1)}
                        initial="hidden"
                        whileInView="show"
                        viewport={{ once: true, amount: 0.2 }}
                        className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4"
                    >
                        {features.map((f) => (
                            <motion.div
                                key={f.key}
                                variants={fadeUp}
                                className="flex flex-col gap-3 rounded-3xl border border-card-border bg-card p-7 shadow-soft"
                            >
                                <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-gold/15 text-gold">
                                    <f.icon className="h-6 w-6" />
                                </div>
                                <h3 className="text-xl font-semibold text-foreground">
                                    {t(`home.${f.key}Title`)}
                                </h3>
                                <p className="text-sm leading-relaxed text-muted-foreground">
                                    {t(`home.${f.key}Desc`)}
                                </p>
                            </motion.div>
                        ))}
                    </motion.div>
                </div>
            </section>

            {/* ========================= FINAL CTA ========================= */}
            <section className="pt-4 pb-28">
                <div className="container mx-auto px-4">
                    <motion.div
                        {...revealOnView}
                        className="relative mx-auto max-w-5xl overflow-hidden rounded-[2.5rem] px-8 py-20 text-center shadow-lift sm:px-16"
                        style={{
                            background:
                                'linear-gradient(135deg,#4b3fa0,#2a2170 60%,#171338)',
                        }}
                    >
                        <Starfield count={36} aurora />
                        <div className="relative z-10 mx-auto max-w-2xl">
                            <Sparkles className="mx-auto mb-6 h-9 w-9 text-gold" />
                            <h2 className="text-4xl font-semibold text-white md:text-5xl">
                                {t('home.finalTitle')}
                            </h2>
                            <p className="mx-auto mt-5 max-w-xl text-lg text-white/75">
                                {t('home.finalSubtitle')}
                            </p>
                            <Link href={templates.index()}>
                                <Button
                                    variant="gold"
                                    size="xl"
                                    className="mt-9 rounded-full"
                                >
                                    {t('home.finalCta')}
                                    <ArrowRight className="h-5 w-5 rtl:rotate-180" />
                                </Button>
                            </Link>
                        </div>
                    </motion.div>
                </div>
            </section>
        </div>
    );
}
