import { motion, useReducedMotion } from 'framer-motion';
import Starfield from '@/components/cubfable/starfield';

type OpeningBookProps = {
    // Name shown on the inside caption ("starring ___"). Defaults gently.
    heroName?: string;
    caption?: string;
    className?: string;
};

// The signature: an open storybook, tilted on a desk, whose right page reveals a
// twilight spread where the child is the hero. Fully self-contained (never
// overflows its column) with a soft entrance + float; under prefers-reduced-motion
// it simply sits still.
export default function OpeningBook({
    heroName,
    caption,
    className = '',
}: OpeningBookProps) {
    const reduce = useReducedMotion();

    return (
        <div
            className={`relative mx-auto w-full max-w-[560px] [perspective:1700px] ${className}`}
        >
            <motion.div
                initial={{ opacity: 0, y: 26, rotateX: 12, scale: 0.95 }}
                animate={{ opacity: 1, y: 0, rotateX: 0, scale: 1 }}
                transition={{ duration: 0.9, ease: [0.22, 1, 0.36, 1] }}
                style={{ transformStyle: 'preserve-3d' }}
            >
                <motion.div
                    animate={reduce ? undefined : { y: [0, -9, 0] }}
                    transition={{
                        duration: 7,
                        repeat: Infinity,
                        ease: 'easeInOut',
                    }}
                    className="relative"
                >
                    {/* Page-stack depth behind the open book */}
                    <div className="absolute inset-x-3 top-2 -bottom-2 rounded-[20px] bg-[hsl(250_30%_78%)]/40 blur-[2px]" />
                    <div className="absolute inset-x-1.5 top-1 -bottom-1 rounded-[20px] bg-[hsl(44_45%_90%)]" />

                    {/* Hardcover board wrapping the spread */}
                    <div
                        className="relative rounded-[18px] p-2.5 shadow-[var(--shadow-lift)]"
                        style={{
                            background:
                                'linear-gradient(140deg,#4b3fa0,#2a2170 60%,#171338)',
                            boxShadow:
                                '0 40px 70px -30px rgba(23,19,56,0.7), inset 0 0 0 1px rgba(242,178,62,0.18)',
                        }}
                    >
                        <div className="relative grid grid-cols-2 overflow-hidden rounded-[10px]">
                            {/* Left page - the opening line on warm paper */}
                            <div className="relative flex min-h-[360px] flex-col justify-center gap-4 bg-[#fbf3e3] p-6 sm:p-7">
                                <span className="font-display text-[0.65rem] tracking-[0.22em] text-[#bfa063] uppercase">
                                    Chapter One
                                </span>
                                <p className="font-serif text-xl leading-snug text-[#3a2e1a] italic sm:text-[1.6rem]">
                                    Once upon a time, a little hero set off into
                                    the whispering wood...
                                </p>
                                <span className="absolute start-6 bottom-3 font-serif text-xs text-[#b3a179]">
                                    1
                                </span>
                            </div>

                            {/* Right page - the twilight illustration */}
                            <div
                                className="relative min-h-[360px] overflow-hidden"
                                style={{
                                    background:
                                        'linear-gradient(180deg,#1b1746,#0f0c2b 62%,#0b081f)',
                                }}
                            >
                                <Starfield count={26} aurora />

                                {/* Moon */}
                                <div
                                    className="absolute end-6 top-6 h-12 w-12 rounded-full sm:h-14 sm:w-14"
                                    style={{
                                        background:
                                            'radial-gradient(circle at 35% 35%, #fff4d6, #f2b84a 70%, #e09a2e)',
                                        boxShadow:
                                            '0 0 34px 6px hsl(42 90% 60% / 0.45)',
                                    }}
                                />

                                {/* Rolling hills + hero scene */}
                                <svg
                                    viewBox="0 0 300 380"
                                    className="absolute inset-0 h-full w-full"
                                    preserveAspectRatio="xMidYMax slice"
                                    aria-hidden
                                >
                                    <path
                                        d="M0 280 Q80 232 160 268 T300 262 V380 H0 Z"
                                        fill="hsl(252 42% 20%)"
                                    />
                                    <path
                                        d="M0 320 Q90 284 180 312 T300 304 V380 H0 Z"
                                        fill="hsl(250 46% 13%)"
                                    />
                                    <g transform="translate(58 284)">
                                        <rect
                                            x="-3"
                                            y="0"
                                            width="6"
                                            height="24"
                                            rx="2"
                                            fill="hsl(28 40% 22%)"
                                        />
                                        <circle
                                            cx="0"
                                            cy="-6"
                                            r="15"
                                            fill="hsl(158 34% 30%)"
                                        />
                                        <circle
                                            cx="-11"
                                            cy="2"
                                            r="10"
                                            fill="hsl(158 34% 26%)"
                                        />
                                        <circle
                                            cx="11"
                                            cy="2"
                                            r="10"
                                            fill="hsl(158 34% 26%)"
                                        />
                                    </g>
                                    <g
                                        transform="translate(196 284)"
                                        fill="hsl(250 40% 8%)"
                                    >
                                        <circle cx="0" cy="-19" r="6.5" />
                                        <rect
                                            x="-5.5"
                                            y="-13"
                                            width="11"
                                            height="19"
                                            rx="5"
                                        />
                                        <circle cx="17" cy="-3" r="5" />
                                        <circle cx="13.8" cy="-7" r="1.9" />
                                        <circle cx="20.2" cy="-7" r="1.9" />
                                    </g>
                                </svg>

                                <div className="absolute inset-x-0 top-5 ps-5 pe-16 text-start">
                                    <p className="font-serif text-lg leading-tight text-[hsl(44_60%_92%)] italic drop-shadow sm:text-xl">
                                        The Whispering Oak
                                    </p>
                                </div>

                                <div className="absolute inset-x-0 bottom-0 p-4">
                                    <div className="rounded-xl bg-[hsl(245_45%_10%)]/70 px-4 py-2.5 text-center ring-1 ring-white/10 backdrop-blur-sm">
                                        <p className="text-[0.62rem] font-semibold tracking-[0.2em] text-[hsl(42_90%_66%)] uppercase">
                                            {caption ??
                                                'A bedtime tale starring'}
                                        </p>
                                        <p className="mt-0.5 font-display text-base font-bold text-white">
                                            {heroName ?? 'your little one'}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            {/* Center binding shadow */}
                            <div
                                className="pointer-events-none absolute inset-y-0 left-1/2 w-10 -translate-x-1/2"
                                style={{
                                    background:
                                        'linear-gradient(90deg, transparent, rgba(23,19,56,0.22) 42%, rgba(23,19,56,0.34) 50%, rgba(23,19,56,0.22) 58%, transparent)',
                                }}
                            />
                        </div>
                    </div>
                </motion.div>
            </motion.div>
        </div>
    );
}
