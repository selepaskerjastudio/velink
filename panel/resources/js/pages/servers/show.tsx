import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
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
import { CheckIcon, ChevronDownIcon, ClipboardIcon, TriangleAlertIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Legend, Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

function CopyCommand({ command }: { command: string }) {
    const [copied, setCopied] = useState(false);

    const copy = () => {
        navigator.clipboard.writeText(command).then(() => {
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        });
    };

    return (
        <div className="mt-2 flex max-w-[640px] items-center gap-2">
            <pre className="bg-muted flex-1 rounded-md p-3 text-xs whitespace-pre-wrap break-all">{command}</pre>
            <Button variant="outline" size="icon" className="shrink-0" onClick={copy} aria-label="Copy command">
                {copied ? <CheckIcon className="h-4 w-4" /> : <ClipboardIcon className="h-4 w-4" />}
            </Button>
        </div>
    );
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' | 'success' {
    switch (status) {
        case 'online':
        case 'succeeded':
            return 'success';
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

function formatGB(bytes: number): string {
    return (bytes / 1024 / 1024 / 1024).toFixed(2) + ' GB';
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

interface LatestMetric {
    cpu_percent: number;
    mem_total: number;
    mem_used: number;
    disk_total: number;
    disk_used: number;
    load1: number;
}

interface Counts {
    applications: number;
    databases: number;
    cron_jobs: number;
    workers: number;
}

export default function ServersShow({
    server,
    applications,
    jobs,
    phpVersions,
    dbComponents: dbComponentKeys,
    recentMetrics,
    latestMetric,
    counts,
}: {
    server: Server;
    applications: ApplicationSummary[];
    jobs: AgentJob[];
    phpVersions: string[];
    dbComponents: string[];
    recentMetrics: ServerMetricPoint[];
    latestMetric: LatestMetric | null;
    counts: Counts;
}) {
    const { flash } = usePage<SharedData>().props;
    const [liveStatus, setLiveStatus] = useState(server.status);
    const [liveJobs, setLiveJobs] = useState<AgentJob[]>(jobs ?? []);

    useEffect(() => setLiveJobs(jobs ?? []), [jobs]);
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

        // Track recently viewed
        try {
            const key = 'velink:recently-viewed';
            const existing: string[] = JSON.parse(localStorage.getItem(key) ?? '[]');
            const updated = [server.id, ...existing.filter((id) => id !== server.id)].slice(0, 5);
            localStorage.setItem(key, JSON.stringify(updated));
        } catch {}

        return () => {
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const form = useForm<{ php_versions: string[]; db_components: string[] }>({
        php_versions: [],
        db_components: [],
    });

    const tokenForm = useForm({});

    const togglePhpVersion = (version: string, checked: boolean) => {
        form.setData(
            'php_versions',
            checked ? [...form.data.php_versions, version] : form.data.php_versions.filter((v) => v !== version),
        );
    };

    const toggleDb = (key: string, checked: boolean) => {
        form.setData(
            'db_components',
            checked ? [...form.data.db_components, key] : form.data.db_components.filter((k) => k !== key),
        );
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
        { title: `${server.name}${server.public_ip ? ` (${server.public_ip})` : ''}`, href: `/servers/${server.id}` },
    ];

    // Compute metric card percentages
    const memPct =
        latestMetric && latestMetric.mem_total > 0 ? Math.round((latestMetric.mem_used / latestMetric.mem_total) * 100) : null;
    const diskPct =
        latestMetric && latestMetric.disk_total > 0 ? Math.round((latestMetric.disk_used / latestMetric.disk_total) * 100) : null;

    return (
        <ServerLayout
            breadcrumbs={breadcrumbs}
            server={{ id: server.id, name: server.name, public_ip: server.public_ip ?? null, status: liveStatus }}
        >
            <Head title={server.name} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {/* Server header */}
                <div className="flex items-start justify-between gap-4">
                    <div>
                        <h1 className="text-2xl font-bold tracking-tight">{server.name}</h1>
                        <div className="text-muted-foreground mt-1 flex flex-wrap items-center gap-3 text-sm">
                            {server.os && <span>{server.os}</span>}
                            <span>x86_64</span>
                            {server.agent_version && <span>Agent: {server.agent_version}</span>}
                        </div>
                    </div>
                    <Badge variant={statusVariant(liveStatus)} className="mt-1 shrink-0">
                        {liveStatus}
                    </Badge>
                </div>

                {/* Agent not connected card */}
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

                {/* Flash alert */}
                {flash.installCommand && (
                    <Alert>
                        <TriangleAlertIcon />
                        <AlertTitle>Install the agent on this server</AlertTitle>
                        <AlertDescription>
                            <p>Run this command on the target server. The token is shown only once — copy it now.</p>
                            <CopyCommand command={flash.installCommand} />
                            {flash.plainAgentToken && (
                                <p className="mt-2 text-sm">
                                    Agent token: <span className="font-mono">{flash.plainAgentToken}</span>
                                </p>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                {!isPending && (
                    <>
                        {/* Metric cards */}
                        <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                            {/* Load */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-muted-foreground text-sm font-medium">Load</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-3xl font-bold">{latestMetric?.load1 != null ? latestMetric.load1.toFixed(2) : '—'}</p>
                                    <p className="text-muted-foreground mt-1 text-xs">1-min average</p>
                                </CardContent>
                            </Card>

                            {/* Memory */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-muted-foreground text-sm font-medium">Memory</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {latestMetric && memPct !== null ? (
                                        <>
                                            <p className="mb-2 text-lg font-bold">{memPct}%</p>
                                            <div className="h-2 rounded-full bg-muted">
                                                <div className="h-2 rounded-full bg-primary" style={{ width: `${memPct}%` }} />
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {formatGB(latestMetric.mem_used)} used of {formatGB(latestMetric.mem_total)}
                                            </p>
                                        </>
                                    ) : (
                                        <p className="text-3xl font-bold">—</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Disk */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-muted-foreground text-sm font-medium">Disk</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {latestMetric && diskPct !== null ? (
                                        <>
                                            <p className="mb-2 text-lg font-bold">{diskPct}%</p>
                                            <div className="h-2 rounded-full bg-muted">
                                                <div className="h-2 rounded-full bg-primary" style={{ width: `${diskPct}%` }} />
                                            </div>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                {formatGB(latestMetric.disk_used)} used of {formatGB(latestMetric.disk_total)}
                                            </p>
                                        </>
                                    ) : (
                                        <p className="text-3xl font-bold">—</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* CPU */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-muted-foreground text-sm font-medium">CPU</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-3xl font-bold">
                                        {latestMetric?.cpu_percent != null ? `${latestMetric.cpu_percent.toFixed(1)}%` : '—'}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">current usage</p>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Counts strip */}
                        <Card>
                            <CardContent className="py-3">
                                <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
                                    <div className="flex items-center gap-2">
                                        <span className="text-muted-foreground text-sm">Web Applications:</span>
                                        <span className="text-sm font-bold">{counts.applications}</span>
                                    </div>
                                    <span className="text-muted-foreground hidden text-sm sm:inline">|</span>
                                    <div className="flex items-center gap-2">
                                        <span className="text-muted-foreground text-sm">Databases:</span>
                                        <span className="text-sm font-bold">{counts.databases}</span>
                                    </div>
                                    <span className="text-muted-foreground hidden text-sm sm:inline">|</span>
                                    <div className="flex items-center gap-2">
                                        <span className="text-muted-foreground text-sm">Cron Jobs:</span>
                                        <span className="text-sm font-bold">{counts.cron_jobs}</span>
                                    </div>
                                    <span className="text-muted-foreground hidden text-sm sm:inline">|</span>
                                    <div className="flex items-center gap-2">
                                        <span className="text-muted-foreground text-sm">Workers:</span>
                                        <span className="text-sm font-bold">{counts.workers}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        {/* Latest web applications table */}
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <div>
                                    <CardTitle>Latest Web Applications</CardTitle>
                                    <CardDescription>PHP/Laravel apps hosted on this server.</CardDescription>
                                </div>
                                {isOnline ? (
                                    <Button asChild size="sm">
                                        <Link href={route('applications.create', server.id)}>New application</Link>
                                    </Button>
                                ) : (
                                    <Button size="sm" disabled>
                                        New application
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent className="p-0">
                                {applications.length === 0 ? (
                                    <p className="text-muted-foreground px-6 py-4 text-sm">No applications yet.</p>
                                ) : (
                                    <table className="w-full text-sm">
                                        <thead>
                                            <tr className="border-b">
                                                <th className="text-muted-foreground px-6 py-3 text-left font-medium">Name</th>
                                                <th className="text-muted-foreground px-6 py-3 text-left font-medium">Domain</th>
                                                <th className="text-muted-foreground px-6 py-3 text-left font-medium">PHP</th>
                                                <th className="text-muted-foreground px-6 py-3 text-left font-medium">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {applications.map((app) => (
                                                <tr key={app.id} className="hover:bg-muted/50 border-b last:border-0">
                                                    <td className="px-6 py-3">
                                                        <Link
                                                            href={route('applications.show', app.id)}
                                                            className="font-medium hover:underline"
                                                        >
                                                            {app.name}
                                                        </Link>
                                                    </td>
                                                    <td className="text-muted-foreground px-6 py-3">{app.domain ?? '—'}</td>
                                                    <td className="px-6 py-3">
                                                        <Badge variant="outline">PHP {app.php_version}</Badge>
                                                    </td>
                                                    <td className="px-6 py-3">
                                                        <Badge variant={statusVariant(app.status)}>{app.status}</Badge>
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </CardContent>
                        </Card>

                        {/* Provisioning / Activity tabs */}
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
                                                        <span
                                                            key={s}
                                                            className="bg-muted text-muted-foreground rounded-md px-2 py-0.5 text-xs"
                                                        >
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
                                                                onCheckedChange={(checked) =>
                                                                    togglePhpVersion(version, checked === true)
                                                                }
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
                                                            <Label htmlFor={`db-${key}`} className="text-sm">
                                                                {label}
                                                            </Label>
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
                                                                    <span className="text-muted-foreground text-xs">
                                                                        exit {job.exit_code}
                                                                    </span>
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

                        {/* Resource usage chart */}
                        {recentMetrics.length > 0 && (
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
                                            <Line
                                                type="monotone"
                                                dataKey="cpu"
                                                name="CPU"
                                                stroke="#3b82f6"
                                                dot={false}
                                                strokeWidth={1.5}
                                            />
                                            <Line
                                                type="monotone"
                                                dataKey="ram"
                                                name="RAM"
                                                stroke="#22c55e"
                                                dot={false}
                                                strokeWidth={1.5}
                                            />
                                        </LineChart>
                                    </ResponsiveContainer>
                                </CardContent>
                            </Card>
                        )}
                    </>
                )}

                {/* Server details card — hidden while agent hasn't connected yet */}
                {!isPending && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Server details</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-2 text-sm">
                            {(
                                [
                                    ['Hostname', server.hostname],
                                    ['Public IP', server.public_ip],
                                    ['Private IP', server.private_ip],
                                    ['OS', server.os],
                                    ['Agent version', server.agent_version],
                                    ['Last seen', server.last_seen_at ?? 'Never'],
                                ] as [string, string | null | undefined][]
                            ).map(([label, value]) => (
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
                )}

                {/* Manage quick links */}
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
                                    <Button variant="outline" size="sm" disabled>
                                        Services
                                    </Button>
                                    <Button variant="outline" size="sm" disabled>
                                        Cron jobs
                                    </Button>
                                    <Button variant="outline" size="sm" disabled>
                                        Databases
                                    </Button>
                                    <Button variant="outline" size="sm" disabled>
                                        Database users
                                    </Button>
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}

            </div>
        </ServerLayout>
    );
}
