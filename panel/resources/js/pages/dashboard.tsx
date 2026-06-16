import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    CloudOff,
    HardDrive,
    MemoryStick,
    Rocket,
    Server,
    Settings,
    Wifi,
    WifiOff,
} from 'lucide-react';

interface DashboardServer {
    id: string;
    name: string;
    public_ip: string | null;
    status: string;
    cpu_percent: number | null;
    mem_total: number | null;
    mem_used: number | null;
    disk_total: number | null;
    disk_used: number | null;
    load1: number | null;
}

interface ServerCounts {
    total: number;
    online: number;
    offline: number;
    provisioning: number;
}

interface AuditEntry {
    id: number;
    action: string;
    description: string | null;
    created_at: string;
    user: { name: string } | null;
}

interface DeploymentEntry {
    id: string;
    application: { name: string } | null;
    branch: string;
    status: string;
    triggered_by: string;
    started_at: string | null;
    finished_at: string | null;
    user: { name: string } | null;
}

function formatGB(bytes: number): string {
    return (bytes / 1024 / 1024 / 1024).toFixed(1);
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function statusColor(status: string): string {
    switch (status) {
        case 'online':
            return 'success';
        case 'offline':
            return 'destructive';
        default:
            return 'secondary';
    }
}

function deployStatusColor(status: string): string {
    switch (status) {
        case 'succeeded':
            return 'success';
        case 'failed':
            return 'destructive';
        case 'running':
            return 'default';
        default:
            return 'secondary';
    }
}

export default function Dashboard({
    servers,
    serverCounts,
    recentActivity,
    recentDeployments,
}: {
    servers: DashboardServer[];
    serverCounts: ServerCounts;
    recentActivity: AuditEntry[];
    recentDeployments: DeploymentEntry[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                {/* Header */}
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Dashboard</h1>
                    <p className="text-muted-foreground text-sm">Overview of your servers and recent activity.</p>
                </div>

                {/* Server count summary */}
                <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Total Servers</CardTitle>
                            <Server className="text-muted-foreground h-4 w-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold">{serverCounts.total}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Online</CardTitle>
                            <Wifi className="text-green-500 h-4 w-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-green-500">{serverCounts.online}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Offline</CardTitle>
                            <WifiOff className="text-red-500 h-4 w-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-red-500">{serverCounts.offline}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                            <CardTitle className="text-sm font-medium">Provisioning</CardTitle>
                            <Settings className="text-yellow-500 h-4 w-4" />
                        </CardHeader>
                        <CardContent>
                            <div className="text-2xl font-bold text-yellow-500">{serverCounts.provisioning}</div>
                        </CardContent>
                    </Card>
                </div>

                {/* Main content grid */}
                <div className="grid gap-4 lg:grid-cols-3">
                    {/* Server list — takes 2 cols */}
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Server className="h-5 w-5" />
                                Servers
                            </CardTitle>
                            <CardDescription>
                                {serverCounts.online} of {serverCounts.total} servers online
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            {servers.length === 0 ? (
                                <div className="text-muted-foreground flex flex-col items-center justify-center py-12 text-center">
                                    <CloudOff className="mb-3 h-10 w-10" />
                                    <p className="text-sm">No servers yet</p>
                                    <p className="text-muted-foreground mt-1 text-xs">
                                        Add your first server to get started.
                                    </p>
                                </div>
                            ) : (
                                <div className="space-y-3">
                                    {servers.map((server) => {
                                        const memPct =
                                            server.mem_total && server.mem_used
                                                ? Math.round((server.mem_used / server.mem_total) * 100)
                                                : null;
                                        const diskPct =
                                            server.disk_total && server.disk_used
                                                ? Math.round((server.disk_used / server.disk_total) * 100)
                                                : null;

                                        return (
                                            <Link
                                                key={server.id}
                                                href={`/servers/${server.id}`}
                                                className="hover:bg-muted/50 flex items-center justify-between rounded-lg border p-3 transition-colors"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <Badge variant={statusColor(server.status) as 'success' | 'destructive' | 'secondary'}>
                                                        {server.status}
                                                    </Badge>
                                                    <div>
                                                        <p className="font-medium">{server.name}</p>
                                                        {server.public_ip && (
                                                            <p className="text-muted-foreground text-xs">{server.public_ip}</p>
                                                        )}
                                                    </div>
                                                </div>
                                                {server.cpu_percent !== null ? (
                                                    <div className="flex items-center gap-4 text-sm">
                                                        <div className="text-center">
                                                            <p className="text-muted-foreground text-xs">CPU</p>
                                                            <p className="font-mono font-medium">{server.cpu_percent.toFixed(1)}%</p>
                                                        </div>
                                                        <div className="text-center">
                                                            <p className="text-muted-foreground text-xs">RAM</p>
                                                            <p className="font-mono font-medium">{memPct}%</p>
                                                        </div>
                                                        <div className="text-center">
                                                            <p className="text-muted-foreground text-xs">Disk</p>
                                                            <p className="font-mono font-medium">{diskPct}%</p>
                                                        </div>
                                                        <div className="text-center">
                                                            <p className="text-muted-foreground text-xs">Load</p>
                                                            <p className="font-mono font-medium">{server.load1?.toFixed(2)}</p>
                                                        </div>
                                                        <ArrowRight className="text-muted-foreground h-4 w-4" />
                                                    </div>
                                                ) : (
                                                    <span className="text-muted-foreground text-xs">No data</span>
                                                )}
                                            </Link>
                                        );
                                    })}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Sidebar: Recent Activity + Deployments */}
                    <div className="space-y-4">
                        {/* Recent Deployments */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Rocket className="h-4 w-4" />
                                    Recent Deployments
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {recentDeployments.length === 0 ? (
                                    <p className="text-muted-foreground text-xs text-center py-4">No deployments yet</p>
                                ) : (
                                    <div className="space-y-2">
                                        {recentDeployments.map((dep) => (
                                            <div
                                                key={dep.id}
                                                className="flex items-center justify-between rounded-md border px-2 py-1.5 text-xs"
                                            >
                                                <div className="flex items-center gap-2">
                                                    <Badge variant={deployStatusColor(dep.status) as 'success' | 'destructive' | 'default' | 'secondary'}>
                                                        {dep.status}
                                                    </Badge>
                                                    <span className="font-medium">{dep.application?.name ?? '—'}</span>
                                                    <span className="text-muted-foreground">{dep.branch}</span>
                                                </div>
                                                <span className="text-muted-foreground">
                                                    {dep.started_at ? formatTime(dep.started_at) : '—'}
                                                </span>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        {/* Recent Activity */}
                        <Card>
                            <CardHeader>
                                <CardTitle className="flex items-center gap-2 text-sm">
                                    <Activity className="h-4 w-4" />
                                    Recent Activity
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                {recentActivity.length === 0 ? (
                                    <p className="text-muted-foreground text-xs text-center py-4">No activity yet</p>
                                ) : (
                                    <div className="space-y-2">
                                        {recentActivity.map((entry) => (
                                            <div
                                                key={entry.id}
                                                className="rounded-md border px-2 py-1.5 text-xs"
                                            >
                                                <div className="flex items-center justify-between">
                                                    <span className="font-medium">{entry.action}</span>
                                                    <span className="text-muted-foreground">
                                                        {formatTime(entry.created_at)}
                                                    </span>
                                                </div>
                                                {entry.description && (
                                                    <p className="text-muted-foreground mt-0.5">{entry.description}</p>
                                                )}
                                                {entry.user && (
                                                    <p className="text-muted-foreground mt-0.5">by {entry.user.name}</p>
                                                )}
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
