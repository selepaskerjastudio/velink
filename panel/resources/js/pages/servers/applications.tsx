import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import ServerLayout from '@/layouts/server-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { GlobeIcon, PlusIcon, SearchIcon } from 'lucide-react';
import { useState } from 'react';

interface AppRow {
    id: string;
    name: string;
    domain: string | null;
    php_version: string;
    linux_user: string;
    status: string;
}

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' | 'success' {
    switch (status) {
        case 'active':
        case 'running':
            return 'success';
        case 'failed':
            return 'destructive';
        case 'provisioning':
            return 'outline';
        default:
            return 'secondary';
    }
}

export default function ServersApplications({
    server,
    applications,
}: {
    server: { id: string; name: string; public_ip: string | null; status: string };
    applications: AppRow[];
}) {
    const [search, setSearch] = useState('');

    const filtered = applications.filter((app) => {
        if (!search.trim()) return true;
        const q = search.toLowerCase();
        return app.name.toLowerCase().includes(q) || (app.domain ?? '').toLowerCase().includes(q);
    });

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Web Applications', href: `/servers/${server.id}/applications` },
    ];

    const isOnline = server.status === 'online';

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`Web Applications — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                {/* Page header */}
                <div className="flex items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">Web Applications</h1>
                        <p className="text-muted-foreground mt-0.5 text-sm">
                            PHP/Laravel apps hosted on <span className="font-medium">{server.name}</span>.
                        </p>
                    </div>
                    {isOnline ? (
                        <Button asChild size="sm">
                            <Link href={route('applications.create', server.id)}>
                                <PlusIcon className="mr-1.5 h-4 w-4" />
                                Deploy New Web App
                            </Link>
                        </Button>
                    ) : (
                        <Button size="sm" disabled>
                            <PlusIcon className="mr-1.5 h-4 w-4" />
                            Deploy New Web App
                        </Button>
                    )}
                </div>

                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex items-center justify-between gap-4">
                            <CardTitle className="text-base">
                                {applications.length === 0
                                    ? 'No applications'
                                    : `${applications.length} application${applications.length === 1 ? '' : 's'}`}
                            </CardTitle>
                            {applications.length > 0 && (
                                <div className="relative max-w-xs flex-1">
                                    <SearchIcon className="text-muted-foreground absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2" />
                                    <Input
                                        placeholder="Search by name or domain…"
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        className="pl-8 text-sm"
                                    />
                                </div>
                            )}
                        </div>
                    </CardHeader>

                    <CardContent className="p-0">
                        {applications.length === 0 ? (
                            /* Empty state */
                            <div className="flex flex-col items-center gap-3 px-6 py-12 text-center">
                                <GlobeIcon className="text-muted-foreground h-10 w-10" />
                                <div>
                                    <p className="font-medium">No web applications yet</p>
                                    <p className="text-muted-foreground mt-1 text-sm">
                                        Deploy your first PHP application on this server.
                                    </p>
                                </div>
                                {isOnline && (
                                    <Button asChild size="sm" className="mt-1">
                                        <Link href={route('applications.create', server.id)}>
                                            <PlusIcon className="mr-1.5 h-4 w-4" />
                                            Deploy New Web App
                                        </Link>
                                    </Button>
                                )}
                            </div>
                        ) : filtered.length === 0 ? (
                            <p className="text-muted-foreground px-6 py-6 text-sm">No applications match your search.</p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b">
                                        <th className="text-muted-foreground px-6 py-3 text-left font-medium">Name</th>
                                        <th className="text-muted-foreground px-6 py-3 text-left font-medium">Domain / URL</th>
                                        <th className="text-muted-foreground px-6 py-3 text-left font-medium">PHP Version</th>
                                        <th className="text-muted-foreground px-6 py-3 text-left font-medium">Owner</th>
                                        <th className="text-muted-foreground px-6 py-3 text-left font-medium">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {filtered.map((app) => (
                                        <tr key={app.id} className="hover:bg-muted/50 border-b last:border-0 transition-colors">
                                            <td className="px-6 py-3">
                                                <Link
                                                    href={route('applications.show', app.id)}
                                                    className="font-medium hover:underline"
                                                >
                                                    {app.name}
                                                </Link>
                                            </td>
                                            <td className="px-6 py-3">
                                                {app.domain ? (
                                                    <span className="text-muted-foreground font-mono text-xs">{app.domain}</span>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="px-6 py-3">
                                                <Badge variant="outline">PHP {app.php_version}</Badge>
                                            </td>
                                            <td className="px-6 py-3">
                                                <span className="text-muted-foreground font-mono text-xs">{app.linux_user}</span>
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
            </div>
        </ServerLayout>
    );
}
