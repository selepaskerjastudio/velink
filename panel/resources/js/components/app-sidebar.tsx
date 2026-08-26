import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useIsAdmin } from '@/hooks/use-permissions';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { Globe, KeyRound, Server, ShieldCheck, UsersIcon } from 'lucide-react';
import AppLogo from './app-logo';

const mainNavItems: NavItem[] = [
    {
        title: 'Servers',
        url: '/servers',
        icon: Server,
    },
    {
        title: 'Web Applications',
        url: '/servers',
        icon: Globe,
    },
    {
        title: 'Git Credentials',
        url: '/git-credentials',
        icon: KeyRound,
    },
    {
        title: 'Audit Log',
        url: '/audit-logs',
        icon: ShieldCheck,
    },
    {
        title: 'Users',
        url: '/settings/users',
        icon: UsersIcon,
        adminOnly: true,
    },
];

export function AppSidebar() {
    const isAdmin = useIsAdmin();
    const items = mainNavItems.filter((item) => !item.adminOnly || isAdmin);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
