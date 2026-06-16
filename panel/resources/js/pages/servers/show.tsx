import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
import {
    type AgentJob,
    type ApplicationSummary,
    type BreadcrumbItem,
    type Server,
    type ServerMetricPoint,
    type SharedData,
} from '@/types';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { CheckIcon, ClipboardIcon, TriangleAlertIcon } from 'lucide-react';
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

function formatUptime(seconds: number): string {
    if (seconds === 0) return '—';
    const d = Math.floor(seconds / 86400);
    const h = Math.floor((seconds % 86400) / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const parts: string[] = [];
    if (d > 0) parts.push(`${d}d`);
    if (h > 0) parts.push(`${h}h`);
    if (m > 0 || parts.length === 0) parts.push(`${m}m`);
    return parts.join(' ');
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
    uptime_seconds?: number;
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

    useEffect(() => setLiveStatus(server.status), [server.status]);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);

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

    const tokenForm = useForm({});
    const deleteForm = useForm({});

    const isOnline = liveStatus === 'online';
    const isPending = liveStatus === 'pending';

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

                {isPending && (
                    <div className="mt-2 flex justify-start">
                        <Dialog>
                            <DialogTrigger asChild>
                                <button className="text-muted-foreground hover:text-destructive text-xs underline-offset-2 hover:underline">
                                    Delete this server
                                </button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Delete server?</DialogTitle>
                                    <DialogDescription>
                                        Server <span className="font-medium text-foreground">{server.name}</span> will be permanently
                                        deleted. This action cannot be undone.
                                    </DialogDescription>
                                </DialogHeader>
                                <DialogFooter>
                                    <Button
                                        variant="destructive"
                                        disabled={deleteForm.processing}
                                        onClick={() => deleteForm.delete(route('servers.destroy', server.id))}
                                    >
                                        Delete server
                                    </Button>
                                </DialogFooter>
                            </DialogContent>
                        </Dialog>
                    </div>
                )}

                {!isPending && (
                    <>
                        {/* Compact server details — 1 row */}
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                            {server.hostname && <span className="text-muted-foreground">{server.hostname}</span>}
                            {server.public_ip && <span className="text-muted-foreground">{server.public_ip}</span>}
                            {server.private_ip && <span className="text-muted-foreground">Private: {server.private_ip}</span>}
                            {server.os && <span className="text-muted-foreground">{server.os}</span>}
                            {server.agent_version && <span className="text-muted-foreground">Agent {server.agent_version}</span>}
                            {server.last_seen_at && <span className="text-muted-foreground">Seen: {server.last_seen_at}</span>}
                            <Button
                                variant="link"
                                size="sm"
                                className="text-muted-foreground h-auto p-0 text-sm font-normal"
                                disabled={tokenForm.processing}
                                onClick={() => tokenForm.post(route('servers.regenerate-token', server.id))}
                            >
                                Regenerate token
                            </Button>
                        </div>

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

                            {/* Uptime */}
                            <Card>
                                <CardHeader className="pb-2">
                                    <CardTitle className="text-muted-foreground text-sm font-medium">Uptime</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="text-3xl font-bold">
                                        {latestMetric?.uptime_seconds != null ? formatUptime(latestMetric.uptime_seconds) : '—'}
                                    </p>
                                    <p className="text-muted-foreground mt-1 text-xs">since last boot</p>
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

                        {/* Resource usage chart */}
                        {recentMetrics.length > 0 && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Memory usage</CardTitle>
                                    <CardDescription>RAM % — last {recentMetrics.length} readings</CardDescription>
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


            </div>
        </ServerLayout>
    );
}
