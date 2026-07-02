import { Link } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import type { ReactNode } from 'react';
import { BrandGlyph } from '@/components/cubfable/brand-mark';
import OpeningBook from '@/components/cubfable/opening-book';
import Starfield from '@/components/cubfable/starfield';
import { fadeUp, staggerContainer } from '@/lib/motion';
import { home } from '@/routes';

// Layout props: pages override these via `setLayoutProps()` (re-set on every
// render so translated strings follow the active language) or via a static
// `Page.layout = { ... }` props object. Everything has a default so pages
// that pass nothing still render.
export type AuthLayoutProps = {
    // Small label above the heading, e.g. "Sign in".
    eyebrow?: string;
    title?: string;
    subtitle?: string;
    // Caption + hero name shown on the storybook in the brand panel.
    storyCaption?: string;
    storyHero?: string;
    // Rotating line of reassurance shown under the wordmark on the dark panel.
    panelTagline?: string;
};

// Shared shell for the auth pages: a fixed starlit "night sky" brand panel
// (the signature opening book) beside a warm, token-driven form surface. The
// dark panel is always night regardless of theme; the form surface uses tokens
// so it reads correctly in both light and dark.
export default function AuthLayout({
    eyebrow = 'Welcome',
    title = 'Your storybook awaits',
    subtitle = '',
    children,
    storyCaption = 'A bedtime tale starring',
    storyHero = 'your little one',
    panelTagline = 'Every great bedtime story begins with a name.',
}: AuthLayoutProps & { children: ReactNode }) {
    const reduce = useReducedMotion();

    return (
        <section className="grid min-h-svh lg:grid-cols-[1.05fr_1fr]">
            {/* Brand / story panel - a fixed twilight night, both themes. */}
            <aside
                className="relative hidden overflow-hidden p-10 text-white lg:flex lg:flex-col lg:justify-between xl:p-14"
                style={{
                    background:
                        'linear-gradient(165deg,#211b57 0%,#161238 55%,#0d0a26 100%)',
                }}
            >
                <Starfield count={52} aurora />

                {/* Wordmark */}
                <Link
                    href={home()}
                    className="relative z-10 inline-flex w-fit items-center gap-2.5 rounded-full focus-visible:ring-2 focus-visible:ring-gold/70 focus-visible:outline-none"
                >
                    <BrandGlyph size={34} />
                    <span className="font-display text-xl leading-none font-bold tracking-tight text-white">
                        Cub<span className="text-lamplight">Fable</span>
                    </span>
                </Link>

                {/* The signature opening book */}
                <div className="relative z-10 my-8 flex flex-1 items-center justify-center">
                    <OpeningBook
                        heroName={storyHero}
                        caption={storyCaption}
                        className="w-full max-w-[360px]"
                    />
                </div>

                {/* Reassurance line */}
                <motion.p
                    initial={reduce ? undefined : { opacity: 0, y: 12 }}
                    animate={reduce ? undefined : { opacity: 1, y: 0 }}
                    transition={{
                        duration: 0.7,
                        delay: 0.4,
                        ease: [0.22, 1, 0.36, 1],
                    }}
                    className="relative z-10 max-w-sm font-serif text-lg leading-relaxed text-white/80 italic"
                >
                    {panelTagline}
                </motion.p>
            </aside>

            {/* Form panel - token-driven, reads in light + dark. */}
            <div className="bg-grain relative flex items-center justify-center bg-background px-4 py-12 sm:px-6 lg:px-10">
                <motion.div
                    variants={staggerContainer(0.08)}
                    initial="hidden"
                    animate="show"
                    className="w-full max-w-md"
                >
                    {/* Compact brand mark for small screens (brand panel is hidden there). */}
                    <motion.div
                        variants={fadeUp}
                        className="mb-8 flex justify-center lg:hidden"
                    >
                        <Link
                            href={home()}
                            className="inline-flex items-center gap-2.5 rounded-full focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            <BrandGlyph size={32} />
                            <span className="font-display text-lg leading-none font-bold tracking-tight text-foreground">
                                Cub<span className="text-lamplight">Fable</span>
                            </span>
                        </Link>
                    </motion.div>

                    <motion.div
                        variants={fadeUp}
                        className="mb-7 text-center lg:text-start"
                    >
                        <p className="font-display text-xs font-bold tracking-[0.2em] text-gold uppercase">
                            {eyebrow}
                        </p>
                        <h1 className="mt-2 font-serif text-4xl leading-tight font-bold text-foreground">
                            {title}
                        </h1>
                        {subtitle ? (
                            <p className="mt-2 text-muted-foreground">
                                {subtitle}
                            </p>
                        ) : null}
                    </motion.div>

                    <motion.div variants={fadeUp}>{children}</motion.div>
                </motion.div>
            </div>
        </section>
    );
}
