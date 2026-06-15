import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Server } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { PlusIcon, ServerIcon } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Servers',
        href: '/servers',
    },
];

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

export default function ServersIndex({ servers }: { servers: Server[] }) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Servers" />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">Servers</h1>
                    <Button asChild>
                        <Link href={route('servers.create')}>
                            <PlusIcon />
                            Add Server
                        </Link>
                    </Button>
                </div>

                {servers.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-2 py-12 text-center">
                            <ServerIcon className="text-muted-foreground size-10" />
                            <p className="text-muted-foreground text-sm">No servers yet. Add your first server to get started.</p>
                            <Button asChild className="mt-2">
                                <Link href={route('servers.create')}>
                                    <PlusIcon />
                                    Add Server
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                ) : (
                    <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                        {servers.map((server) => (
                            <Link key={server.id} href={route('servers.show', server.id)}>
                                <Card className="hover:bg-muted/50 transition-colors">
                                    <CardContent className="flex flex-col gap-2">
                                        <div className="flex items-center justify-between">
                                            <span className="font-medium">{server.name}</span>
                                            <Badge variant={statusVariant(server.status)}>{server.status}</Badge>
                                        </div>
                                        <div className="text-muted-foreground text-sm">
                                            {server.public_ip ?? server.hostname ?? 'No address yet'}
                                        </div>
                                        {server.os && <div className="text-muted-foreground text-xs">{server.os}</div>}
                                    </CardContent>
                                </Card>
                            </Link>
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
