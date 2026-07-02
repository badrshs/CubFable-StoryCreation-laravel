import type { InertiaLinkProps } from '@inertiajs/react';
import { Link, router, usePage } from '@inertiajs/react';
import { motion, useReducedMotion } from 'framer-motion';
import {
    BookMarked,
    Library as LibraryIcon,
    LogOut,
    Moon,
    Sun,
    Globe,
    Sparkles,
    ChevronRight,
    BookHeart,
    ShieldCheck,
} from 'lucide-react';
import Starfield from '@/components/cubfable/starfield';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { useAppearance } from '@/hooks/use-appearance';
import { useI18n, useT, LANGUAGES } from '@/i18n';
import { staggerContainer, fadeUp } from '@/lib/motion';
import { logout } from '@/routes';
import books from '@/routes/books';
import characters from '@/routes/characters';
import profile from '@/routes/profile';

// The signed-in email's leading glyph, used for the keepsake avatar.
function initialOf(email: string | undefined): string {
    const c = email?.trim().charAt(0);

    return c ? c.toUpperCase() : '?';
}

// A calm day/night appearance control rendered as two lamplit segments.
// The shared appearance store resolves via useSyncExternalStore, so the
// active state never flashes on hydration.
function AppearanceControl() {
    const t = useT();
    const { resolvedAppearance, updateAppearance } = useAppearance();

    const isDark = resolvedAppearance === 'dark';
    const options: Array<{
        value: 'light' | 'dark';
        label: string;
        icon: typeof Sun;
    }> = [
        { value: 'light', label: t('account.appearanceDay'), icon: Sun },
        { value: 'dark', label: t('account.appearanceNight'), icon: Moon },
    ];

    return (
        <div
            role="radiogroup"
            aria-label={t('account.appearanceLabel')}
            className="inline-flex w-full items-center gap-1 rounded-full border border-card-border bg-muted/60 p-1 sm:w-auto"
        >
            {options.map((opt) => {
                const active = opt.value === 'dark' ? isDark : !isDark;
                const Icon = opt.icon;

                return (
                    <button
                        key={opt.value}
                        type="button"
                        role="radio"
                        aria-checked={active}
                        onClick={() => updateAppearance(opt.value)}
                        className={`inline-flex flex-1 items-center justify-center gap-2 rounded-full px-4 py-2 font-display text-sm font-semibold transition-all duration-300 focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-none sm:flex-none ${
                            active
                                ? 'glow-gold bg-card text-foreground shadow-soft'
                                : 'text-muted-foreground hover:text-foreground'
                        }`}
                    >
                        <Icon
                            className={`h-4 w-4 ${active ? 'text-gold' : ''}`}
                        />
                        {opt.label}
                    </button>
                );
            })}
        </div>
    );
}

// One row in the "quick links" list: a labelled destination with an icon and
// an end-aligned chevron that respects RTL via logical properties.
function QuickLink({
    href,
    icon: Icon,
    title,
    description,
}: {
    href: InertiaLinkProps['href'];
    icon: typeof BookMarked;
    title: string;
    description: string;
}) {
    return (
        <Link
            href={href}
            className="group flex items-center gap-4 rounded-2xl border border-card-border bg-card/60 p-4 transition-all duration-300 hover:-translate-y-0.5 hover:border-gold/40 hover:shadow-lift focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
        >
            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-primary/12 text-primary transition-colors group-hover:bg-primary/20">
                <Icon className="h-5 w-5" />
            </span>
            <span className="min-w-0 flex-1">
                <span className="block font-serif text-lg leading-tight font-semibold text-foreground">
                    {title}
                </span>
                <span className="block truncate text-sm text-muted-foreground">
                    {description}
                </span>
            </span>
            <ChevronRight className="h-5 w-5 shrink-0 text-muted-foreground transition-transform duration-300 group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5" />
        </Link>
    );
}

