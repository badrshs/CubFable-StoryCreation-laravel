import { Link } from '@inertiajs/react';
import {
    BookMarked,
    BookOpen,
    FolderGit2,
    LayoutGrid,
    Library as LibraryIcon,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useT } from '@/i18n';
import { index as booksIndex } from '@/routes/books';
import { index as charactersIndex } from '@/routes/characters';
import { index as templatesIndex } from '@/routes/templates';
import type { NavItem } from '@/types';

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const t = useT();

    const mainNavItems: NavItem[] = [
        {
            title: t('nav.templates'),
            href: templatesIndex(),
            icon: LayoutGrid,
        },
        {
            title: t('nav.myBooks'),
            href: booksIndex(),
            icon: BookMarked,
        },
        {
            title: t('nav.library'),
            href: charactersIndex(),
            icon: LibraryIcon,
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={booksIndex()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
