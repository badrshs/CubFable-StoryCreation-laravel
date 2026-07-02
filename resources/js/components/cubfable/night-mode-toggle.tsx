import { Moon, Sun } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useAppearance } from '@/hooks/use-appearance';
import { useT } from '@/i18n';

// A calm day/night switch. Toggles the global .dark class via the shared
// appearance store (persisted to localStorage + cookie for SSR).
export default function NightModeToggle({
    className = '',
}: {
    className?: string;
}) {
    const t = useT();
    const { resolvedAppearance, updateAppearance } = useAppearance();

    const isDark = resolvedAppearance === 'dark';

    return (
        <Button
            variant="ghost"
            size="icon"
            className={`rounded-full text-muted-foreground hover:text-foreground ${className}`}
            aria-label={
                isDark ? t('theme.switchToDay') : t('theme.switchToNight')
            }
            title={isDark ? t('theme.switchToDay') : t('theme.switchToNight')}
            onClick={() => updateAppearance(isDark ? 'light' : 'dark')}
        >
            {isDark ? (
                <Sun className="h-4 w-4" />
            ) : (
                <Moon className="h-4 w-4" />
            )}
        </Button>
    );
}
