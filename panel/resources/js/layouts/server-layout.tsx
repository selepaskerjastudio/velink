import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { type BreadcrumbItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { Activity, ChevronLeft, Clock, Cpu, Database, Globe, LayoutGrid, Layers, ScrollText, Settings } from 'lucide-react';

interface ServerLayoutProps {
    children: React.ReactNode;
    breadcrumbs?: BreadcrumbItem[];
    server: { id: string; name: string; public_ip: string | null; status: string };
}

function ServerSidebar({ server }: { server: ServerLayoutProps['server'] }) {
    const page = usePage();

    const dashboardUrl = `/servers/${server.id}`;

    const isActive = (url: string, exact = false) => {
        if (exact) return page.url === url;
        return page.url.startsWith(url);
    };

    const mainNavItems = [
        { title: 'Dashboard', url: dashboardUrl, icon: LayoutGrid, exact: true },
        { title: 'Monitoring', url: `/servers/${server.id}/monitoring`, icon: Activity, exact: false },
        { title: 'Web Applications', url: `/servers/${server.id}/applications`, icon: Globe, exact: false },
        { title: 'Databases', url: `/servers/${server.id}/databases`, icon: Database, exact: false },
        { title: 'Services', url: `/servers/${server.id}/services`, icon: Cpu, exact: false },
    ];

    const utilityNavItems = [
        { title: 'Cron Jobs', url: `/servers/${server.id}/cron`, icon: Clock, exact: false },
        { title: 'Workers', url: `/servers/${server.id}/workers`, icon: Layers, exact: false },
    ];

    const moreNavItems = [
        { title: 'Activity Log', url: `/servers/${server.id}/activity`, icon: ScrollText, exact: false },
        { title: 'Settings', url: `/servers/${server.id}/settings`, icon: Settings, exact: false },
    ];

    const isPending = server.status === 'pending';

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <div className="px-3 py-2">
                    <Link
                        href="/servers"
                        className="text-sidebar-foreground/60 hover:text-sidebar-foreground mb-3 flex items-center gap-1 text-xs transition-colors"
                    >
                        <ChevronLeft className="h-3 w-3" />
                        <span>Back to Servers</span>
                    </Link>
                    <div className="rounded-md border px-3 py-2">
                        <p className="truncate text-sm font-semibold">{server.name}</p>
                        {server.public_ip && (
                            <p className="text-muted-foreground truncate font-mono text-xs">{server.public_ip}</p>
                        )}
                        <p className="text-muted-foreground mt-0.5 text-xs capitalize">{server.status}</p>
                    </div>
                </div>
            </SidebarHeader>

            <SidebarContent>
                <SidebarGroup className="px-2 py-0">
                    <SidebarMenu>
                        {(isPending ? mainNavItems.slice(0, 1) : mainNavItems).map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton asChild isActive={isActive(item.url, item.exact)}>
                                    <Link href={item.url} prefetch>
                                        <item.icon />
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </SidebarGroup>

                {!isPending && (
                    <>
                        <SidebarGroup className="px-2 py-0">
                            <SidebarGroupLabel>Utility</SidebarGroupLabel>
                            <SidebarMenu>
                                {utilityNavItems.map((item) => (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton asChild isActive={isActive(item.url, item.exact)}>
                                            <Link href={item.url} prefetch>
                                                <item.icon />
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroup>

                        <SidebarGroup className="px-2 py-0">
                            <SidebarGroupLabel>More</SidebarGroupLabel>
                            <SidebarMenu>
                                {moreNavItems.map((item) => (
                                    <SidebarMenuItem key={item.title}>
                                        <SidebarMenuButton asChild isActive={isActive(item.url, item.exact)}>
                                            <Link href={item.url} prefetch>
                                                <item.icon />
                                                <span>{item.title}</span>
                                            </Link>
                                        </SidebarMenuButton>
                                    </SidebarMenuItem>
                                ))}
                            </SidebarMenu>
                        </SidebarGroup>
                    </>
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}

export default function ServerLayout({ children, breadcrumbs = [], server }: ServerLayoutProps) {
    return (
        <AppShell variant="sidebar">
            <ServerSidebar server={server} />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
