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
import { type BreadcrumbItem, type SharedData } from '@/types';
import echo from '@/echo';
import { Link, router, usePage } from '@inertiajs/react';
import { Activity, ChevronLeft, Clock, Cpu, Database, Globe, KeyRound, LayoutGrid, Layers, Loader2, ScrollText, Settings } from 'lucide-react';
import { useEffect, useState } from 'react';

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
        { title: 'SSH Keys', url: `/servers/${server.id}/ssh-keys`, icon: KeyRound, exact: false },
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

const TERMINAL = ['succeeded', 'failed', 'timeout'];

export default function ServerLayout({ children, breadcrumbs = [], server }: ServerLayoutProps) {
    const { server_provisioning } = usePage<SharedData>().props;
    const [isProvisioning, setIsProvisioning] = useState(server_provisioning);

    useEffect(() => setIsProvisioning(server_provisioning), [server_provisioning]);

    useEffect(() => {
        if (!isProvisioning) return;

        const channel = echo.private(`server.${server.id}`);

        channel.listen('.agent-job.updated', (event: { status: string }) => {
            if (TERMINAL.includes(event.status)) {
                router.reload();
            }
        });

        return () => {
            channel.stopListening('.agent-job.updated');
        };
    }, [server.id, isProvisioning]);

    return (
        <AppShell variant="sidebar">
            <ServerSidebar server={server} />
            <AppContent variant="sidebar">
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {isProvisioning && (
                    <div className="flex items-center gap-5 border-b border-amber-200 bg-gradient-to-r from-amber-50 to-orange-50 px-6 py-4 dark:border-amber-800/40 dark:from-amber-950/40 dark:to-orange-950/30">
                        <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-amber-100 ring-4 ring-amber-200/60 dark:bg-amber-900/50 dark:ring-amber-700/40">
                            <Loader2 className="h-6 w-6 animate-spin text-amber-600 dark:text-amber-400" />
                        </div>
                        <div>
                            <p className="text-base font-semibold text-amber-900 dark:text-amber-200">Server sedang disetup</p>
                            <p className="mt-0.5 text-sm text-amber-700/80 dark:text-amber-400/80">
                                Layanan masih diinstall di background. Halaman akan otomatis terupdate setelah selesai.
                            </p>
                        </div>
                    </div>
                )}
                {children}
            </AppContent>
        </AppShell>
    );
}
