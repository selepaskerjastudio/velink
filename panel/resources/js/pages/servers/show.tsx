import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Server, type SharedData } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'online':
            return 'default';
        case 'offline':
            return 'destructive';
        default:
            return 'secondary';
    }
}

export default function ServersShow({ server }: { server: Server }) {
    const { flash } = usePage<SharedData>().props;

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Servers',
            href: '/servers',
        },
        {
            title: server.name,
            href: `/servers/${server.id}`,
        },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={server.name} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">{server.name}</h1>
                    <Badge variant={statusVariant(server.status)}>{server.status}</Badge>
                </div>

                {flash.installCommand && (
                    <Alert>
                        <TriangleAlertIcon />
                        <AlertTitle>Install the agent on this server</AlertTitle>
                        <AlertDescription>
                            <p>
                                Run this command on the target server to install and register the agent. The token below is shown only
                                once — copy it now.
                            </p>
                            <pre className="bg-muted mt-2 overflow-x-auto rounded-md p-3 text-xs">{flash.installCommand}</pre>
                            {flash.plainAgentToken && (
                                <p className="mt-2 text-sm">
                                    Agent token: <span className="font-mono">{flash.plainAgentToken}</span>
                                </p>
                            )}
                        </AlertDescription>
                    </Alert>
                )}

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Server details</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Hostname</span>
                            <span>{server.hostname ?? '—'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Public IP</span>
                            <span>{server.public_ip ?? '—'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Private IP</span>
                            <span>{server.private_ip ?? '—'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">OS</span>
                            <span>{server.os ?? '—'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Agent version</span>
                            <span>{server.agent_version ?? '—'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Last seen</span>
                            <span>{server.last_seen_at ?? 'Never'}</span>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
