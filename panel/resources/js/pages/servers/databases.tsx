import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
import {
    type AgentJob,
    type BreadcrumbItem,
    type DatabaseEngine,
    type DatabaseGrants,
    type DatabaseInstanceSummary,
    type DatabaseUserSummary,
    type SharedData,
} from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { DatabaseIcon, EllipsisIcon, PlusIcon, SearchIcon, TriangleAlertIcon } from 'lucide-react';
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

// ─── Databases ──────────────────────────────────────────────────────────────

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

function DatabasesPanel({
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
        users.filter((u) => u.grants && dbName in u.grants).map((u) => u.username);

    const drop = (database: DatabaseInstanceSummary) => {
        if (!confirm(`Drop database "${database.name}"? This cannot be undone.`)) return;
        destroyForm.delete(route('databases.destroy', database.id), { preserveScroll: true });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <div className="relative">
                    <SearchIcon className="text-muted-foreground absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2" />
                    <Input placeholder="Search..." value={search} onChange={(e) => setSearch(e.target.value)} className="w-56 pl-8" />
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
                            {databases.length === 0 ? `No ${ENGINE_LABELS[engine]} databases yet.` : 'No databases match your search.'}
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
                                                        <DropdownMenuItem onClick={() => drop(database)} className="text-destructive focus:text-destructive">
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

// ─── Database users ─────────────────────────────────────────────────────────

function GrantsChecklist({
    databases,
    grants,
    onToggle,
}: {
    databases: DatabaseInstanceSummary[];
    grants: DatabaseGrants;
    onToggle: (database: string, checked: boolean) => void;
}) {
    if (databases.length === 0) {
        return <p className="text-muted-foreground text-sm">No databases on this server yet for this engine.</p>;
    }
    return (
        <div className="grid max-h-48 gap-2 overflow-y-auto">
            {databases.map((database) => (
                <div key={database.id} className="flex items-center gap-2">
                    <Checkbox
                        id={`grant-${database.id}`}
                        checked={database.name in grants}
                        onCheckedChange={(checked) => onToggle(database.name, checked === true)}
                    />
                    <Label htmlFor={`grant-${database.id}`} className="cursor-pointer font-mono text-sm font-normal">
                        {database.name}
                    </Label>
                </div>
            ))}
        </div>
    );
}

function AddUserDialog({ serverId, engine, databases }: { serverId: string; engine: DatabaseEngine; databases: DatabaseInstanceSummary[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ username: string; host: string; grants: DatabaseGrants }>({ username: '', host: '%', grants: {} });

    const toggle = (db: string, checked: boolean) => {
        const next = { ...form.data.grants };
        if (checked) next[db] = ['ALL'];
        else delete next[db];
        form.setData('grants', next);
    };

    const submit = () => {
        form.transform((data) => ({ ...data, engine }));
        form.post(route('database-users.store', serverId), {
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
                    Add New User
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>New {ENGINE_LABELS[engine]} User</DialogTitle>
                    <DialogDescription>The password is generated automatically and shown once after creation.</DialogDescription>
                </DialogHeader>

                <div className="grid gap-4 py-2">
                    <div className="grid gap-2">
                        <Label htmlFor="u-name">Username</Label>
                        <Input
                            id="u-name"
                            autoFocus
                            value={form.data.username}
                            onChange={(e) => form.setData('username', e.target.value)}
                            placeholder="my_app_user"
                            className="font-mono"
                        />
                        <InputError message={form.errors.username} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="u-host">Host</Label>
                        <Input
                            id="u-host"
                            value={form.data.host}
                            onChange={(e) => form.setData('host', e.target.value)}
                            placeholder="%"
                            className="font-mono"
                        />
                        <InputError message={form.errors.host} />
                    </div>
                    <div className="grid gap-2">
                        <Label>Database access</Label>
                        <GrantsChecklist databases={databases} grants={form.data.grants} onToggle={toggle} />
                        <InputError message={form.errors.grants} />
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={() => setOpen(false)} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing || form.data.username === ''}>
                        {form.processing ? 'Creating…' : 'Create User'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function ManageAccessDialog({
    user,
    databases,
    open,
    onOpenChange,
}: {
    user: DatabaseUserSummary;
    databases: DatabaseInstanceSummary[];
    open: boolean;
    onOpenChange: (v: boolean) => void;
}) {
    const form = useForm<{ grants: DatabaseGrants }>({ grants: user.grants ?? {} });

    const toggle = (db: string, checked: boolean) => {
        const next = { ...form.data.grants };
        if (checked) next[db] = ['ALL'];
        else delete next[db];
        form.setData('grants', next);
    };

    const submit = () => {
        form.patch(route('database-users.grants', user.id), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Database access</DialogTitle>
                    <DialogDescription>
                        Grant <span className="font-mono">{user.username}</span> access to databases.
                    </DialogDescription>
                </DialogHeader>
                <div className="py-2">
                    <GrantsChecklist databases={databases} grants={form.data.grants} onToggle={toggle} />
                </div>
                <DialogFooter>
                    <Button variant="outline" onClick={() => onOpenChange(false)} disabled={form.processing}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={form.processing}>
                        {form.processing ? 'Saving…' : 'Save grants'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function UserRow({ user, databases }: { user: DatabaseUserSummary; databases: DatabaseInstanceSummary[] }) {
    const [managing, setManaging] = useState(false);
    const deleteForm = useForm({});
    const granted = Object.keys(user.grants ?? {});

    const remove = () => {
        if (!confirm(`Delete database user "${user.username}@${user.host}"? This cannot be undone.`)) return;
        deleteForm.delete(route('database-users.destroy', user.id), { preserveScroll: true });
    };

    return (
        <tr className="border-b last:border-0 hover:bg-muted/30 transition-colors">
            <td className="py-3 pl-4 pr-2 font-mono text-sm">{user.username}</td>
            <td className="px-4 py-3 font-mono text-sm text-muted-foreground">{user.host}</td>
            <td className="px-4 py-3 text-sm">
                {granted.length > 0 ? (
                    <span className="font-mono text-xs">{granted.join(', ')}</span>
                ) : (
                    <span className="text-muted-foreground">No access granted</span>
                )}
            </td>
            <td className="py-3 pl-2 pr-4 text-right">
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-8 w-8" disabled={deleteForm.processing}>
                            <EllipsisIcon className="h-4 w-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem onClick={() => setManaging(true)}>Manage access</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem onClick={remove} className="text-destructive focus:text-destructive">
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <ManageAccessDialog user={user} databases={databases} open={managing} onOpenChange={setManaging} />
            </td>
        </tr>
    );
}

function UsersPanel({
    engine,
    serverId,
    users,
    databases,
}: {
    engine: DatabaseEngine;
    serverId: string;
    users: DatabaseUserSummary[];
    databases: DatabaseInstanceSummary[];
}) {
    const [search, setSearch] = useState('');
    const list = users.filter((u) => u.username.toLowerCase().includes(search.toLowerCase()));

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center gap-3">
                <div className="relative">
                    <SearchIcon className="text-muted-foreground absolute left-2.5 top-1/2 h-4 w-4 -translate-y-1/2" />
                    <Input placeholder="Search..." value={search} onChange={(e) => setSearch(e.target.value)} className="w-56 pl-8" />
                </div>
                <div className="ml-auto">
                    <AddUserDialog serverId={serverId} engine={engine} databases={databases} />
                </div>
            </div>

            <Card>
                <CardContent className="p-0">
                    {list.length === 0 ? (
                        <p className="text-muted-foreground px-4 py-10 text-center text-sm">
                            {users.length === 0 ? `No ${ENGINE_LABELS[engine]} users yet.` : 'No users match your search.'}
                        </p>
                    ) : (
                        <table className="w-full">
                            <thead>
                                <tr className="border-b bg-muted/30">
                                    <th className="py-2.5 pl-4 pr-2 text-left text-xs font-medium text-muted-foreground">Username</th>
                                    <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Host</th>
                                    <th className="px-4 py-2.5 text-left text-xs font-medium text-muted-foreground">Database Access</th>
                                    <th className="py-2.5 pl-2 pr-4" />
                                </tr>
                            </thead>
                            <tbody>
                                {list.map((user) => (
                                    <UserRow key={user.id} user={user} databases={databases} />
                                ))}
                            </tbody>
                        </table>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

// ─── Page ───────────────────────────────────────────────────────────────────

function EngineTab({
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
    return (
        <Tabs defaultValue="databases">
            <TabsList>
                <TabsTrigger value="databases">
                    Databases
                    <span className="text-muted-foreground ml-1.5 text-xs">{databases.length}</span>
                </TabsTrigger>
                <TabsTrigger value="users">
                    Database Users
                    <span className="text-muted-foreground ml-1.5 text-xs">{users.length}</span>
                </TabsTrigger>
            </TabsList>
            <TabsContent value="databases" className="mt-4">
                <DatabasesPanel engine={engine} serverId={serverId} databases={databases} users={users} />
            </TabsContent>
            <TabsContent value="users" className="mt-4">
                <UsersPanel engine={engine} serverId={serverId} users={users} databases={databases} />
            </TabsContent>
        </Tabs>
    );
}

export default function ServersDatabases({
    server,
    installedEngines,
    databases,
    databaseUsers,
}: {
    server: DbServer;
    installedEngines: DatabaseEngine[];
    databases: DatabaseInstanceSummary[];
    databaseUsers: DatabaseUserSummary[];
    jobs: AgentJob[];
}) {
    const { flash } = usePage<SharedData>().props;
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);
        channel.listen('.agent-job.updated', () => {
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
            reloadTimer.current = setTimeout(() => router.reload({ only: ['databases', 'databaseUsers', 'installedEngines'] }), 800);
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

    const dbFor = (e: DatabaseEngine) => databases.filter((d) => d.engine === e || (e === 'mariadb' && d.engine === 'mysql'));
    const usersFor = (e: DatabaseEngine) => databaseUsers.filter((u) => u.engine === e || (e === 'mariadb' && u.engine === 'mysql'));

    // Show an engine tab when it is installed, or when it still has data to show.
    const available = ENGINES.filter((e) => installedEngines.includes(e) || dbFor(e).length > 0 || usersFor(e).length > 0);

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`Databases — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-bold tracking-tight">Databases</h1>

                {flash.plainDbUserPassword && (
                    <Alert>
                        <TriangleAlertIcon />
                        <AlertTitle>Database user created</AlertTitle>
                        <AlertDescription>
                            <p>The password below is shown only once — copy it now.</p>
                            {flash.plainDbUserUsername && (
                                <p className="mt-2 text-sm">
                                    Username: <span className="font-mono">{flash.plainDbUserUsername}</span>
                                </p>
                            )}
                            <p className="mt-1 text-sm">
                                Password: <span className="font-mono">{flash.plainDbUserPassword}</span>
                            </p>
                        </AlertDescription>
                    </Alert>
                )}

                {available.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 px-4 py-12 text-center">
                            <DatabaseIcon className="h-8 w-8 text-muted-foreground/50" />
                            <p className="text-sm text-muted-foreground">
                                No database engine installed yet. Install MariaDB, PostgreSQL, or MongoDB from the{' '}
                                <strong>Services</strong> page first.
                            </p>
                        </CardContent>
                    </Card>
                ) : (
                    <Tabs defaultValue={available[0]}>
                        <TabsList>
                            {available.map((e) => (
                                <TabsTrigger key={e} value={e}>
                                    {ENGINE_LABELS[e]}
                                </TabsTrigger>
                            ))}
                        </TabsList>
                        {available.map((e) => (
                            <TabsContent key={e} value={e} className="mt-4">
                                <EngineTab engine={e} serverId={server.id} databases={dbFor(e)} users={usersFor(e)} />
                            </TabsContent>
                        ))}
                    </Tabs>
                )}
            </div>
        </ServerLayout>
    );
}
