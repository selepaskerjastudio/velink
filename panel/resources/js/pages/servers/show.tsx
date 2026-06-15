import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import echo from '@/echo';
import AppLayout from '@/layouts/app-layout';
import {
    type AgentJob,
    type AgentJobStatus,
    type ApplicationSummary,
    type BreadcrumbItem,
    type Server,
    type ServerMetricPoint,
    type SharedData,
} from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronDownIcon, TriangleAlertIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'online':
        case 'succeeded':
            return 'default';
        case 'offline':
        case 'failed':
        case 'timeout':
            return 'destructive';
        case 'running':
            return 'outline';
        default:
            return 'secondary';
    }
}

const CORE_LABELS = ['Nginx', 'Certbot', 'Composer', 'Node.js 20', 'Supervisord', 'Redis'];

const DB_OPTIONS = [
    { key: 'mariadb', label: 'MariaDB' },
    { key: 'postgresql', label: 'PostgreSQL' },
    { key: 'mongodb', label: 'MongoDB' },
];

interface AgentJobUpdatedEvent {
    uuid: string;
    type: string;
    label: string | null;
    status: AgentJobStatus;
    exit_code: number | null;
    output: string | null;
}

interface ServerPresenceEvent {
    id: string;
    status: string;
    agent_version: string | null;
    last_seen_at: string | null;
}

