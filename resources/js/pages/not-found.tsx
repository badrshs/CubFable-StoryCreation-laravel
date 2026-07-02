import { Link } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import { Home, Wand2 } from 'lucide-react';
import Starfield from '@/components/cubfable/starfield';
import { Button } from '@/components/ui/button';
import { useT } from '@/i18n';
import { fadeUp, staggerContainer } from '@/lib/motion';
import { home } from '@/routes';
import templates from '@/routes/templates';

export default function NotFound() {
    const t = useT();
    const reduce = useReducedMotion();

    return (
        <section className="bg-grain relative flex flex-1 items-center justify-center overflow-hidden px-4 py-16">
            {/* A soft twilight wash so the lost page still feels in-world. */}
            <div className="pointer-events-none absolute inset-0 opacity-70 dark:opacity-100">
                <Starfield count={30} aurora />
            </div>

            <motion.div
                variants={staggerContainer(0.1)}
                initial="hidden"
                animate="show"
                className="relative z-10 w-full max-w-lg text-center"
            >
                {/* A little lost-lantern glyph: a stray star drifting off the page. */}
                <motion.div
                    variants={fadeUp}
                    className="mb-6 flex justify-center"
                >
                    <div className="relative">
                        <span
                            className="absolute inset-0 -z-10 rounded-full blur-2xl"
                            style={{
                                background:
                                    'radial-gradient(circle, hsl(42 95% 66% / 0.4), transparent 70%)',
                            }}
                            aria-hidden
                        />
                        <motion.svg
                            width="88"
                            height="88"
                            viewBox="0 0 88 88"
                            fill="none"
                            role="img"
                            aria-label={t('notFound.glyphAlt')}
                            animate={reduce ? undefined : { y: [0, -8, 0] }}
                            transition={{
                                duration: 6,
                                repeat: Infinity,
                                ease: 'easeInOut',
                            }}
                        >
                            <circle
                                cx="44"
                                cy="44"
                                r="42"
                                fill="hsl(249 62% 46% / 0.14)"
                            />
                            <circle
                                cx="44"
                                cy="44"
                                r="42"
                                stroke="hsl(42 92% 64% / 0.4)"
                                strokeWidth="1.4"
                            />
                            {/* crescent moon */}
                            <path
                                d="M52 26 a20 20 0 1 0 10 30 a16 16 0 1 1 -10 -30 z"
                                fill="hsl(42 95% 66%)"
                                fillOpacity="0.9"
                            />
                            {/* stray sparkle wandering off */}
                            <path
                                d="M30 30 l1.4 4 l4 1.4 l-4 1.4 l-1.4 4 l-1.4 -4 l-4 -1.4 l4 -1.4 z"
                                fill="hsl(7 84% 74%)"
                            />
                        </motion.svg>
                    </div>
                </motion.div>

                <motion.p
                    variants={fadeUp}
                    className="font-display text-xs font-bold tracking-[0.24em] text-gold uppercase"
                >
                    {t('notFound.eyebrow')}
                </motion.p>

                <motion.h1
                    variants={fadeUp}
                    className="mt-3 font-serif text-5xl leading-tight font-bold text-foreground sm:text-6xl"
                >
                    {t('notFound.heading')}
                </motion.h1>

                <motion.p
                    variants={fadeUp}
                    className="mx-auto mt-4 max-w-md text-base leading-relaxed text-muted-foreground"
                >
                    {t('notFound.body')}
                </motion.p>

                <motion.div
                    variants={fadeUp}
                    className="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row"
                >
                    <Link href={home()}>
                        <Button
                            variant="gold"
                            size="lg"
                            className="rounded-full"
                        >
                            <Home className="h-4 w-4" aria-hidden />
                            {t('notFound.home')}
                        </Button>
                    </Link>
                    <Link href={templates.index()}>
                        <Button
                            variant="outline"
                            size="lg"
                            className="rounded-full"
                        >
                            <Wand2 className="h-4 w-4" aria-hidden />
                            {t('notFound.browse')}
                        </Button>
                    </Link>
                </motion.div>
            </motion.div>
        </section>
    );
}
