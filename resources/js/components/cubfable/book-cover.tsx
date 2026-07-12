import { BookOpen } from 'lucide-react';

type BookCoverProps = {
    coverImageUrl?: string | null;
    className?: string;
    // A subtle on-shelf lift on hover (used in the library grid).
    interactive?: boolean;
};

const FALLBACK_BG = 'linear-gradient(150deg,#4b3fa0,#2a2170 55%,#171338)';

// A gold filigree corner ornament, rotated into each corner of the frame.
function CornerFlourish({ className }: { className: string }) {
    return (
        <svg
            viewBox="0 0 56 56"
            className={className}
            fill="none"
            stroke="currentColor"
            strokeWidth="1.3"
            strokeLinecap="round"
        >
            <path d="M6 50 L6 20 Q6 8 18 6" />
            <path
                d="M6 20 Q14 20 17 14 Q20 8 28 9"
                strokeWidth="1"
                opacity="0.85"
            />
            <path d="M11 50 Q11 32 22 27" strokeWidth="1" opacity="0.7" />
            <circle cx="7" cy="49" r="1.8" fill="currentColor" stroke="none" />
            <circle cx="28" cy="9" r="1.4" fill="currentColor" stroke="none" />
        </svg>
    );
}

// A hardcover book object for the shelf: the AI-generated cover art (which
// carries its own title) shown as the front face, dressed with an ornate gold
// frame and corner filigree, a cloth binding, stacked page edges and a grounded
// shadow. The title is NOT overlaid here - it lives in the generated image.
export default function BookCover({
    coverImageUrl,
    className = '',
    interactive = true,
}: BookCoverProps) {
    return (
        <div className={`group/book [perspective:1200px] ${className}`}>
            <div
                className={`relative aspect-[3/4] w-full rounded-l-[3px] rounded-r-[6px] transition-all duration-500 ease-out ${interactive ? 'group-hover/book:-translate-y-1.5 group-hover/book:rotate-[0.4deg]' : ''}`}
                style={{
                    boxShadow:
                        '2px 0 0 #efe6d0, 4px 0 0 #e4d8bd, 6px 0 0 #efe6d0, 8px 0 0 #e0d3b4, 18px 22px 30px -10px rgba(28,18,6,0.55)',
                }}
            >
                <div
                    className="absolute inset-0 overflow-hidden rounded-l-[3px] rounded-r-[6px]"
                    style={{ background: FALLBACK_BG }}
                >
                    {coverImageUrl ? (
                        <img
                            src={coverImageUrl}
                            alt=""
                            // object-top: 3:4 covers fill the frame exactly;
                            // taller legacy covers crop from the bottom so the
                            // painted title near the top is never clipped.
                            className="absolute inset-0 h-full w-full object-cover object-top"
                        />
                    ) : (
                        <div className="flex h-full w-full items-center justify-center">
                            <BookOpen className="h-10 w-10 text-white/40" />
                        </div>
                    )}

                    {/* Cloth binding down the left edge */}
                    <div className="absolute inset-y-0 left-0 w-[6%] bg-gradient-to-r from-black/40 to-transparent" />

                    {/* Ornate gold frame */}
                    <div className="pointer-events-none absolute inset-[5px] rounded-[4px] border-2 border-amber-200/55" />
                    <div className="pointer-events-none absolute inset-[9px] rounded-[2px] border border-amber-200/35" />

                    {/* Corner filigree */}
                    <div className="pointer-events-none absolute inset-[5px] text-amber-200/80">
                        <CornerFlourish className="absolute top-0 left-0 h-7 w-7" />
                        <CornerFlourish className="absolute top-0 right-0 h-7 w-7 -scale-x-100" />
                        <CornerFlourish className="absolute bottom-0 left-0 h-7 w-7 -scale-y-100" />
                        <CornerFlourish className="absolute right-0 bottom-0 h-7 w-7 -scale-100" />
                    </div>

                    {/* Glossy sheen */}
                    <div className="pointer-events-none absolute inset-0 bg-gradient-to-tr from-transparent via-white/5 to-white/10" />
                </div>
            </div>
        </div>
    );
}
