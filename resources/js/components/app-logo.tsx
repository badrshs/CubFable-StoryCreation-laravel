import { BrandGlyph } from '@/components/cubfable/brand-mark';

export default function AppLogo() {
    return (
        <>
            <BrandGlyph size={32} className="shrink-0" />
            <div className="ms-1 grid flex-1 text-start text-sm">
                <span className="mb-0.5 truncate leading-tight font-semibold">
                    Cub<span className="text-lamplight">Fable</span>
                </span>
            </div>
        </>
    );
}
