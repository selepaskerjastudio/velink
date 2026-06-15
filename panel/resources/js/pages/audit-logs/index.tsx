import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Audit log', href: '/audit-logs' }];

interface AuditLogEntry {
    id: number;
    action: string;
    description: string | null;
    ip_address: string | null;
    created_at: string;
    user: { name: string } | null;
    server: { id: string; name: string } | null;
}

function formatDate(dateStr: string): string {
    return new Date(dateStr).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
    });
}

export default function AuditLogsIndex({ logs }: { logs: AuditLogEntry[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit log" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Audit log</h1>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent activity</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        {logs.length === 0 ? (
                            <p className="text-muted-foreground p-4 text-sm">No audit log entries yet.</p>
                        ) : (
                            <div className="divide-y">
                                {logs.map((log) => (
                                    <div key={log.id} className="flex flex-col gap-1 px-4 py-3 sm:flex-row sm:items-start sm:gap-4">
                                        <div className="text-muted-foreground min-w-36 text-xs">{formatDate(log.created_at)}</div>
                                        <div className="flex flex-1 flex-col gap-1">
                                            <div className="flex flex-wrap items-center gap-2">
                                                <Badge variant="outline" className="font-mono text-xs">
                                                    {log.action}
                                                </Badge>
                                                {log.description && <span className="text-sm">{log.description}</span>}
                                            </div>
                                            <div className="text-muted-foreground flex flex-wrap gap-3 text-xs">
                                                {log.user && <span>User: {log.user.name}</span>}
                                                {log.server && <span>Server: {log.server.name}</span>}
                                                {log.ip_address && <span>IP: {log.ip_address}</span>}
                                            </div>
                                        </div>
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
