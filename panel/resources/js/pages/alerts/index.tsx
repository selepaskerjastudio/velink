import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Cpu, HardDrive, MemoryStick } from 'lucide-react';

interface AlertEntry {
    id: number;
    server_id: string;
    server_name: string;
    metric_type: string;
    value: number;
    threshold: number;
    message: string;
    created_at: string;
}

interface ResolvedEntry {
    id: number;
    server_id: string;
    server_name: string;
    metric_type: string;
    message: string;
    resolved_at: string | null;
}

function metricIcon(type: string) {
    switch (type) {
        case 'cpu':
            return <Cpu className="h-4 w-4 text-blue-500" />;
        case 'memory':
            return <MemoryStick className="h-4 w-4 text-green-500" />;
        case 'disk':
            return <HardDrive className="h-4 w-4 text-yellow-500" />;
        default:
            return <AlertTriangle className="h-4 w-4" />;
    }
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function timeAgo(iso: string): string {
    const diff = Date.now() - new Date(iso).getTime();
    const minutes = Math.floor(diff / 60000);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

export default function Alerts({
    activeAlerts,
    recentResolved,
    activeCount,
}: {
    activeAlerts: AlertEntry[];
    recentResolved: ResolvedEntry[];
    activeCount: number;
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Alerts', href: '/alerts' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Alerts${activeCount > 0 ? ` (${activeCount})` : ''}`} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-2xl font-bold tracking-tight">Server Alerts</h1>
                    <p className="text-muted-foreground text-sm">
                        {activeCount > 0
                            ? `${activeCount} active alert${activeCount > 1 ? 's' : ''} — metrics exceeding thresholds`
                            : 'No active alerts — all servers within normal thresholds.'}
                    </p>
                </div>

                {/* Active Alerts */}
                <Card className={activeCount > 0 ? 'border-destructive/40' : ''}>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <AlertTriangle className={`h-5 w-5 ${activeCount > 0 ? 'text-destructive' : 'text-muted-foreground'}`} />
                            Active Alerts
                            {activeCount > 0 && (
                                <Badge variant="destructive">{activeCount}</Badge>
                            )}
                        </CardTitle>
                        <CardDescription>
                            CPU, memory, and disk usage exceeding 90% threshold
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {activeAlerts.length === 0 ? (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <CheckCircle2 className="mb-3 h-10 w-10 text-green-500" />
                                <p className="text-sm font-medium">All clear</p>
                                <p className="text-muted-foreground mt-1 text-xs">
                                    No servers currently exceeding thresholds.
                                </p>
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {activeAlerts.map((alert) => (
                                    <div
                                        key={alert.id}
                                        className="flex items-center justify-between rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3"
                                    >
                                        <div className="flex items-center gap-3">
                                            {metricIcon(alert.metric_type)}
                                            <div>
                                                <p className="text-sm font-medium">{alert.message}</p>
                                                <div className="text-muted-foreground mt-0.5 flex items-center gap-2 text-xs">
                                                    <Link
                                                        href={`/servers/${alert.server_id}`}
                                                        className="font-medium text-foreground underline decoration-muted-foreground/50 hover:text-primary"
                                                    >
                                                        {alert.server_name}
                                                    </Link>
                                                    <span>·</span>
                                                    <span>Triggered {timeAgo(alert.created_at)}</span>
                                                </div>
                                            </div>
                                        </div>
                                        <Badge variant="destructive">
                                            {alert.value.toFixed(1)}%
                                        </Badge>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* Recently Resolved */}
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <CheckCircle2 className="h-4 w-4 text-green-500" />
                            Recently Resolved
                        </CardTitle>
                        <CardDescription>Alerts that cleared in the last 24 hours</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {recentResolved.length === 0 ? (
                            <p className="text-muted-foreground py-4 text-center text-xs">No resolved alerts recently.</p>
                        ) : (
                            <div className="space-y-2">
                                {recentResolved.map((alert) => (
                                    <div
                                        key={alert.id}
                                        className="flex items-center justify-between rounded-md border px-3 py-2 text-xs"
                                    >
                                        <div className="flex items-center gap-2">
                                            {metricIcon(alert.metric_type)}
                                            <span>{alert.message}</span>
                                            <span className="text-muted-foreground">—</span>
                                            <Link
                                                href={`/servers/${alert.server_id}`}
                                                className="font-medium text-foreground underline decoration-muted-foreground/50 hover:text-primary"
                                            >
                                                {alert.server_name}
                                            </Link>
                                        </div>
                                        <span className="text-muted-foreground">
                                            Resolved {alert.resolved_at ? timeAgo(alert.resolved_at) : '—'}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
