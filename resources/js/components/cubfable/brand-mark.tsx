type BrandMarkProps = {
    // Pixel size of the square glyph.
    size?: number;
    // Show the "CubFable" wordmark next to the glyph.
    withWordmark?: boolean;
    className?: string;
};

// The CubFable identity: a sleepy star-gazing cub tucked into a twilight tile,
// with a lamplight-gold sparkle. Pure SVG (no emoji, no external asset).
export function BrandGlyph({
    size = 36,
    className = '',
}: {
    size?: number;
    className?: string;
}) {
    return (
        <svg
            width={size}
            height={size}
            viewBox="0 0 40 40"
            fill="none"
            className={className}
            role="img"
            aria-label="CubFable"
        >
            <defs>
                <linearGradient
                    id="cf-tile"
                    x1="0"
                    y1="0"
                    x2="40"
                    y2="40"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stopColor="hsl(249 62% 46%)" />
                    <stop offset="1" stopColor="hsl(253 56% 24%)" />
                </linearGradient>
            </defs>
            <rect
                x="1.5"
                y="1.5"
                width="37"
                height="37"
                rx="12"
                fill="url(#cf-tile)"
            />
            <rect
                x="1.5"
                y="1.5"
                width="37"
                height="37"
                rx="12"
                stroke="hsl(42 92% 64%)"
                strokeOpacity="0.55"
                strokeWidth="1.4"
            />
            {/* ears */}
            <circle cx="13" cy="15" r="4.4" fill="hsl(44 58% 93%)" />
            <circle cx="27" cy="15" r="4.4" fill="hsl(44 58% 93%)" />
            <circle cx="13" cy="15" r="2" fill="hsl(7 84% 74%)" />
            <circle cx="27" cy="15" r="2" fill="hsl(7 84% 74%)" />
            {/* head */}
            <circle cx="20" cy="23" r="9" fill="hsl(44 58% 93%)" />
            {/* eyes (gently closed, star-gazing) */}
            <path
                d="M15.5 22.2 q1.6 1.4 3.2 0"
                stroke="hsl(252 40% 22%)"
                strokeWidth="1.4"
                strokeLinecap="round"
                fill="none"
            />
            <path
                d="M21.3 22.2 q1.6 1.4 3.2 0"
                stroke="hsl(252 40% 22%)"
                strokeWidth="1.4"
                strokeLinecap="round"
                fill="none"
            />
            {/* muzzle */}
            <circle cx="20" cy="26" r="0.95" fill="hsl(252 40% 22%)" />
            {/* lamplight sparkle */}
            <path
                d="M31 8 l0.9 2.6 l2.6 0.9 l-2.6 0.9 l-0.9 2.6 l-0.9 -2.6 l-2.6 -0.9 l2.6 -0.9 z"
                fill="hsl(42 95% 66%)"
            />
        </svg>
    );
}

export default function BrandMark({
    size = 36,
    withWordmark = true,
    className = '',
}: BrandMarkProps) {
    return (
        <span className={`inline-flex items-center gap-2.5 ${className}`}>
            <BrandGlyph size={size} />
            {withWordmark && (
                <span className="font-display text-xl leading-none font-bold tracking-tight text-foreground">
                    Cub<span className="text-lamplight">Fable</span>
                </span>
            )}
        </span>
    );
}
