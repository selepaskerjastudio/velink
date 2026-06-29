import { useEffect, useRef, useState } from 'react';
import echo from '@/echo';
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
import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import ServerLayout from '@/layouts/server-layout';
import { type AgentJob, type BreadcrumbItem, type SystemdService } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    ActivityIcon,
    CheckCircle2Icon,
    CircleDashedIcon,
    EllipsisIcon,
    LoaderIcon,
    PackageIcon,
    TriangleAlertIcon,
    XCircleIcon,
} from 'lucide-react';

type ControlAction = 'start' | 'stop' | 'restart' | 'reload';
type RowAction = ControlAction | 'install' | 'reinstall';

interface AgentJobUpdatedEvent {
    uuid: string;
    type: string;
    label: string | null;
    status: AgentJob['status'];
    exit_code: number | null;
    output: string | null;
}

const ACTION_LABELS: Record<RowAction, string> = {
    install: 'Install',
    reinstall: 'Reinstall',
    start: 'Start',
    restart: 'Restart',
    reload: 'Reload',
    stop: 'Stop',
};

const JOB_DONE: AgentJob['status'][] = ['succeeded', 'failed', 'timeout'];

function serviceIcon(name: string): { bg: string; letter: string } {
    const n = name.toLowerCase();
    if (n.startsWith('nginx')) return { bg: 'bg-green-600', letter: 'N' };
    if (n.startsWith('redis')) return { bg: 'bg-red-600', letter: 'R' };
    if (n.startsWith('mariadb') || n.startsWith('mysql')) return { bg: 'bg-orange-500', letter: 'M' };
    if (n.startsWith('postgresql') || n.startsWith('postgres')) return { bg: 'bg-blue-600', letter: 'P' };
    if (n.startsWith('supervisor')) return { bg: 'bg-indigo-600', letter: 'S' };
    if (n.startsWith('php')) return { bg: 'bg-purple-600', letter: 'P' };
    if (n.startsWith('mongod')) return { bg: 'bg-green-700', letter: 'M' };
    if (n.startsWith('certbot')) return { bg: 'bg-teal-600', letter: 'C' };
    if (n.startsWith('composer')) return { bg: 'bg-amber-700', letter: 'C' };
    if (n.startsWith('node')) return { bg: 'bg-lime-600', letter: 'N' };
    if (n.startsWith('apache') || n.startsWith('httpd')) return { bg: 'bg-red-700', letter: 'A' };
    return { bg: 'bg-slate-500', letter: (name[0] ?? '?').toUpperCase() };
}

function statusBadge(status: string, isTool: boolean) {
    const running = status === 'running' || status === 'active';
    if (isTool && running) {
        return <Badge className="bg-green-100 text-green-800 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400">Installed</Badge>;
    }
    switch (status) {
        case 'running':
        case 'active': // legacy
            return <Badge className="bg-green-100 text-green-800 hover:bg-green-100 dark:bg-green-900/30 dark:text-green-400">Running</Badge>;
        case 'installing':
            return (
                <Badge className="bg-blue-100 text-blue-800 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400">
                    <LoaderIcon className="mr-1 h-3 w-3 animate-spin" />
                    Installing
                </Badge>
            );
        case 'waiting':
            return <Badge className="bg-amber-100 text-amber-800 hover:bg-amber-100 dark:bg-amber-900/30 dark:text-amber-400">Waiting</Badge>;
        case 'restarting':
            return (
                <Badge className="bg-blue-100 text-blue-800 hover:bg-blue-100 dark:bg-blue-900/30 dark:text-blue-400">
                    <LoaderIcon className="mr-1 h-3 w-3 animate-spin" />
                    Restarting
                </Badge>
            );
        case 'stopped':
        case 'inactive': // legacy
            return <Badge variant="secondary">Stopped</Badge>;
        case 'not_installed':
        case 'failed': // legacy
            return <Badge variant="destructive">Not Installed</Badge>;
        default:
            return <Badge variant="outline">Unknown</Badge>;
    }
}

function formatMemory(bytes: number): string {
    if (bytes >= 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024 / 1024).toFixed(1)} GB`;
    if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(0)} MB`;
    return `${(bytes / 1024).toFixed(0)} KB`;
}

function jobStatusIcon(status: AgentJob['status']) {
    switch (status) {
        case 'succeeded':
            return <CheckCircle2Icon className="h-3.5 w-3.5 text-green-500" />;
        case 'failed':
        case 'timeout':
            return <XCircleIcon className="h-3.5 w-3.5 text-red-500" />;
        case 'running':
            return <LoaderIcon className="h-3.5 w-3.5 animate-spin text-blue-500" />;
        default:
            return <CircleDashedIcon className="h-3.5 w-3.5 text-muted-foreground" />;
    }
}

// Only services whose systemd unit defines a graceful reload (ExecReload).
function supportsReload(name: string): boolean {
    const n = name.toLowerCase();
    return (
        n.startsWith('nginx') ||
        n.startsWith('php') ||
        n.startsWith('apache') ||
        n.startsWith('httpd') ||
        n.startsWith('mariadb') ||
        n.startsWith('mysql') ||
        n.startsWith('postgres')
    );
}

