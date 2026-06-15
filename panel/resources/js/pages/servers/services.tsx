import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import echo from '@/echo';
import AppLayout from '@/layouts/app-layout';
import { type AgentJob, type AgentJobStatus, type BreadcrumbItem, type SystemdService } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { ChevronDownIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

const SERVICE_ACTIONS = ['start', 'stop', 'restart', 'reload', 'enable', 'disable'] as const;

type ServiceAction = (typeof SERVICE_ACTIONS)[number];

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
        case 'succeeded':
            return 'default';
        case 'failed':
        case 'timeout':
            return 'destructive';
        case 'running':
            return 'outline';
        default:
            return 'secondary';
    }
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
    const [action, setAction] = useState<ServiceAction>('restart');
    const controlForm = useForm({ action: 'restart' as ServiceAction });

    const submitControl = () => {
        controlForm.transform(() => ({ action }));
        controlForm.post(route('services.control', service.id), { preserveScroll: true });
    };

    const checkStatus = () => {
        router.post(route('services.refresh-status', service.id), {}, { preserveScroll: true });
    };

    const remove = () => {
        if (confirm(`Stop tracking ${service.name}? This does not stop or uninstall the service on the server.`)) {
            router.delete(route('services.destroy', service.id), { preserveScroll: true });
        }
    };

    const enabled = service.config?.enabled;

    return (
        <div className="flex flex-col gap-2 rounded-md border px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
            <div className="flex flex-col">
                <span className="text-sm font-medium">{service.name}</span>
                {service.config?.label && <span className="text-muted-foreground text-xs">{service.config.label}</span>}
            </div>
            <div className="flex flex-wrap items-center gap-2">
                <Badge variant={statusVariant(service.status)}>{service.status}</Badge>
                {enabled !== undefined && <Badge variant="outline">{enabled ? 'enabled' : 'disabled'}</Badge>}

                <Select value={action} onValueChange={(value) => setAction(value as ServiceAction)}>
                    <SelectTrigger className="h-8 w-32">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {SERVICE_ACTIONS.map((a) => (
                            <SelectItem key={a} value={a}>
                                {a}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button size="sm" onClick={submitControl} disabled={controlForm.processing}>
                    Apply
                </Button>
                <Button size="sm" variant="secondary" onClick={checkStatus}>
                    Check status
                </Button>
                <Button size="sm" variant="destructive" onClick={remove}>
                    Remove
                </Button>
            </div>
        </div>
    );
}

export default function ServerServices({
    server,
    services,
    jobs,
}: {
    server: { id: string; name: string };
    services: SystemdService[];
    jobs: AgentJob[];
}) {
    const [liveJobs, setLiveJobs] = useState<AgentJob[]>(jobs);

    useEffect(() => setLiveJobs(jobs), [jobs]);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);

        channel.listen('.agent-job.updated', (event: AgentJobUpdatedEvent) => {
            setLiveJobs((current) => {
                const index = current.findIndex((job) => job.uuid === event.uuid);
                if (index === -1) {
                    return current;
                }
                const next = [...current];
                next[index] = { ...next[index], ...event };
                return next;
            });
        });

        return () => {
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const registerForm = useForm<{ name: string; label: string }>({
        name: '',
        label: '',
    });

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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${server.name} — Services`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Services</h1>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Registered services</CardTitle>
                        <CardDescription>Systemd units tracked on this server.</CardDescription>
                    </CardHeader>
                    {services.length > 0 && (
                        <CardContent className="grid gap-2">
                            {services.map((service) => (
                                <ServiceRow key={service.id} service={service} />
                            ))}
                        </CardContent>
                    )}
                </Card>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Register a service</CardTitle>
                        <CardDescription>Track a systemd unit by name, e.g. nginx or php8.3-fpm.service.</CardDescription>
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
                            Register service
                        </Button>
                    </CardFooter>
                </Card>

                {liveJobs.length > 0 && (
                    <Card className="max-w-2xl">
                        <CardHeader>
                            <CardTitle>Job progress</CardTitle>
                            <CardDescription>Live status of service jobs dispatched to this server.</CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-2">
                            {liveJobs.map((job) => (
                                <Collapsible key={job.uuid}>
                                    <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">{job.label ?? job.type}</span>
                                            {job.exit_code !== null && (
                                                <span className="text-muted-foreground text-xs">exit {job.exit_code}</span>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant={statusVariant(job.status)}>{job.status}</Badge>
                                            {job.output && (
                                                <CollapsibleTrigger asChild>
                                                    <Button variant="ghost" size="icon" className="h-7 w-7">
                                                        <ChevronDownIcon />
                                                    </Button>
                                                </CollapsibleTrigger>
                                            )}
                                        </div>
                                    </div>
                                    {job.output && (
                                        <CollapsibleContent>
                                            <pre className="bg-muted mt-1 max-h-64 overflow-auto rounded-md p-3 text-xs whitespace-pre-wrap">
                                                {job.output}
                                            </pre>
                                        </CollapsibleContent>
                                    )}
                                </Collapsible>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
