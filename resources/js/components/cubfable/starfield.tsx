import { useMemo } from 'react';

type StarfieldProps = {
    // Number of stars to scatter. Keep modest; these animate.
    count?: number;
    // Show soft indigo/gold aurora blobs behind the stars.
    aurora?: boolean;
    className?: string;
};

type Star = {
    top: string;
    left: string;
    size: number;
    delay: string;
    duration: string;
    opacity: number;
    gold: boolean;
};

// An ambient night sky for dark surfaces: gently twinkling stars over soft
// aurora light. Purely decorative (aria-hidden) and disabled under
// prefers-reduced-motion via the global CSS guard. Drop inside a `relative`,
// `overflow-hidden` dark container.
export default function Starfield({
    count = 44,
    aurora = true,
    className = '',
}: StarfieldProps) {
    // The scatter is intentionally random: purely decorative stars whose
    // positions are frozen by useMemo for the life of the component.
    /* eslint-disable react-hooks/purity */
    const stars = useMemo<Star[]>(() => {
        return Array.from({ length: count }, (_, i) => {
            const gold = i % 7 === 0;

            return {
                top: `${Math.round(Math.random() * 100)}%`,
                left: `${Math.round(Math.random() * 100)}%`,
                size: gold ? 2.5 + Math.random() * 2 : 1 + Math.random() * 2,
                delay: `${(Math.random() * 4.5).toFixed(2)}s`,
                duration: `${(3.5 + Math.random() * 3).toFixed(2)}s`,
                opacity: 0.35 + Math.random() * 0.5,
                gold,
            };
        });
    }, [count]);
    /* eslint-enable react-hooks/purity */

    return (
        <div
            aria-hidden
            className={`pointer-events-none absolute inset-0 overflow-hidden ${className}`}
        >
            {aurora && (
                <>
                    <div
                        className="absolute -top-1/3 left-[-10%] h-[70%] w-[55%] animate-aurora rounded-full blur-3xl"
                        style={{
                            background:
                                'radial-gradient(circle, hsl(249 72% 60% / 0.5), transparent 70%)',
                        }}
                    />
                    <div
                        className="absolute right-[-10%] -bottom-1/4 h-[65%] w-[55%] animate-aurora rounded-full blur-3xl"
                        style={{
                            background:
                                'radial-gradient(circle, hsl(40 90% 60% / 0.22), transparent 70%)',
                            animationDelay: '-9s',
                        }}
                    />
                </>
            )}
            {stars.map((s, i) => (
                <span
                    key={i}
                    className="absolute animate-twinkle rounded-full"
                    style={{
                        top: s.top,
                        left: s.left,
                        width: s.size,
                        height: s.size,
                        opacity: s.opacity,
                        animationDelay: s.delay,
                        animationDuration: s.duration,
                        background: s.gold
                            ? 'hsl(42 95% 68%)'
                            : 'hsl(220 70% 92%)',
                        boxShadow: s.gold
                            ? '0 0 6px hsl(42 95% 68% / 0.8)'
                            : '0 0 5px hsl(220 80% 92% / 0.7)',
                    }}
                />
            ))}
        </div>
    );
}
