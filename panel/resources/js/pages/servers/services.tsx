import { useState } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Label } from '@/components/ui/label';
import ServerLayout from '@/layouts/server-layout';
import { type AgentJob, type BreadcrumbItem, type SystemdService } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2Icon, CircleDashedIcon, EllipsisIcon, LoaderIcon, PackagePlusIcon, TriangleAlertIcon, XCircleIcon } from 'lucide-react';

type ServiceAction = 'start' | 'stop' | 'restart' | 'reload';

const COMPONENTS = [
    { key: 'nginx', label: 'NGINX', group: 'Web' },
    { key: 'certbot', label: 'Certbot (SSL)', group: 'Web' },
    { key: 'php', label: 'PHP-FPM', group: 'PHP' },
    { key: 'composer', label: 'Composer', group: 'PHP' },
    { key: 'redis', label: 'Redis', group: 'Database' },
    { key: 'mariadb', label: 'MariaDB', group: 'Database' },
    { key: 'postgresql', label: 'PostgreSQL', group: 'Database' },
    { key: 'mongodb', label: 'MongoDB', group: 'Database' },
    { key: 'supervisor', label: 'Supervisord', group: 'Runtime' },
    { key: 'node', label: 'Node.js', group: 'Runtime' },
] as const;

const PHP_VERSIONS = ['8.1', '8.2', '8.3', '8.4'] as const;

const COMPONENT_GROUPS = ['Web', 'PHP', 'Database', 'Runtime'] as const;

function serviceIcon(name: string): { bg: string; letter: string } {
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

function ProvisionDialog({ serverId }: { serverId: string }) {
    const [open, setOpen] = useState(false);
    const [selectedComponents, setSelectedComponents] = useState<string[]>(['nginx', 'php', 'redis', 'supervisor']);
    const [selectedVersions, setSelectedVersions] = useState<string[]>(['8.3']);

    const form = useForm<{ components: string[]; php_versions: string[] }>({
        components: [],
        php_versions: [],
    });

    const toggleComponent = (key: string) => {
        setSelectedComponents((prev) =>
            prev.includes(key) ? prev.filter((c) => c !== key) : [...prev, key],
        );
    };

    const toggleVersion = (v: string) => {
        setSelectedVersions((prev) =>
            prev.includes(v) ? prev.filter((x) => x !== v) : [...prev, v],
        );
    };

    const handleSubmit = () => {
        form.transform(() => ({
            components: selectedComponents,
            php_versions: selectedVersions,
        }));
        form.post(route('services.provision', serverId), {
            onSuccess: () => setOpen(false),
        });
    };

    const phpSelected = selectedComponents.includes('php');

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <PackagePlusIcon className="mr-1.5 h-4 w-4" />
                    Provision Stack
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Provision Server Stack</DialogTitle>
                    <DialogDescription>
                        Select the services to install on this server. This runs apt-based installation via the agent.
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4 py-2">
                    {COMPONENT_GROUPS.map((group) => {
                        const items = COMPONENTS.filter((c) => c.group === group);
                        return (
                            <div key={group}>
                                <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-muted-foreground">{group}</p>
                                <div className="space-y-2">
                                    {items.map(({ key, label }) => (
                                        <div key={key} className="flex items-center gap-2">
                                            <Checkbox
                                                id={`comp-${key}`}
                                                checked={selectedComponents.includes(key)}
                                                onCheckedChange={() => toggleComponent(key)}
                                            />
                                            <Label htmlFor={`comp-${key}`} className="cursor-pointer text-sm font-normal">
                                                {label}
                                            </Label>
                                        </div>
                                    ))}
                                    {group === 'PHP' && phpSelected && (
                                        <div className="ml-6 mt-2 space-y-1.5 border-l pl-3">
                                            <p className="text-xs text-muted-foreground">PHP versions to install:</p>
                                            <div className="flex flex-wrap gap-x-4 gap-y-1.5">
                                                {PHP_VERSIONS.map((v) => (
                                                    <div key={v} className="flex items-center gap-1.5">
                                                        <Checkbox
                                                            id={`php-${v}`}
                                                            checked={selectedVersions.includes(v)}
                                                            onCheckedChange={() => toggleVersion(v)}
                                                        />
                                                        <Label htmlFor={`php-${v}`} className="cursor-pointer text-sm font-normal">
                                                            {v}
                                                        </Label>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </div>
                        );
                    })}
                </div>

                {form.errors.components && (
                    <p className="text-xs text-destructive">{form.errors.components}</p>
                )}

                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button
                        onClick={handleSubmit}
                        disabled={form.processing || selectedComponents.length === 0 || (phpSelected && selectedVersions.length === 0)}
                    >
                        {form.processing ? 'Installing…' : `Install ${selectedComponents.length} component${selectedComponents.length !== 1 ? 's' : ''}`}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
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
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Services</h1>
                        <p className="text-muted-foreground mt-1 text-sm">
                            Install and manage services running on your server.
                        </p>
                    </div>
                    <ProvisionDialog serverId={server.id} />
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
                                <PackagePlusIcon className="h-8 w-8 text-muted-foreground/50" />
                                <p className="text-sm text-muted-foreground">
                                    No services installed yet. Use <strong>Provision Stack</strong> to install nginx, PHP, Redis, and more.
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

                {jobs.length > 0 && (
                    <Card>
                        <CardContent className="p-0">
                            <p className="border-b px-4 py-2.5 text-xs font-medium text-muted-foreground">Recent Jobs</p>
                            <div className="divide-y">
                                {jobs.map((job) => (
                                    <div key={job.uuid} className="flex items-center gap-3 px-4 py-2.5">
                                        {jobStatusIcon(job.status)}
                                        <span className="flex-1 truncate text-sm">{job.label ?? job.type}</span>
                                        <Badge
                                            variant={job.status === 'succeeded' ? 'outline' : job.status === 'failed' || job.status === 'timeout' ? 'destructive' : 'secondary'}
                                            className="text-xs"
                                        >
                                            {job.status}
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}
            </div>
        </ServerLayout>
    );
}
