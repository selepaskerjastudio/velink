import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Server } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { PlusIcon, SearchIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Servers',
        href: '/servers',
    },
];

function statusVariant(status: string): 'success' | 'destructive' | 'secondary' {
    switch (status) {
        case 'online':
            return 'success';
        case 'offline':
            return 'destructive';
        default:
            return 'secondary';
    }
}

function avatarColor(status: string): string {
    switch (status) {
        case 'online':
            return 'bg-green-500';
        case 'offline':
            return 'bg-red-500';
        default:
            return 'bg-gray-400';
    }
}

function ServerAvatar({ name, status, size = 'md' }: { name: string; status: string; size?: 'sm' | 'md' }) {
    const letter = name.charAt(0).toUpperCase();
    const sizeClasses = size === 'sm' ? 'size-9 text-sm' : 'size-10 text-sm';
    return (
        <div className={`${sizeClasses} ${avatarColor(status)} flex shrink-0 items-center justify-center rounded-full font-semibold text-white`}>
            {letter}
        </div>
    );
}

const RECENTLY_VIEWED_KEY = 'velink:recently-viewed';

export default function ServersIndex({ servers }: { servers: Server[] }) {
    const [search, setSearch] = useState('');
    const [recentlyViewedIds, setRecentlyViewedIds] = useState<string[]>([]);

    useEffect(() => {
        try {
            const raw = localStorage.getItem(RECENTLY_VIEWED_KEY);
            if (raw) {
                const ids: string[] = JSON.parse(raw);
                setRecentlyViewedIds(Array.isArray(ids) ? ids : []);
            }
        } catch {
            // ignore parse errors
        }
    }, []);

    const serverMap = new Map(servers.map((s) => [s.id, s]));

    const recentlyViewed = recentlyViewedIds
        .map((id) => serverMap.get(id))
        .filter((s): s is Server => s !== undefined)
        .slice(0, 5);

    const filtered = servers.filter((s) => {
        if (!search.trim()) return true;
        const q = search.toLowerCase();
        return (
            s.name.toLowerCase().includes(q) ||
            (s.public_ip ?? '').toLowerCase().includes(q) ||
            (s.hostname ?? '').toLowerCase().includes(q)
        );
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Servers" />

            <div className="flex h-full flex-1 flex-col gap-6 p-6">
                <h1 className="text-2xl font-bold">Servers</h1>

                {/* Recently Viewed Strip */}
                {recentlyViewed.length > 0 && (
                    <div>
                        <p className="text-muted-foreground mb-3 text-xs font-medium uppercase tracking-wide">Recently Viewed</p>
                        <div className="flex gap-3 overflow-x-auto pb-1">
                            {recentlyViewed.map((server) => (
                                <Link
                                    key={server.id}
                                    href={route('servers.show', server.id)}
                                    className="border-border bg-card hover:bg-muted/50 flex min-w-[180px] shrink-0 items-center gap-3 rounded-lg border p-3 transition-colors"
                                >
                                    <ServerAvatar name={server.name} status={server.status} size="sm" />
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-semibold">{server.name}</p>
                                        <p className="text-muted-foreground truncate text-xs">{server.public_ip ?? '—'}</p>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </div>
                )}

                {/* Search + Add Server */}
                <div className="flex items-center gap-3">
                    <div className="relative flex-1">
                        <SearchIcon className="text-muted-foreground absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <Input
                            placeholder="Search servers by name or IP..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            className="pl-9"
                        />
                    </div>
                    <Button asChild>
                        <Link href={route('servers.create')}>
                            <PlusIcon className="size-4" />
                            Connect a New Server
                        </Link>
                    </Button>
                </div>

                {/* Server Table */}
                {servers.length === 0 ? (
                    <div className="border-border flex flex-col items-center gap-3 rounded-lg border py-16 text-center">
                        <div className="bg-muted flex size-14 items-center justify-center rounded-full">
                            <SearchIcon className="text-muted-foreground size-6" />
                        </div>
                        <p className="font-medium">No servers yet</p>
                        <p className="text-muted-foreground text-sm">Add your first server to get started.</p>
                        <Button asChild className="mt-1">
                            <Link href={route('servers.create')}>
                                <PlusIcon className="size-4" />
                                Connect a New Server
                            </Link>
                        </Button>
                    </div>
                ) : filtered.length === 0 ? (
                    <div className="border-border flex flex-col items-center gap-2 rounded-lg border py-12 text-center">
                        <p className="font-medium">No servers match your search</p>
                        <p className="text-muted-foreground text-sm">Try a different name or IP address.</p>
                    </div>
                ) : (
                    <div className="border-border overflow-hidden rounded-lg border">
                        <table className="w-full">
                            <thead>
                                <tr className="bg-muted/50 border-border border-b">
                                    <th className="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase tracking-wide">
                                        Server
                                    </th>
                                    <th className="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase tracking-wide">
                                        IP Address
                                    </th>
                                    <th className="text-muted-foreground px-4 py-3 text-left text-xs font-medium uppercase tracking-wide">
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-border divide-y">
                                {filtered.map((server) => (
                                    <tr key={server.id} className="hover:bg-muted/30 group transition-colors">
                                        <td className="px-4 py-3">
                                            <Link
                                                href={route('servers.show', server.id)}
                                                className="flex items-center gap-3"
                                            >
                                                <ServerAvatar name={server.name} status={server.status} />
                                                <div>
                                                    <p className="group-hover:text-primary text-sm font-semibold transition-colors">
                                                        {server.name}
                                                    </p>
                                                    <p className="text-muted-foreground text-xs">{server.os ?? '—'}</p>
                                                </div>
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('servers.show', server.id)} className="block">
                                                <span className="text-muted-foreground font-mono text-sm">
                                                    {server.public_ip ?? server.hostname ?? '—'}
                                                </span>
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3">
                                            <Link href={route('servers.show', server.id)} className="block">
                                                <Badge variant={statusVariant(server.status)} className="capitalize">
                                                    {server.status}
                                                </Badge>
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
