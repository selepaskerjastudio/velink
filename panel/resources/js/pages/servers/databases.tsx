import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
import { type AgentJob, type BreadcrumbItem, type DatabaseEngine, type DatabaseInstanceSummary, type DatabaseUserSummary } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { EllipsisIcon, PlusIcon, SearchIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const ENGINE_LABELS: Record<DatabaseEngine, string> = {
    mysql: 'MySQL',
    mariadb: 'MariaDB',
    postgres: 'PostgreSQL',
    mongodb: 'MongoDB',
};

// MySQL is intentionally not offered — the stack ships MariaDB.
const ENGINES: DatabaseEngine[] = ['mariadb', 'postgres', 'mongodb'];

type SortKey = 'name_asc' | 'name_desc';

interface DbServer {
    id: string;
    name: string;
    public_ip: string | null;
    status: string;
}

function DbPageTabs({ serverId, active }: { serverId: string; active: 'databases' | 'users' }) {
    const tab = 'px-4 py-2 text-sm font-medium';
    const on = 'border-primary text-primary border-b-2';
    const off = 'text-muted-foreground hover:text-foreground';
    return (
        <div className="flex border-b">
            <Link href={route('databases.index', serverId)} className={`${tab} ${active === 'databases' ? on : off}`}>
                Databases
            </Link>
            <Link href={route('database-users.index', serverId)} className={`${tab} ${active === 'users' ? on : off}`}>
                Database Users
            </Link>
        </div>
    );
}

function AddDatabaseDialog({ serverId, engine }: { serverId: string; engine: DatabaseEngine }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ name: string; charset: string; collation: string }>({ name: '', charset: '', collation: '' });

    const showCharset = engine !== 'mongodb';
    const showCollation = engine === 'mariadb';

    const submit = () => {
        form.transform((data) => ({ ...data, engine }));
        form.post(route('databases.store', serverId), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <PlusIcon className="mr-1.5 h-4 w-4" />
                    Add New Database
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>New {ENGINE_LABELS[engine]} Database</DialogTitle>
                    <DialogDescription>Create a new database/schema on this server.</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 py-2">
                    <div className="grid gap-2">
                        <Label htmlFor="db-name">Database name</Label>
                        <Input
                            id="db-name"
                            autoFocus
                            value={form.data.name}
                            onChange={(e) => form.setData('name', e.target.value)}
                            placeholder="my_database"
                            className="font-mono"
                        />
                        <InputError message={form.errors.name} />
                    </div>

                    {showCharset && (
                        <div className="grid gap-2">
                            <Label htmlFor="db-charset">Charset</Label>
                            <Input
                                id="db-charset"
                                value={form.data.charset}
                                onChange={(e) => form.setData('charset', e.target.value)}
                                placeholder={engine === 'postgres' ? 'UTF8' : 'utf8mb4'}
                                className="font-mono"
                            />
                            <InputError message={form.errors.charset} />
                        </div>
                    )}

                    {showCollation && (
                        <div className="grid gap-2">
                            <Label htmlFor="db-collation">Collation</Label>
                            <Input
                                id="db-collation"
                                value={form.data.collation}
                                onChange={(e) => form.setData('collation', e.target.value)}
                                placeholder="utf8mb4_unicode_ci"
                                className="font-mono"
                            />
                            <InputError message={form.errors.collation} />
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing || form.data.name === ''}>
                        {form.processing ? 'Creating…' : 'Create Database'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function EnginePanel({
    engine,
    serverId,
    databases,
    users,
}: {
    engine: DatabaseEngine;
    serverId: string;
    databases: DatabaseInstanceSummary[];
    users: DatabaseUserSummary[];
}) {
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState<SortKey>('name_asc');
    const destroyForm = useForm({});

    const list = databases
        .filter((d) => d.name.toLowerCase().includes(search.toLowerCase()))
        .sort((a, b) => (sort === 'name_desc' ? b.name.localeCompare(a.name) : a.name.localeCompare(b.name)));

    const usersFor = (dbName: string): string[] =>
        users.filter((u) => u.engine === engine && u.grants && dbName in u.grants).map((u) => u.username);

    const drop = (database: DatabaseInstanceSummary) => {
        if (!confirm(`Drop database "${database.name}"? This cannot be undone.`)) return;
        destroyForm.delete(route('databases.destroy', database.id), { preserveScroll: true });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <div className="relative">
                    <SearchIcon className="text-muted-foreground absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2" />
                    <Input
                        placeholder="Search..."
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        className="w-56 pl-8"
                    />
                </div>
                <Select value={sort} onValueChange={(v) => setSort(v as SortKey)}>
                    <SelectTrigger className="w-44">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="name_asc">Sort By: Name (Asc)</SelectItem>
                        <SelectItem value="name_desc">Sort By: Name (Desc)</SelectItem>
                    </SelectContent>
                </Select>
                <div className="ml-auto">
                    <AddDatabaseDialog serverId={serverId} engine={engine} />
                </div>
            </div>

            <Card>
                <CardContent className="p-0">
                    {list.length === 0 ? (
                        <p className="text-muted-foreground px-4 py-10 text-center text-sm">
                            {databases.length === 0
                                ? `No ${ENGINE_LABELS[engine]} databases yet.`
                                : 'No databases match your search.'}
                        </p>
                    ) : (
                        <table className="w-full">
                            <thead>
                                <tr className="border-b bg-muted/30">
                                    <th className="py-2.5 pl-4 pr-2 text-left text-xs font-medium text-muted-foreground">Database Name</th>
                                    <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Database User</th>
                                    <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Collation</th>
                                    <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Added On</th>
                                    <th className="py-2.5 pl-2 pr-4" />
                                </tr>
                            </thead>
                            <tbody>
                                {list.map((database) => {
                                    const dbUsers = usersFor(database.name);
                                    return (
                                        <tr key={database.id} className="border-b last:border-0 hover:bg-muted/30 transition-colors">
                                            <td className="py-3 pl-4 pr-2 font-mono text-sm">{database.name}</td>
                                            <td className="px-4 py-3 text-sm">
                                                {dbUsers.length > 0 ? (
                                                    <span className="font-mono text-xs">{dbUsers.join(', ')}</span>
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-3 text-sm text-muted-foreground">{database.collation ?? '—'}</td>
                                            <td className="px-4 py-3 text-sm text-muted-foreground">{database.created_at ?? '—'}</td>
                                            <td className="py-3 pl-2 pr-4 text-right">
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button variant="ghost" size="icon" className="h-8 w-8" disabled={destroyForm.processing}>
                                                            <EllipsisIcon className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end">
                                                        <DropdownMenuItem
                                                            onClick={() => drop(database)}
                                                            className="text-destructive focus:text-destructive"
                                                        >
                                                            Drop database
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

export default function ServersDatabases({
    server,
    databases,
    databaseUsers,
}: {
    server: DbServer;
    databases: DatabaseInstanceSummary[];
    databaseUsers: DatabaseUserSummary[];
    jobs: AgentJob[];
}) {
    const [engine, setEngine] = useState<DatabaseEngine>('mariadb');
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);
        channel.listen('.agent-job.updated', () => {
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
            reloadTimer.current = setTimeout(() => router.reload({ only: ['databases', 'databaseUsers'] }), 800);
        });
        return () => {
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Databases', href: `/servers/${server.id}/databases` },
    ];

    // Legacy MySQL rows (if any) are shown under the MariaDB tab.
    const forEngine = (e: DatabaseEngine) =>
        databases.filter((d) => d.engine === e || (e === 'mariadb' && d.engine === 'mysql'));

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`Databases — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-bold tracking-tight">Databases</h1>

                <DbPageTabs serverId={server.id} active="databases" />

                <Tabs value={engine} onValueChange={(v) => setEngine(v as DatabaseEngine)}>
                    <TabsList>
                        {ENGINES.map((e) => (
                            <TabsTrigger key={e} value={e}>
                                {ENGINE_LABELS[e]}
                                <span className="text-muted-foreground ml-1.5 text-xs">{forEngine(e).length}</span>
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    {ENGINES.map((e) => (
                        <TabsContent key={e} value={e} className="mt-4">
                            <EnginePanel engine={e} serverId={server.id} databases={forEngine(e)} users={databaseUsers} />
                        </TabsContent>
                    ))}
                </Tabs>
            </div>
        </ServerLayout>
    );
}
