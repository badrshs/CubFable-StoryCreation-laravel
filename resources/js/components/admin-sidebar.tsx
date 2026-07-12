import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
    BookMarked,
    FlaskConical,
    LayoutGrid,
    LibraryBig,
    ScrollText,
    Settings2,
    ShieldAlert,
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
import type { NavItem } from '@/types';

// Navigation for the owner's admin area. Plain string hrefs: the admin routes
// are stable and Wayfinder bindings arrive with each controller.
export function AdminSidebar() {
    const t = useT();

    const mainNavItems: NavItem[] = [
        { title: t('admin.nav.dashboard'), href: '/admin', icon: LayoutGrid },
        { title: t('admin.nav.books'), href: '/admin/books', icon: BookMarked },
        {
            title: t('admin.nav.moderation'),
            href: '/admin/moderation',
            icon: ShieldAlert,
        },
        {
            title: t('admin.nav.templates'),
            href: '/admin/templates',
            icon: LibraryBig,
        },
        {
            title: t('admin.nav.settings'),
            href: '/admin/settings',
            icon: Settings2,
        },
        {
            title: t('admin.nav.playground'),
            href: '/admin/playground',
            icon: FlaskConical,
        },
        {
            title: t('admin.nav.logs'),
            href: '/admin/logs',
            icon: ScrollText,
        },
    ];

    const footerNavItems: NavItem[] = [
        { title: t('admin.nav.backToSite'), href: '/', icon: ArrowLeft },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/admin" prefetch>
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
