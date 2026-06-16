import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import echo from '@/echo';
import AppLayout from '@/layouts/app-layout';
import { type AgentJob, type AgentJobStatus, type BreadcrumbItem, type WorkerStatus, type WorkerSummary } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ChevronDownIcon, TriangleAlertIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'running':
        case 'succeeded':
            return 'default';
        case 'stopped':
        case 'failed':
        case 'timeout':
            return 'destructive';
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

function WorkerRow({ worker }: { worker: WorkerSummary }) {
    const editForm = useForm<{ command: string; numprocs: number }>({
        command: worker.command,
        numprocs: worker.config?.numprocs ?? 1,
    });

    const controlForm = useForm<{ action: 'start' | 'stop' | 'restart' }>({
        action: 'start',
    });

    const deleteForm = useForm({});

    const submitEdit = () => {
        editForm.patch(route('workers.update', worker.id), { preserveScroll: true });
    };

    const submitControl = (action: 'start' | 'stop' | 'restart') => {
        controlForm.transform(() => ({ action }));
        controlForm.post(route('workers.control', worker.id), { preserveScroll: true });
    };

    const submitDelete = () => {
        if (!confirm(`Delete worker "${worker.name}"? This stops the supervisor program and removes its config.`)) {
            return;
        }
        deleteForm.delete(route('workers.destroy', worker.id), { preserveScroll: true });
    };

    return (
        <Collapsible>
            <div className="flex items-center justify-between rounded-md border px-3 py-2">
                <div className="flex items-center gap-2">
                    <span className="text-sm font-medium">{worker.name}</span>
                    <span className="text-muted-foreground font-mono text-xs">{worker.command}</span>
                    <span className="text-muted-foreground text-xs">x{worker.config?.numprocs ?? 1}</span>
                </div>
                <div className="flex items-center gap-2">
                    <Badge variant={statusVariant(worker.status)}>{worker.status}</Badge>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => submitControl('start')}
                        disabled={controlForm.processing}
                    >
                        Start
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => submitControl('restart')}
                        disabled={controlForm.processing}
                    >
                        Restart
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => submitControl('stop')}
                        disabled={controlForm.processing}
                    >
                        Stop
                    </Button>
                    <CollapsibleTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-7 w-7">
                            <ChevronDownIcon />
                        </Button>
                    </CollapsibleTrigger>
                </div>
            </div>
            <CollapsibleContent>
                <div className="mt-2 grid gap-3 rounded-md border p-3">
                    <div className="grid gap-2">
                        <Label htmlFor={`command-${worker.id}`}>Command</Label>
                        <Input
                            id={`command-${worker.id}`}
                            value={editForm.data.command}
                            onChange={(e) => editForm.setData('command', e.target.value)}
                            className="font-mono text-sm"
                        />
                        {editForm.errors.command && <p className="text-sm text-destructive">{editForm.errors.command}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`numprocs-${worker.id}`}>Processes</Label>
                        <Input
                            id={`numprocs-${worker.id}`}
                            type="number"
                            min={1}
                            max={20}
                            className="max-w-24"
                            value={editForm.data.numprocs}
                            onChange={(e) => editForm.setData('numprocs', Number(e.target.value))}
                        />
                        {editForm.errors.numprocs && <p className="text-sm text-destructive">{editForm.errors.numprocs}</p>}
                    </div>
                    <div className="flex items-center gap-2">
                        <Button onClick={submitEdit} disabled={editForm.processing} size="sm">
                            Save changes
                        </Button>
                        <Button onClick={submitDelete} disabled={deleteForm.processing} variant="destructive" size="sm">
                            Delete worker
                        </Button>
                    </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function ApplicationsWorkers({
    application,
    server,
    workers,
    jobs,
}: {
    application: { id: string; name: string };
    server: { id: string; name: string; status: string };
    workers: WorkerSummary[];
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

    const createForm = useForm<{ name: string; command: string; numprocs: number }>({
        name: '',
        command: '',
        numprocs: 1,
    });

    const submitCreate = () => {
        createForm.post(route('workers.store', application.id), {
            preserveScroll: true,
            onSuccess: () => createForm.reset(),
        });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: application.name, href: `/apps/${application.id}` },
        { title: 'Workers', href: `/apps/${application.id}/workers` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${application.name} — Workers`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Queue workers</h1>
                </div>

                {server.status !== 'online' && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon className="h-4 w-4" />
                        <AlertTitle>Server offline</AlertTitle>
                        <AlertDescription>
                            The agent on this server is not connected. Actions that require the agent (deploy, PHP switch, .env write, SSL) will be queued but won't run until the server reconnects.
                        </AlertDescription>
                    </Alert>
                )}

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Workers</CardTitle>
                        <CardDescription>Supervisord programs managed for {application.name}.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-2">
                        {workers.length === 0 && <p className="text-muted-foreground text-sm">No workers configured yet.</p>}
                        {workers.map((worker) => (
                            <WorkerRow key={worker.id} worker={worker} />
                        ))}
                    </CardContent>
                </Card>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Add worker</CardTitle>
                        <CardDescription>
                            Creates a new supervisord program, e.g. <code>php artisan queue:work --tries=3</code>.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={createForm.data.name}
                                onChange={(e) => createForm.setData('name', e.target.value)}
                                placeholder="default"
                                className="max-w-60"
                            />
                            {createForm.errors.name && <p className="text-sm text-destructive">{createForm.errors.name}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="command">Command</Label>
                            <Input
                                id="command"
                                value={createForm.data.command}
                                onChange={(e) => createForm.setData('command', e.target.value)}
                                placeholder="php artisan queue:work --tries=3"
                                className="font-mono text-sm"
                            />
                            {createForm.errors.command && <p className="text-sm text-destructive">{createForm.errors.command}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="numprocs">Processes</Label>
                            <Input
                                id="numprocs"
                                type="number"
                                min={1}
                                max={20}
                                className="max-w-24"
                                value={createForm.data.numprocs}
                                onChange={(e) => createForm.setData('numprocs', Number(e.target.value))}
                            />
                            {createForm.errors.numprocs && <p className="text-sm text-destructive">{createForm.errors.numprocs}</p>}
                        </div>
                    </CardContent>
                    <CardFooter>
                        <Button onClick={submitCreate} disabled={createForm.processing}>
                            Add worker
                        </Button>
                    </CardFooter>
                </Card>

                {liveJobs.length > 0 && (
                    <Card className="max-w-2xl">
                        <CardHeader>
                            <CardTitle>Job progress</CardTitle>
                            <CardDescription>Live status of worker jobs dispatched for this application.</CardDescription>
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