// Actions appropriate for the row's status and kind. Tools (node/composer/
// certbot) only install/reinstall; services get runtime controls once running.
function rowActions(service: SystemdService): RowAction[] {
    const s = service.status;
    const isTool = service.type === 'tool';

    if (s === 'waiting' || s === 'installing' || s === 'restarting') return [];
    if (s === 'not_installed' || s === 'failed') return ['install'];

    const running = s === 'running' || s === 'active';
    const stopped = s === 'stopped' || s === 'inactive';

    if (isTool) return ['reinstall'];
    if (stopped) return ['start', 'reinstall'];
    if (running) return [...(supportsReload(service.name) ? (['reload'] as RowAction[]) : []), 'restart', 'reinstall', 'stop'];
    return ['start', 'restart', 'reinstall', 'stop']; // unknown / legacy
}

function ServiceRow({ service }: { service: SystemdService }) {
    const controlForm = useForm<{ action: ControlAction }>({ action: 'restart' });
    const installForm = useForm({});
    const processing = controlForm.processing || installForm.processing;

    const run = (action: RowAction) => {
        if (action === 'install' || action === 'reinstall') {
            installForm.post(route('services.install', service.id), { preserveScroll: true });
            return;
        }
        controlForm.transform(() => ({ action }));
        controlForm.post(route('services.control', service.id), { preserveScroll: true });
    };

    const icon = serviceIcon(service.name);
    const label = service.config?.label ?? service.name;
    const actions = rowActions(service);
    const primary = actions.filter((a) => a !== 'stop');

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
                {service.cpu_percent != null ? `${Number(service.cpu_percent).toFixed(2)}%` : '—'}
            </td>
            <td className="px-4 py-3 text-sm text-muted-foreground">
                {service.memory_usage != null ? formatMemory(service.memory_usage) : '—'}
            </td>
            <td className="px-4 py-3">{statusBadge(service.status, service.type === 'tool')}</td>
            <td className="py-3 pl-2 pr-4 text-right">
                {actions.length > 0 && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" size="icon" className="h-8 w-8" disabled={processing}>
                                <EllipsisIcon className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            {primary.map((a) => (
                                <DropdownMenuItem key={a} onClick={() => run(a)}>
                                    {ACTION_LABELS[a]}
                                </DropdownMenuItem>
                            ))}
                            {actions.includes('stop') && (
                                <>
                                    <DropdownMenuSeparator />
                                    <DropdownMenuItem onClick={() => run('stop')} className="text-destructive focus:text-destructive">
                                        Stop
                                    </DropdownMenuItem>
                                </>
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </td>
        </tr>
    );
}

function ActivitiesSheet({ jobs }: { jobs: AgentJob[] }) {
    const active = jobs.filter((j) => !JOB_DONE.includes(j.status)).length;

    return (
        <Sheet>
            <SheetTrigger asChild>
                <Button variant="outline" size="sm" className="relative">
                    <ActivityIcon className="mr-1.5 h-4 w-4" />
                    Activities
                    {active > 0 && (
                        <span className="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-blue-600 px-1 text-[10px] font-semibold text-white">
                            {active}
                        </span>
                    )}
                </Button>
            </SheetTrigger>
            <SheetContent className="flex w-full flex-col sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>Activities</SheetTitle>
                    <SheetDescription>Recent provisioning and service jobs on this server.</SheetDescription>
                </SheetHeader>
                <div className="mt-2 flex-1 divide-y overflow-y-auto">
                    {jobs.length === 0 ? (
                        <p className="px-1 py-6 text-center text-sm text-muted-foreground">No recent activity.</p>
                    ) : (
                        jobs.map((job) => (
                            <div key={job.uuid} className="flex items-center gap-3 px-1 py-2.5">
                                {jobStatusIcon(job.status)}
                                <span className="flex-1 truncate text-sm">{job.label ?? job.type}</span>
                                <Badge
                                    variant={
                                        job.status === 'succeeded'
                                            ? 'outline'
                                            : job.status === 'failed' || job.status === 'timeout'
                                              ? 'destructive'
                                              : 'secondary'
                                    }
                                    className="text-xs"
                                >
                                    {job.status}
                                </Badge>
                            </div>
                        ))
                    )}
                </div>
            </SheetContent>
        </Sheet>
    );
}

export default function ServerServices({
    server,
    services,
    jobs,
}: {
    server: { id: string; name: string; status: string; public_ip: string | null };
    services: SystemdService[];
    jobs: AgentJob[];
}) {
    const [liveJobs, setLiveJobs] = useState<AgentJob[]>(jobs);
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => setLiveJobs(jobs), [jobs]);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);

        channel.listen('.agent-job.updated', (event: AgentJobUpdatedEvent) => {
            setLiveJobs((current) => {
                const index = current.findIndex((j) => j.uuid === event.uuid);
                if (index === -1) return current;
                const next = [...current];
                next[index] = { ...next[index], ...event };
                return next;
            });

            // Service statuses change as provisioning jobs progress; refresh the
            // services prop (debounced so a burst of output events coalesces).
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
            reloadTimer.current = setTimeout(() => {
                router.reload({ only: ['services'] });
            }, 800);
        });

        return () => {
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Services', href: `/servers/${server.id}/services` },
    ];

    const running = services.filter((s) => s.status === 'running' || s.status === 'active').length;

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`${server.name} — Services`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Services</h1>
                        <p className="text-muted-foreground mt-1 text-sm">Install and manage services running on your server.</p>
                    </div>
                    <ActivitiesSheet jobs={liveJobs} />
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
                            <div className="flex flex-col items-center gap-3 px-4 py-12 text-center">
                                <PackageIcon className="h-8 w-8 text-muted-foreground/50" />
                                <p className="text-sm text-muted-foreground">
                                    No services yet. They appear automatically once the agent connects and provisioning starts.
                                </p>
                            </div>
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
