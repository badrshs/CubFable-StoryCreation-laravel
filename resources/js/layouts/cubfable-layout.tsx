import { Link, router, usePage } from '@inertiajs/react';
import {
    BookMarked,
    Library as LibraryIcon,
    LogOut,
    ShieldCheck,
    Sparkles,
    UserRound,
    Wand2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import BrandMark, { BrandGlyph } from '@/components/cubfable/brand-mark';
import LanguageSwitcher from '@/components/cubfable/language-switcher';
import NightModeToggle from '@/components/cubfable/night-mode-toggle';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useT } from '@/i18n';
import { cn } from '@/lib/utils';
import { account, home, login, logout, register } from '@/routes';
import { index as booksIndex } from '@/routes/books';
import { index as charactersIndex } from '@/routes/characters';
import { index as templatesIndex } from '@/routes/templates';

const navLink =
    'text-sm font-semibold text-muted-foreground hover:text-foreground transition-colors';

export default function CubFableLayout({ children }: { children: ReactNode }) {
    const t = useT();
    const page = usePage();
    const { auth, registrationOpen } = page.props;
    const user = auth.user;
    const currentPath = page.url.split('?')[0];

    function isCurrentPath(path: string): boolean {
        return currentPath === path || currentPath.startsWith(`${path}/`);
    }

    function navLinkClass(path: string): string {
        return cn(navLink, isCurrentPath(path) && 'text-foreground');
    }

    function handleLogout() {
        router.flushAll();
        router.post(
            logout.url(),
            {},
            {
                onSuccess: () => router.visit(home.url()),
            },
        );
    }

    return (
        <div className="flex min-h-[100dvh] flex-col bg-background selection:bg-primary/20 selection:text-primary">
            <header className="sticky top-0 z-50 w-full border-b border-border/50 bg-background/80 backdrop-blur-xl">
                <div className="container mx-auto flex h-16 items-center justify-between gap-4 px-4">
                    <Link
                        href={home()}
                        className="flex items-center transition-transform hover:-translate-y-0.5"
                    >
                        <BrandMark size={34} />
                    </Link>

                    <nav className="hidden items-center gap-7 md:flex">
                        <Link
                            href={templatesIndex()}
                            className={navLinkClass(templatesIndex.url())}
                        >
                            {t('nav.templates')}
                        </Link>
                        <Link
                            href={booksIndex()}
                            className={navLinkClass(booksIndex.url())}
                        >
                            {t('nav.myBooks')}
                        </Link>
                        {user && (
                            <Link
                                href={charactersIndex()}
                                className={navLinkClass(charactersIndex.url())}
                            >
                                {t('nav.library')}
                            </Link>
                        )}
                    </nav>

                    <div className="flex items-center gap-1.5 sm:gap-2">
                        <LanguageSwitcher />
                        <NightModeToggle />

                        {user ? (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/12 text-sm font-bold text-primary ring-1 ring-primary/20 transition-colors hover:bg-primary/20 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        aria-label={t('nav.account')}
                                        data-testid="account-menu"
                                    >
                                        {user.email.charAt(0).toUpperCase()}
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent
                                    align="end"
                                    className="w-56"
                                >
                                    <DropdownMenuLabel
                                        className="truncate font-normal text-muted-foreground"
                                        title={user.email}
                                    >
                                        {user.email}
                                    </DropdownMenuLabel>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href={booksIndex()}
                                            className="cursor-pointer gap-2"
                                        >
                                            <BookMarked className="h-4 w-4" />{' '}
                                            {t('nav.myBooks')}
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href={charactersIndex()}
                                            className="cursor-pointer gap-2"
                                        >
                                            <LibraryIcon className="h-4 w-4" />{' '}
                                            {t('nav.library')}
                                        </Link>
                                    </DropdownMenuItem>
                                    <DropdownMenuItem asChild>
                                        <Link
                                            href={account()}
                                            className="cursor-pointer gap-2"
                                        >
                                            <UserRound className="h-4 w-4" />{' '}
                                            {t('nav.account')}
                                        </Link>
                                    </DropdownMenuItem>
                                    {user.is_admin && (
                                        <DropdownMenuItem asChild>
                                            <Link
                                                href="/admin"
                                                className="cursor-pointer gap-2"
                                            >
                                                <ShieldCheck className="h-4 w-4" />{' '}
                                                {t('nav.admin')}
                                            </Link>
                                        </DropdownMenuItem>
                                    )}
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem
                                        onClick={handleLogout}
                                        className="cursor-pointer gap-2 text-destructive focus:text-destructive"
                                        data-testid="logout-button"
                                    >
                                        <LogOut className="h-4 w-4" />{' '}
                                        {t('auth.logout')}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        ) : (
                            <Link
                                href={login()}
                                className={`${navLink} hidden px-1 sm:inline`}
                            >
                                {t('auth.login')}
                            </Link>
                        )}

                        <Button
                            asChild
                            variant="gold"
                            className="rounded-full shadow-soft"
                        >
                            <Link href={templatesIndex()}>
                                <Wand2 className="h-4 w-4" />
                                <span className="hidden sm:inline">
                                    {t('nav.createBook')}
                                </span>
                                <span className="sm:hidden">
                                    {t('nav.createShort')}
                                </span>
                            </Link>
                        </Button>
                    </div>
                </div>
            </header>

            <main className="flex flex-1 flex-col">{children}</main>

            <footer className="mt-auto border-t border-border/50 bg-card/60">
                <div className="container mx-auto px-4 py-14">
                    <div className="grid gap-10 md:grid-cols-[1.5fr_1fr_1fr]">
                        <div className="max-w-xs">
                            <BrandMark size={34} />
                            <p className="mt-4 text-sm leading-relaxed text-muted-foreground">
                                {t('footer.tagline')}
                            </p>
                        </div>

                        <div>
                            <h4 className="font-display text-sm font-bold tracking-wider text-foreground uppercase">
                                {t('footer.explore')}
                            </h4>
                            <ul className="mt-4 space-y-2.5 text-sm">
                                <li>
                                    <Link
                                        href={templatesIndex()}
                                        className="text-muted-foreground transition-colors hover:text-foreground"
                                    >
                                        {t('nav.templates')}
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href={booksIndex()}
                                        className="text-muted-foreground transition-colors hover:text-foreground"
                                    >
                                        {t('nav.myBooks')}
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href={charactersIndex()}
                                        className="text-muted-foreground transition-colors hover:text-foreground"
                                    >
                                        {t('nav.library')}
                                    </Link>
                                </li>
                            </ul>
                        </div>

                        <div>
                            <h4 className="font-display text-sm font-bold tracking-wider text-foreground uppercase">
                                {t('footer.account')}
                            </h4>
                            <ul className="mt-4 space-y-2.5 text-sm">
                                <li>
                                    <Link
                                        href={account()}
                                        className="text-muted-foreground transition-colors hover:text-foreground"
                                    >
                                        {t('nav.account')}
                                    </Link>
                                </li>
                                <li>
                                    <Link
                                        href={login()}
                                        className="text-muted-foreground transition-colors hover:text-foreground"
                                    >
                                        {t('auth.login')}
                                    </Link>
                                </li>
                                {registrationOpen && (
                                    <li>
                                        <Link
                                            href={register()}
                                            className="text-muted-foreground transition-colors hover:text-foreground"
                                        >
                                            {t('auth.register')}
                                        </Link>
                                    </li>
                                )}
                            </ul>
                        </div>
                    </div>

                    <div className="mt-12 flex flex-col items-center justify-between gap-3 border-t border-border/50 pt-6 sm:flex-row">
                        <span className="inline-flex items-center gap-2 text-sm text-muted-foreground">
                            <BrandGlyph size={20} />
                            {t('footer.rights')}
                        </span>
                        <span className="inline-flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Sparkles className="h-3.5 w-3.5 text-gold" />
                            {t('footer.madeFor')}
                        </span>
                    </div>
                </div>
            </footer>
        </div>
    );
}
