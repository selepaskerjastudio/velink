import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem, type SystemdService } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { EllipsisIcon, TriangleAlertIcon } from 'lucide-react';

type ServiceAction = 'start' | 'stop' | 'restart' | 'reload';

interface ServiceIconConfig {
    bg: string;
    letter: string;
}

function serviceIcon(name: string): ServiceIconConfig {
    const n = name.toLowerCase();
    if (n.startsWith('nginx')) return { bg: 'bg-green-600', letter: 'N' };
    if (n.startsWith('redis')) return { bg: 'bg-red-600', letter: 'R' };
    if (n.startsWith('mariadb') || n.startsWith('mysql')) return { bg: 'bg-orange-500', letter: 'M' };
    if (n.startsWith('postgresql') || n.startsWith('postgres')) return { bg: 'bg-blue-600', letter: 'P' };
    if (n.startsWith('supervisor')) return { bg: 'bg-indigo-600', letter: 'S' };
    if (n.startsWith('php')) return { bg: 'bg-purple-600', letter: 'P' };
    if (n.startsWith('mongod')) return { bg: 'bg-green-700', letter: 'M' };
    if (n.startsWith('apache') || n.startsWith('httpd')) return { bg: 'bg-red-700', letter: 'A' };
    return { bg: 'bg-slate-500', letter: (name[0] ?? '?').toUpperCase() };
}

function statusBadge(status: string) {
    switch (status) {
        case 'active':
            return <Badge className="bg-green-100 text-green-800 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400">Running</Badge>;
        case 'inactive':
            return <Badge variant="secondary">Stopped</Badge>;
        case 'failed':
            return <Badge variant="destructive">Failed</Badge>;
        default:
            return <Badge variant="outline">Unknown</Badge>;
    }
}

function formatMemory(bytes: number): string {
    if (bytes >= 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024 / 1024).toFixed(1)} GB`;
    if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(0)} MB`;
    return `${(bytes / 1024).toFixed(0)} KB`;
}

function ServiceRow({ service }: { service: SystemdService }) {
    const controlForm = useForm<{ action: ServiceAction }>({ action: 'restart' });

    const dispatch = (action: ServiceAction) => {
        controlForm.transform(() => ({ action }));
        controlForm.post(route('services.control', service.id), { preserveScroll: true });
    };

    const icon = serviceIcon(service.name);
    const label = service.config?.label ?? service.name;

    return (
        <tr className="border-b last:border-0 hover:bg-muted/30 transition-colors">
            <td className="py-3 pl-4 pr-2">
                <div className="flex items-center gap-3">
                    <div className={`flex h-8 w-8 shrink-0 items-center justify-center rounded text-xs font-bold text-white ${icon.bg}`}>
                        {icon.letter}
                    </div>
                    <div className="flex flex-col">
                        <span className="text-sm font-medium">{label}</span>
                        <span className="text-muted-foreground text-xs">{service.name}</span>
                    </div>
                </div>
            </td>
            <td className="px-4 py-3 text-sm text-muted-foreground">
                {service.cpu_percent != null ? `${service.cpu_percent.toFixed(4)}%` : '—'}
            </td>
            <td className="px-4 py-3 text-sm text-muted-foreground">
                {service.memory_usage != null ? formatMemory(service.memory_usage) : '—'}
            </td>
            <td className="px-4 py-3">
                {statusBadge(service.status)}
            </td>
            <td className="py-3 pl-2 pr-4 text-right">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-8 w-8" disabled={controlForm.processing}>
                            <EllipsisIcon className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => dispatch('start')}>Start</DropdownMenuItem>
                        <DropdownMenuItem onClick={() => dispatch('restart')}>Restart</DropdownMenuItem>
                        <DropdownMenuItem onClick={() => dispatch('reload')}>Reload</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={() => dispatch('stop')} className="text-destructive focus:text-destructive">
                            Stop
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </td>
        </tr>
    );
}

export default function ServerServices({
    server,
    services,
}: {
    server: { id: string; name: string; status: string; public_ip: string | null };
    services: SystemdService[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Services', href: `/servers/${server.id}/services` },
    ];

    const running = services.filter((s) => s.status === 'active').length;

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`${server.name} — Services`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div>
                    <h1 className="text-xl font-semibold">Services</h1>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Enable or disable services running on your server. Depending on usage, NGINX, PHP-FPM, and Supervisord will start or stop automatically.
                    </p>
                </div>

                {server.status !== 'online' && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon className="h-4 w-4" />
                        <AlertTitle>Server offline</AlertTitle>
                        <AlertDescription>
                            The agent on this server is not connected. Service actions will be queued but won't run until the server reconnects.
                        </AlertDescription>
                    </Alert>
                )}

                <Card>
                    <CardContent className="p-0">
                        {services.length === 0 ? (
                            <p className="text-muted-foreground px-4 py-8 text-center text-sm">
                                No services registered. Provision your server to auto-register services.
                            </p>
                        ) : (
                            <>
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-b bg-muted/30">
                                            <th className="py-2.5 pl-4 pr-2 text-left text-xs font-medium text-muted-foreground">Service</th>
                                            <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Processor Usage</th>
                                            <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Memory Usage</th>
                                            <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Status</th>
                                            <th className="py-2.5 pl-2 pr-4" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {services.map((service) => (
                                            <ServiceRow key={service.id} service={service} />
                                        ))}
                                    </tbody>
                                </table>
                                <p className="text-muted-foreground border-t px-4 py-3 text-xs">
                                    Showing {running} running of {services.length} service{services.length !== 1 ? 's' : ''}
                                </p>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </ServerLayout>
    );
}