export default function ServersShow({
    server,
    applications,
    jobs,
    phpVersions,
    dbComponents: dbComponentKeys,
    recentMetrics,
}: {
    server: Server;
    applications: ApplicationSummary[];
    jobs: AgentJob[];
    phpVersions: string[];
    dbComponents: string[];
    recentMetrics: ServerMetricPoint[];
}) {
    const { flash } = usePage<SharedData>().props;
    const [liveStatus, setLiveStatus] = useState(server.status);
    const [liveJobs, setLiveJobs] = useState<AgentJob[]>(jobs);

    useEffect(() => setLiveJobs(jobs), [jobs]);
    useEffect(() => setLiveStatus(server.status), [server.status]);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);

        channel.listen('.agent-job.updated', (event: AgentJobUpdatedEvent) => {
            setLiveJobs((current) => {
                const index = current.findIndex((job) => job.uuid === event.uuid);
                if (index === -1) return current;
                const next = [...current];
                next[index] = { ...next[index], ...event };
                return next;
            });
        });

        channel.listen('.server.presence', (event: ServerPresenceEvent) => {
            setLiveStatus(event.status);
        });

        return () => { echo.leave(`server.${server.id}`); };
    }, [server.id]);

    const form = useForm<{ php_versions: string[]; db_components: string[] }>({
        php_versions: [],
        db_components: [],
    });

    const tokenForm = useForm({});
    const deleteForm = useForm({});
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [deleteConfirm, setDeleteConfirm] = useState('');

    const togglePhpVersion = (version: string, checked: boolean) => {
        form.setData('php_versions', checked
            ? [...form.data.php_versions, version]
            : form.data.php_versions.filter((v) => v !== version));
    };

    const toggleDb = (key: string, checked: boolean) => {
        form.setData('db_components', checked
            ? [...form.data.db_components, key]
            : form.data.db_components.filter((k) => k !== key));
    };

    const submitProvision = () => {
        form.transform((data) => ({
            components: ['base', 'nginx', 'certbot', 'php', 'composer', 'node', 'supervisor', 'redis', ...data.db_components],
            php_versions: data.php_versions,
        }));
        form.post(route('servers.provision', server.id), { preserveScroll: true });
    };

    const isOnline = liveStatus === 'online';
    const isPending = liveStatus === 'pending';
    const runningJobsCount = liveJobs.filter((job) => job.status === 'running' || job.status === 'pending').length;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={server.name} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">{server.name}</h1>
                    <Badge variant={statusVariant(liveStatus)}>{liveStatus}</Badge>
                </div>

                {/* Full-width alerts */}
                {flash.installCommand && (
                    <Alert>
                        <TriangleAlertIcon />
                        <AlertTitle>Install the agent on this server</AlertTitle>
                        <AlertDescription>
                            <p>Run this command on the target server. The token is shown only once — copy it now.</p>
                            <pre className="bg-muted mt-2 overflow-x-auto rounded-md p-3 text-xs">{flash.installCommand}</pre>
                            {flash.plainAgentToken && (
                                <p className="mt-2 text-sm">
                                    Agent token: <span className="font-mono">{flash.plainAgentToken}</span>
                                </p>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {!isOnline && !flash.installCommand && isPending && (
                    <Card className="border-destructive/50">
                        <CardHeader>
                            <CardTitle>Agent not connected</CardTitle>
                            <CardDescription>
                                This server has no active agent connection. Install the agent or regenerate the token.
                            </CardDescription>
                        </CardHeader>
                        <CardFooter className="flex flex-col items-start gap-2">
                            <Button
                                variant="destructive"
                                size="sm"
                                disabled={tokenForm.processing}
                                onClick={() => tokenForm.post(route('servers.regenerate-token', server.id))}
                            >
                                Regenerate install command
                            </Button>
                            <p className="text-muted-foreground text-xs">This will invalidate the existing agent token.</p>
                        </CardFooter>
                    </Card>
                )}

                {/* 2-column grid */}
                <div className="grid gap-4 lg:grid-cols-3">

                    {/* Left column — main content */}
                    <div className="flex flex-col gap-4 lg:col-span-2">

                        {!isPending && (
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <div>
                                        <CardTitle>Applications</CardTitle>
                                        <CardDescription>PHP/Laravel apps hosted on this server.</CardDescription>
                                    </div>
                                    {isOnline ? (
                                        <Button asChild size="sm">
                                            <Link href={route('applications.create', server.id)}>New application</Link>
                                        </Button>
                                    ) : (
                                        <Button size="sm" disabled>New application</Button>
                                    )}
                                </CardHeader>
                                {applications.length > 0 && (
                                    <CardContent className="grid gap-2">
                                        {applications.map((app) => (
                                            <Link
                                                key={app.id}
                                                href={route('applications.show', app.id)}
                                                className="flex items-center justify-between rounded-md border px-3 py-2 hover:bg-accent"
                                            >
                                                <div className="flex flex-col">
                                                    <span className="text-sm font-medium">{app.name}</span>
                                                    <span className="text-muted-foreground text-xs">{app.domain ?? '—'}</span>
                                                </div>
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="outline">PHP {app.php_version}</Badge>
                                                    <Badge variant={statusVariant(app.status)}>{app.status}</Badge>
                                                </div>
                                            </Link>
                                        ))}
                                    </CardContent>
                                )}
                            </Card>
                        )}

                        {!isPending && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Provisioning</CardTitle>
                                    <CardDescription>Install services and track live job progress.</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <Tabs defaultValue="provision">
                                        <TabsList>
                                            <TabsTrigger value="provision">Provision</TabsTrigger>
                                            <TabsTrigger value="activity">
                                                Activity
                                                {runningJobsCount > 0 && (
                                                    <Badge variant="outline" className="ml-1 h-4 px-1 text-xs">
                                                        {runningJobsCount}
                                                    </Badge>
                                                )}
                                            </TabsTrigger>
                                        </TabsList>

                                        <TabsContent value="provision">
                                            <div className="grid gap-5 pt-3">
                                                {/* Core stack */}
                                                <div>
                                                    <p className="text-muted-foreground mb-2 text-xs font-medium uppercase tracking-wide">
                                                        Always installed
                                                    </p>
                                                    <div className="flex flex-wrap gap-1.5">
                                                        {CORE_LABELS.map((s) => (
                                                            <span key={s} className="bg-muted text-muted-foreground rounded-md px-2 py-0.5 text-xs">
                                                                {s}
                                                            </span>
                                                        ))}
                                                    </div>
                                                </div>

                                                {/* PHP versions */}
                                                <div>
                                                    <p className="mb-2 text-sm font-medium">
                                                        PHP version <span className="text-destructive">*</span>
                                                    </p>
                                                    <div className="flex flex-wrap gap-3">
                                                        {phpVersions.map((version) => (
                                                            <div key={version} className="flex items-center gap-2">
                                                                <Checkbox
                                                                    id={`php-${version}`}
                                                                    checked={form.data.php_versions.includes(version)}
                                                                    onCheckedChange={(checked) => togglePhpVersion(version, checked === true)}
                                                                />
                                                                <Label htmlFor={`php-${version}`} className="text-sm">
                                                                    PHP {version}
                                                                </Label>
                                                            </div>
                                                        ))}
                                                    </div>
                                                    {form.errors.php_versions && (
                                                        <p className="mt-1 text-sm text-destructive">{form.errors.php_versions}</p>
                                                    )}
                                                </div>

                                                {/* Optional databases */}
                                                <div>
                                                    <p className="mb-2 text-sm font-medium">
                                                        Databases{' '}
                                                        <span className="text-muted-foreground text-xs font-normal">(optional)</span>
                                                    </p>
                                                    <div className="flex flex-wrap gap-3">
                                                        {DB_OPTIONS.map(({ key, label }) => (
                                                            <div key={key} className="flex items-center gap-2">
                                                                <Checkbox
                                                                    id={`db-${key}`}
                                                                    checked={form.data.db_components.includes(key)}
                                                                    onCheckedChange={(checked) => toggleDb(key, checked === true)}
                                                                />
                                                                <Label htmlFor={`db-${key}`} className="text-sm">{label}</Label>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>

                                                <div className="flex items-center gap-3">
                                                    <Button
                                                        onClick={submitProvision}
                                                        disabled={form.processing || form.data.php_versions.length === 0 || !isOnline}
                                                    >
                                                        Provision server
                                                    </Button>
                                                    {form.data.php_versions.length === 0 && (
                                                        <p className="text-muted-foreground text-sm">Select at least one PHP version</p>
                                                    )}
                                                </div>
                                            </div>
                                        </TabsContent>

                                        <TabsContent value="activity">
                                            {liveJobs.length === 0 ? (
                                                <p className="text-muted-foreground pt-2 text-sm">No jobs dispatched yet.</p>
                                            ) : (
                                                <div className="grid gap-2 pt-2">
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
                                                </div>
                                            )}
                                        </TabsContent>
                                    </Tabs>
                                </CardContent>
                            </Card>
                        )}
                    </div>

                    {/* Right column — sidebar */}
                    <div className="flex flex-col gap-4">

                        <Card>
                            <CardHeader>
                                <CardTitle>Server details</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2 text-sm">
                                {[
                                    ['Hostname', server.hostname],
                                    ['Public IP', server.public_ip],
                                    ['Private IP', server.private_ip],
                                    ['OS', server.os],
                                    ['Agent version', server.agent_version],
                                    ['Last seen', server.last_seen_at ?? 'Never'],
                                ].map(([label, value]) => (
                                    <div key={label} className="flex justify-between gap-2">
                                        <span className="text-muted-foreground shrink-0">{label}</span>
                                        <span className="text-right">{value ?? '—'}</span>
                                    </div>
                                ))}
                            </CardContent>
                            <CardFooter>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={tokenForm.processing}
                                    onClick={() => tokenForm.post(route('servers.regenerate-token', server.id))}
                                >
                                    Regenerate install command
                                </Button>
                            </CardFooter>
                        </Card>

                        {!isPending && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Manage</CardTitle>
                                    <CardDescription>Services, cron jobs, and databases.</CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-wrap gap-2">
                                    {isOnline ? (
                                        <>
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={route('services.index', server.id)}>Services</Link>
                                            </Button>
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={route('cron.index', server.id)}>Cron jobs</Link>
                                            </Button>
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={route('databases.index', server.id)}>Databases</Link>
                                            </Button>
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={route('database-users.index', server.id)}>Database users</Link>
                                            </Button>
                                        </>
                                    ) : (
                                        <>
                                            <Button variant="outline" size="sm" disabled>Services</Button>
                                            <Button variant="outline" size="sm" disabled>Cron jobs</Button>
                                            <Button variant="outline" size="sm" disabled>Databases</Button>
                                            <Button variant="outline" size="sm" disabled>Database users</Button>
                                        </>
                                    )}
                                </CardContent>
                            </Card>
                        )}

                        {!isPending && recentMetrics.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Resource usage</CardTitle>
                                    <CardDescription>CPU and RAM % — last {recentMetrics.length} readings</CardDescription>
                                </CardHeader>
                                <CardContent>
                                    <ResponsiveContainer width="100%" height={180}>
                                        <LineChart data={recentMetrics}>
                                            <XAxis dataKey="ts" interval="preserveStartEnd" tick={{ fontSize: 10 }} />
                                            <YAxis domain={[0, 100]} unit="%" tick={{ fontSize: 10 }} />
                                            <Tooltip formatter={(v: number) => `${v}%`} />
                                            <Legend />
                                            <Line type="monotone" dataKey="cpu" name="CPU" stroke="#3b82f6" dot={false} strokeWidth={1.5} />
                                            <Line type="monotone" dataKey="ram" name="RAM" stroke="#22c55e" dot={false} strokeWidth={1.5} />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        )}
                    </div>
                </div>

                {/* Danger zone — always at bottom */}
                <Card className="border-destructive/40">
                    <CardHeader>
                        <CardTitle>Danger zone</CardTitle>
                        <CardDescription>Permanently delete this server and all associated data.</CardDescription>
                    </CardHeader>
                    <CardFooter>
                        <Dialog open={deleteOpen} onOpenChange={(open) => { setDeleteOpen(open); setDeleteConfirm(''); }}>
                            <DialogTrigger asChild>
                                <Button variant="destructive" size="sm">Delete server</Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Delete server</DialogTitle>
                                    <DialogDescription>
                                        This will permanently delete <strong>{server.name}</strong> and all its applications,
                                        databases, services, and jobs. This action cannot be undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <div className="grid gap-2 py-2">
                                    <Label htmlFor="delete-confirm">Type <strong>DELETE</strong> to confirm</Label>
                                    <Input
                                        id="delete-confirm"
                                        value={deleteConfirm}
                                        onChange={(e) => setDeleteConfirm(e.target.value)}
                                        placeholder="DELETE"
                                    />
                                </div>
                                <DialogFooter>
                                    <Button variant="outline" onClick={() => setDeleteOpen(false)}>Cancel</Button>
                                    <Button
                                        variant="destructive"
                                        disabled={deleteConfirm !== 'DELETE' || deleteForm.processing}
                                        onClick={() => deleteForm.delete(route('servers.destroy', server.id), {
                                            onSuccess: () => setDeleteOpen(false),
                                        })}
                                    >
                                        Delete server
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </CardFooter>
                </Card>
            </div>
        </AppLayout>
    );
}
