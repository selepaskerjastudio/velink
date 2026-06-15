import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import echo from '@/echo';
import AppLayout from '@/layouts/app-layout';
import { type AgentJob, type AgentJobStatus, type BreadcrumbItem, type CronApplicationOption, type CronJobSummary } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ChevronDownIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

const SYSTEM_APPLICATION = 'system';

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
        case 'succeeded':
            return 'default';
        case 'paused':
            return 'secondary';
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

interface CronJobFormData {
    application_id: string;
    user: string;
    command: string;
    schedule: string;
}

function CronJobRow({ cronJob, applications }: { cronJob: CronJobSummary; applications: CronApplicationOption[] }) {
    const editForm = useForm<CronJobFormData>({
        application_id: cronJob.application_id !== null ? String(cronJob.application_id) : SYSTEM_APPLICATION,
        user: cronJob.user,
        command: cronJob.command,
        schedule: cronJob.schedule,
    });

    const toggleForm = useForm({});
    const deleteForm = useForm({});

    const submitEdit = () => {
        editForm.transform((data) => ({
            ...data,
            application_id: data.application_id === SYSTEM_APPLICATION ? null : data.application_id,
        }));
        editForm.patch(route('cron.update', cronJob.id), { preserveScroll: true });
    };

    const submitToggle = () => {
        toggleForm.post(route('cron.toggle', cronJob.id), { preserveScroll: true });
    };

    const submitDelete = () => {
        if (!confirm('Delete this cron job?')) {
            return;
        }
        deleteForm.delete(route('cron.destroy', cronJob.id), { preserveScroll: true });
    };

    return (
        <Collapsible>
            <div className="flex flex-col gap-2 rounded-md border px-3 py-2 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex flex-col">
                    <div className="flex items-center gap-2">
                        <span className="font-mono text-sm">{cronJob.schedule}</span>
                        <span className="text-muted-foreground text-xs">{cronJob.user}</span>
                        {cronJob.application_name && <Badge variant="outline">{cronJob.application_name}</Badge>}
                    </div>
                    <span className="text-muted-foreground font-mono text-xs">{cronJob.command}</span>
                    {cronJob.last_run_at && <span className="text-muted-foreground text-xs">Last run: {cronJob.last_run_at}</span>}
                </div>
                <div className="flex items-center gap-2">
                    <Badge variant={statusVariant(cronJob.status)}>{cronJob.status}</Badge>
                    <Button size="sm" variant="outline" onClick={submitToggle} disabled={toggleForm.processing}>
                        {cronJob.status === 'active' ? 'Pause' : 'Resume'}
                    </Button>
                    <Button size="sm" variant="destructive" onClick={submitDelete} disabled={deleteForm.processing}>
                        Delete
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
                        <Label htmlFor={`application-${cronJob.id}`}>Application</Label>
                        <Select
                            value={editForm.data.application_id}
                            onValueChange={(value) => editForm.setData('application_id', value)}
                        >
                            <SelectTrigger id={`application-${cronJob.id}`} className="max-w-60">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={SYSTEM_APPLICATION}>System</SelectItem>
                                {applications.map((application) => (
                                    <SelectItem key={application.id} value={String(application.id)}>
                                        {application.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {editForm.errors.application_id && <p className="text-sm text-destructive">{editForm.errors.application_id}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`user-${cronJob.id}`}>User</Label>
                        <Input
                            id={`user-${cronJob.id}`}
                            value={editForm.data.user}
                            onChange={(e) => editForm.setData('user', e.target.value)}
                            className="max-w-60 font-mono"
                        />
                        {editForm.errors.user && <p className="text-sm text-destructive">{editForm.errors.user}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`command-${cronJob.id}`}>Command</Label>
                        <Input
                            id={`command-${cronJob.id}`}
                            value={editForm.data.command}
                            onChange={(e) => editForm.setData('command', e.target.value)}
                            className="font-mono text-sm"
                        />
                        {editForm.errors.command && <p className="text-sm text-destructive">{editForm.errors.command}</p>}
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`schedule-${cronJob.id}`}>Schedule</Label>
                        <Input
                            id={`schedule-${cronJob.id}`}
                            value={editForm.data.schedule}
                            onChange={(e) => editForm.setData('schedule', e.target.value)}
                            placeholder="* * * * *"
                            className="max-w-40 font-mono"
                        />
                        {editForm.errors.schedule && <p className="text-sm text-destructive">{editForm.errors.schedule}</p>}
                    </div>
                    <div>
                        <Button size="sm" onClick={submitEdit} disabled={editForm.processing}>
                            Save changes
                        </Button>
                    </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function ServerCron({
    server,
    cronJobs,
    applications,
    jobs,
}: {
    server: { id: string; name: string };
    cronJobs: CronJobSummary[];
    applications: CronApplicationOption[];
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

    const createForm = useForm<CronJobFormData>({
        application_id: SYSTEM_APPLICATION,
        user: 'root',
        command: '',
        schedule: '* * * * *',
    });

    const submitCreate = () => {
        createForm.transform((data) => ({
            ...data,
            application_id: data.application_id === SYSTEM_APPLICATION ? null : data.application_id,
        }));
        createForm.post(route('cron.store', server.id), {
            preserveScroll: true,
            onSuccess: () => createForm.reset('command'),
        });
    };

    const onApplicationChange = (value: string) => {
        createForm.setData('application_id', value);

        if (value !== SYSTEM_APPLICATION) {
            const application = applications.find((app) => String(app.id) === value);
            if (application) {
                createForm.setData('user', application.linux_user);
            }
        } else {
            createForm.setData('user', 'root');
        }
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Cron jobs', href: `/servers/${server.id}/cron` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Cron jobs — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Cron jobs</h1>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Scheduled jobs</CardTitle>
                        <CardDescription>Managed via /etc/cron.d/coruncloud on this server.</CardDescription>
                    </CardHeader>
                    {cronJobs.length > 0 && (
                        <CardContent className="grid gap-2">
                            {cronJobs.map((cronJob) => (
                                <CronJobRow key={cronJob.id} cronJob={cronJob} applications={applications} />
                            ))}
                        </CardContent>
                    )}
                    {cronJobs.length === 0 && (
                        <CardContent>
                            <p className="text-muted-foreground text-sm">No cron jobs yet.</p>
                        </CardContent>
                    )}
                </Card>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Add cron job</CardTitle>
                        <CardDescription>Schedule is in standard 5-field cron syntax, e.g. */5 * * * *.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="application_id">Application</Label>
                            <Select value={createForm.data.application_id} onValueChange={onApplicationChange}>
                                <SelectTrigger id="application_id" className="max-w-60">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value={SYSTEM_APPLICATION}>System</SelectItem>
                                    {applications.map((application) => (
                                        <SelectItem key={application.id} value={String(application.id)}>
                                            {application.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {createForm.errors.application_id && (
                                <p className="text-sm text-destructive">{createForm.errors.application_id}</p>
                            )}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="user">User</Label>
                            <Input
                                id="user"
                                value={createForm.data.user}
                                onChange={(e) => createForm.setData('user', e.target.value)}
                                className="max-w-60 font-mono"
                            />
                            {createForm.errors.user && <p className="text-sm text-destructive">{createForm.errors.user}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="command">Command</Label>
                            <Input
                                id="command"
                                value={createForm.data.command}
                                onChange={(e) => createForm.setData('command', e.target.value)}
                                placeholder="php artisan schedule:run"
                                className="font-mono text-sm"
                            />
                            {createForm.errors.command && <p className="text-sm text-destructive">{createForm.errors.command}</p>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="schedule">Schedule</Label>
                            <Input
                                id="schedule"
                                value={createForm.data.schedule}
                                onChange={(e) => createForm.setData('schedule', e.target.value)}
                                placeholder="* * * * *"
                                className="max-w-40 font-mono"
                            />
                            {createForm.errors.schedule && <p className="text-sm text-destructive">{createForm.errors.schedule}</p>}
                        </div>
                    </CardContent>
                    <CardFooter>
                        <Button onClick={submitCreate} disabled={createForm.processing || createForm.data.command === ''}>
                            Add cron job
                        </Button>
                    </CardFooter>
                </Card>

                {liveJobs.length > 0 && (
                    <Card className="max-w-2xl">
                        <CardHeader>
                            <CardTitle>Job progress</CardTitle>
                            <CardDescription>Live status of cron sync jobs dispatched to this server.</CardDescription>
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
