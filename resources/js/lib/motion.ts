import type { Variants, Transition } from 'framer-motion';

// Shared motion language for CubFable. Everything is gentle and "settled" -
// entrances drift up like a page being set down, never bouncy or frantic.

export const easeOutSoft: Transition['ease'] = [0.22, 1, 0.36, 1];

export const springSoft: Transition = {
    type: 'spring',
    stiffness: 260,
    damping: 26,
    mass: 0.9,
};

// Container that staggers its children on enter.
export const staggerContainer = (stagger = 0.08, delay = 0): Variants => ({
    hidden: {},
    show: {
        transition: { staggerChildren: stagger, delayChildren: delay },
    },
});

// A single item drifting up into place.
export const fadeUp: Variants = {
    hidden: { opacity: 0, y: 24 },
    show: {
        opacity: 1,
        y: 0,
        transition: { duration: 0.6, ease: easeOutSoft },
    },
};

export const fadeIn: Variants = {
    hidden: { opacity: 0 },
    show: { opacity: 1, transition: { duration: 0.7, ease: easeOutSoft } },
};

export const scaleIn: Variants = {
    hidden: { opacity: 0, scale: 0.94 },
    show: {
        opacity: 1,
        scale: 1,
        transition: { duration: 0.7, ease: easeOutSoft },
    },
};

// Props to reveal a block the first time it scrolls into view.
export const revealOnView = {
    initial: 'hidden' as const,
    whileInView: 'show' as const,
    viewport: { once: true, amount: 0.3 },
    variants: fadeUp,
};
