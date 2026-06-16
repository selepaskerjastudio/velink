import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
import { type AgentJob, type AgentJobStatus, type BreadcrumbItem, type SystemdService } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { EllipsisIcon, SearchIcon, TriangleAlertIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

type ServiceAction = 'start' | 'stop' | 'restart' | 'reload';

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
        case 'succeeded':
            return 'default';
        case 'failed':
        case 'timeout':
            return 'destructive';
        default:
            return 'secondary';
    }
}

function formatMemory(bytes: number): string {
    if (bytes >= 1024 * 1024 * 1024) return `${(bytes / 1024 / 1024 / 1024).toFixed(1)} GB`;
    if (bytes >= 1024 * 1024) return `${(bytes / 1024 / 1024).toFixed(0)} MB`;
    return `${(bytes / 1024).toFixed(0)} KB`;
}

interface AgentJobUpdatedEvent {
    uuid: string;
    type: string;
    label: string | null;
    status: AgentJobStatus;
    exit_code: number | null;
    output: string | null;
}

function ServiceRow({ service }: { service: SystemdService }) {
    const controlForm = useForm<{ action: ServiceAction }>({ action: 'restart' });

    const dispatch = (action: ServiceAction) => {
        controlForm.transform(() => ({ action }));
        controlForm.post(route('services.control', service.id), { preserveScroll: true });
    };

    const remove = () => {
        if (confirm(`Stop tracking ${service.name}? This does not stop or uninstall the service on the server.`)) {
            router.delete(route('services.destroy', service.id), { preserveScroll: true });
        }
    };

    return (
        <tr className="border-b last:border-0">
            <td className="py-3 pl-4 pr-2">
                <div className="flex flex-col">
                    <span className="text-sm font-medium">{service.name}</span>
                    {service.config?.label && (
                        <span className="text-muted-foreground text-xs">{service.config.label}</span>
                    )}
                </div>
            </td>
            <td className="px-4 py-3 text-sm text-muted-foreground">
                {service.cpu_percent != null ? `${service.cpu_percent.toFixed(2)}%` : '—'}
            </td>
            <td className="px-4 py-3 text-sm text-muted-foreground">
                {service.memory_usage != null ? formatMemory(service.memory_usage) : '—'}
            </td>
            <td className="px-2 py-3">
                <Badge variant={statusVariant(service.status)}>{service.status}</Badge>
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
                        <DropdownMenuItem onClick={() => dispatch('stop')}>Stop</DropdownMenuItem>
                        <DropdownMenuItem onClick={() => dispatch('restart')}>Restart</DropdownMenuItem>
                        <DropdownMenuItem onClick={() => dispatch('reload')}>Reload</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            onClick={() => router.post(route('services.refresh-status', service.id), {}, { preserveScroll: true })}
                        >
                            Refresh status
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={remove} className="text-destructive focus:text-destructive">
                            Remove
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
    jobs: AgentJob[];
}) {
    const [search, setSearch] = useState('');

    const filtered = services.filter((s) =>
        s.name.toLowerCase().includes(search.toLowerCase()),
    );

    const registerForm = useForm<{ name: string; label: string }>({ name: '', label: '' });

    const submitRegister = () => {
        registerForm.post(route('services.store', server.id), {
            preserveScroll: true,
            onSuccess: () => registerForm.reset(),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Services', href: `/servers/${server.id}/services` },
    ];

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`${server.name} — Services`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Services</h1>

                {server.status !== 'online' && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon className="h-4 w-4" />
                        <AlertTitle>Server offline</AlertTitle>
                        <AlertDescription>
                            The agent on this server is not connected. Service actions will be queued but won't run until the server reconnects.
                        </AlertDescription>
                    </Alert>
                )}

                <p className="text-muted-foreground text-sm">
                    Enable or disable services running on your server. Start, stop, reload, or restart any tracked systemd unit.
                </p>

                <Card>
                    <CardHeader>
                        <CardTitle>Services</CardTitle>
                        <CardDescription>Systemd units tracked on this server.</CardDescription>
                    </CardHeader>
                    <CardContent className="p-0">
                        {services.length === 0 ? (
                            <p className="text-muted-foreground px-4 pb-4 text-sm">No services registered yet.</p>
                        ) : (
                            <>
                                <div className="border-b px-4 py-3">
                                    <div className="relative max-w-xs">
                                        <SearchIcon className="text-muted-foreground absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2" />
                                        <Input
                                            placeholder="Search services..."
                                            value={search}
                                            onChange={(e) => setSearch(e.target.value)}
                                            className="pl-9"
                                        />
                                    </div>
                                </div>
                                <table className="w-full">
                                    <thead>
                                        <tr className="border-b">
                                            <th className="py-2 pl-4 pr-2 text-left text-xs font-medium text-muted-foreground">Service</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-muted-foreground">CPU %</th>
                                            <th className="px-4 py-2 text-left text-xs font-medium text-muted-foreground">Memory</th>
                                            <th className="px-2 py-2 text-left text-xs font-medium text-muted-foreground">Status</th>
                                            <th className="py-2 pl-2 pr-4" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {filtered.length === 0 ? (
                                            <tr>
                                                <td colSpan={5} className="text-muted-foreground px-4 py-4 text-sm">
                                                    No services match your search.
                                                </td>
                                            </tr>
                                        ) : (
                                            filtered.map((service) => (
                                                <ServiceRow key={service.id} service={service} />
                                            ))
                                        )}
                                    </tbody>
                                </table>
                                <p className="text-muted-foreground border-t px-4 py-3 text-xs">
                                    Showing {filtered.length} of {services.length} service{services.length !== 1 ? 's' : ''}
                                </p>
                            </>
                        )}
                    </CardContent>
                </Card>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Track a service</CardTitle>
                        <CardDescription>Add a systemd unit by name, e.g. nginx or php8.3-fpm.service.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Unit name</Label>
                            <Input
                                id="name"
                                value={registerForm.data.name}
                                onChange={(e) => registerForm.setData('name', e.target.value)}
                                placeholder="nginx"
                                className="max-w-60"
                            />
                            <InputError message={registerForm.errors.name} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="label">Label (optional)</Label>
                            <Input
                                id="label"
                                value={registerForm.data.label}
                                onChange={(e) => registerForm.setData('label', e.target.value)}
                                placeholder="Web server"
                                className="max-w-60"
                            />
                            <InputError message={registerForm.errors.label} />
                        </div>
                    </CardContent>
                    <CardFooter>
                        <Button onClick={submitRegister} disabled={registerForm.processing || registerForm.data.name === ''}>
                            Track service
                        </Button>
                    </CardFooter>
                </Card>
            </div>
        </ServerLayout>
    );
}
