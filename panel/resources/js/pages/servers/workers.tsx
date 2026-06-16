import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem, type WorkerStatus } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'running':
            return 'default';
        case 'stopped':
            return 'destructive';
        default:
            return 'secondary';
    }
}

interface ServerWorkerRow {
    id: number;
    name: string;
    command: string;
    status: WorkerStatus;
    config: { numprocs?: number } | null;
    application: {
        id: string;
        name: string;
    };
}

export default function ServerWorkers({
    server,
    workers,
}: {
    server: { id: string; name: string; public_ip: string | null; status: string };
    workers: ServerWorkerRow[];
}) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Workers', href: `/servers/${server.id}/workers` },
    ];

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`Workers — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Workers</h1>

                {server.status !== 'online' && (
                    <Alert variant="destructive">
                        <TriangleAlertIcon className="h-4 w-4" />
                        <AlertTitle>Server offline</AlertTitle>
                        <AlertDescription>
                            The agent on this server is not connected. Worker control actions will be queued but won't run until the server reconnects.
                        </AlertDescription>
                    </Alert>
                )}

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>Supervisor workers</CardTitle>
                        <CardDescription>All supervisord programs across applications on this server.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        {workers.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No supervisor workers configured. Add workers from an application page.
                            </p>
                        ) : (
                            <div className="grid gap-2">
                                {workers.map((worker) => (
                                    <div
                                        key={worker.id}
                                        className="flex flex-col gap-1 rounded-md border px-3 py-2 sm:flex-row sm:items-center sm:justify-between"
                                    >
                                        <div className="flex flex-col gap-0.5">
                                            <div className="flex items-center gap-2">
                                                <span className="text-sm font-medium">{worker.name}</span>
                                                <span className="text-muted-foreground text-xs">x{worker.config?.numprocs ?? 1}</span>
                                            </div>
                                            <span className="text-muted-foreground font-mono text-xs">{worker.command}</span>
                                            <div className="flex items-center gap-1">
                                                <span className="text-muted-foreground text-xs">App:</span>
                                                <Link
                                                    href={`/applications/${worker.application.id}`}
                                                    className="text-xs underline-offset-2 hover:underline"
                                                >
                                                    {worker.application.name}
                                                </Link>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant={statusVariant(worker.status)}>{worker.status}</Badge>
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={`/applications/${worker.application.id}/workers`}>Manage</Link>
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </ServerLayout>
    );
}