export default function Account({ storyCount }: { storyCount: number }) {
    const t = useT();
    const { lang, setLang, tc } = useI18n();
    const { auth } = usePage().props;
    const user = auth.user;
    const reduceMotion = useReducedMotion();

    function handleSignOut() {
        router.post(logout.url());
    }

    return (
        <div className="bg-grain relative min-h-[100dvh] overflow-hidden bg-background">
            {/* Twilight sky wash + gentle stars, matching the keepsake mood */}
            <div
                aria-hidden
                className="pointer-events-none absolute inset-x-0 top-0 h-[32rem] bg-gradient-to-b from-primary/12 via-background/0 to-transparent dark:from-primary/25"
            />
            <Starfield count={reduceMotion ? 16 : 34} className="opacity-70" />

            <div className="relative container mx-auto max-w-3xl px-4 py-16">
                <motion.div
                    variants={staggerContainer(0.09)}
                    initial={reduceMotion ? false : 'hidden'}
                    animate="show"
                    className="flex flex-col gap-8"
                >
                    {/* Page heading */}
                    <motion.header variants={fadeUp}>
                        <span className="mb-4 inline-flex items-center gap-2 rounded-full border border-gold/30 bg-gold/10 px-3 py-1.5 font-display text-sm font-semibold text-gold-foreground dark:text-gold">
                            <Sparkles className="h-4 w-4 text-gold" />
                            {t('account.eyebrow')}
                        </span>
                        <h1 className="font-serif text-4xl leading-tight font-bold text-foreground md:text-5xl">
                            {t('account.heading')}
                        </h1>
                        <p className="mt-2 text-lg text-muted-foreground">
                            {t('account.subheading')}
                        </p>
                    </motion.header>

                    {/* Profile card */}
                    <motion.div variants={fadeUp}>
                        <Card className="relative overflow-hidden shadow-lift">
                            <div
                                aria-hidden
                                className="pointer-events-none absolute -end-16 -top-16 h-48 w-48 rounded-full bg-primary/10 blur-3xl"
                            />
                            <CardContent className="relative flex flex-col items-center gap-5 p-8 text-center sm:flex-row sm:text-start">
                                <Avatar className="glow-gold h-20 w-20 shrink-0 ring-2 ring-gold/40 ring-offset-2 ring-offset-card">
                                    <AvatarFallback className="bg-primary/15 font-display text-3xl font-bold text-primary">
                                        {initialOf(user?.email)}
                                    </AvatarFallback>
                                </Avatar>

                                <div className="min-w-0 flex-1">
                                    <span className="inline-flex items-center gap-1.5 rounded-full bg-secondary px-2.5 py-1 font-display text-xs font-semibold text-secondary-foreground">
                                        <ShieldCheck className="h-3.5 w-3.5 text-gold" />
                                        {t('account.memberBadge')}
                                    </span>
                                    <p
                                        className="mt-2 truncate font-serif text-2xl font-semibold text-foreground"
                                        title={user?.email ?? undefined}
                                    >
                                        {user?.email ?? t('account.signedOut')}
                                    </p>
                                    <p className="mt-1 inline-flex items-center gap-1.5 text-sm text-muted-foreground">
                                        <BookHeart className="h-4 w-4 text-rose" />
                                        {t('account.storiesCreated', {
                                            count: storyCount,
                                            plural: storyCount !== 1 ? 's' : '',
                                        })}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Preferences card */}
                    <motion.div variants={fadeUp}>
                        <Card className="shadow-soft">
                            <CardHeader>
                                <CardTitle className="font-serif text-2xl font-bold text-foreground">
                                    {t('account.preferencesTitle')}
                                </CardTitle>
                                <CardDescription>
                                    {t('account.preferencesSubtitle')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-6">
                                {/* UI language */}
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <label
                                            htmlFor="account-language"
                                            className="inline-flex items-center gap-2 font-display text-sm font-semibold text-foreground"
                                        >
                                            <Globe className="h-4 w-4 text-primary" />
                                            {t('account.languageLabel')}
                                        </label>
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            {t('account.languageHint')}
                                        </p>
                                    </div>
                                    <Select
                                        value={lang}
                                        onValueChange={setLang}
                                    >
                                        <SelectTrigger
                                            id="account-language"
                                            className="h-11 w-full rounded-full border-card-border sm:w-56"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {LANGUAGES.map((l) => (
                                                <SelectItem
                                                    key={l.code}
                                                    value={l.code}
                                                >
                                                    {l.native}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                <Separator className="bg-card-border" />

                                {/* Appearance */}
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                                    <div className="min-w-0">
                                        <span className="inline-flex items-center gap-2 font-display text-sm font-semibold text-foreground">
                                            <Moon className="h-4 w-4 text-primary" />
                                            {t('account.appearanceLabel')}
                                        </span>
                                        <p className="mt-0.5 text-sm text-muted-foreground">
                                            {t('account.appearanceHint')}
                                        </p>
                                    </div>
                                    <AppearanceControl />
                                </div>
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Quick links */}
                    <motion.div variants={fadeUp}>
                        <Card className="shadow-soft">
                            <CardHeader>
                                <CardTitle className="font-serif text-2xl font-bold text-foreground">
                                    {t('account.quickLinksTitle')}
                                </CardTitle>
                                <CardDescription>
                                    {t('account.quickLinksSubtitle')}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="grid gap-3 sm:grid-cols-2">
                                <QuickLink
                                    href={characters.index()}
                                    icon={LibraryIcon}
                                    title={t('account.linkLibraryTitle')}
                                    description={t('account.linkLibraryDesc')}
                                />
                                <QuickLink
                                    href={books.index()}
                                    icon={BookMarked}
                                    title={t('account.linkBooksTitle')}
                                    description={t('account.linkBooksDesc')}
                                />
                                <QuickLink
                                    href={profile.edit()}
                                    icon={ShieldCheck}
                                    title={tc(
                                        'account.security',
                                        'Security and profile',
                                    )}
                                    description={tc(
                                        'account.securityDesc',
                                        'Manage your password, passkeys, and profile details.',
                                    )}
                                />
                            </CardContent>
                        </Card>
                    </motion.div>

                    {/* Sign out - clearly separated, destructive framing */}
                    <motion.div variants={fadeUp}>
                        <Separator className="mb-6 bg-card-border" />
                        <div className="flex flex-col items-start gap-4 rounded-2xl border border-destructive/25 bg-destructive/5 p-6 sm:flex-row sm:items-center sm:justify-between">
                            <div className="min-w-0">
                                <h2 className="font-serif text-xl font-semibold text-foreground">
                                    {t('account.signOutTitle')}
                                </h2>
                                <p className="mt-0.5 text-sm text-muted-foreground">
                                    {t('account.signOutHint')}
                                </p>
                            </div>
                            <Button
                                variant="destructive"
                                size="lg"
                                className="w-full gap-2 rounded-full sm:w-auto"
                                onClick={handleSignOut}
                            >
                                <LogOut className="h-4 w-4" />
                                {t('account.signOut')}
                            </Button>
                        </div>
                    </motion.div>
                </motion.div>
            </div>
        </div>
    );
}
