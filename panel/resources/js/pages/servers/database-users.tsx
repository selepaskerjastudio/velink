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
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { EllipsisIcon, PlusIcon, SearchIcon, TriangleAlertIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const ENGINE_LABELS: Record<DatabaseEngine, string> = {
    mysql: 'MySQL',
    mariadb: 'MariaDB',
    postgres: 'PostgreSQL',
    mongodb: 'MongoDB',
};

const ENGINES: DatabaseEngine[] = ['mariadb', 'postgres', 'mongodb'];

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

function EngineUsersPanel({
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

export default function ServerDatabaseUsers({
    server,
    databaseUsers,
    databases,
}: {
    server: DbServer;
    databaseUsers: DatabaseUserSummary[];
    databases: DatabaseInstanceSummary[];
    jobs: AgentJob[];
}) {
    const { flash } = usePage<SharedData>().props;
    const [engine, setEngine] = useState<DatabaseEngine>('mariadb');
    const reloadTimer = useRef<ReturnType<typeof setTimeout> | null>(null);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);
        channel.listen('.agent-job.updated', () => {
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
            reloadTimer.current = setTimeout(() => router.reload({ only: ['databaseUsers', 'databases'] }), 800);
        });
        return () => {
            if (reloadTimer.current) clearTimeout(reloadTimer.current);
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Database users', href: `/servers/${server.id}/database-users` },
    ];

    const usersForEngine = (e: DatabaseEngine) => databaseUsers.filter((u) => u.engine === e || (e === 'mariadb' && u.engine === 'mysql'));
    const dbsForEngine = (e: DatabaseEngine) => databases.filter((d) => d.engine === e || (e === 'mariadb' && d.engine === 'mysql'));

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`Database users — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <h1 className="text-2xl font-bold tracking-tight">Databases</h1>

                <DbPageTabs serverId={server.id} active="users" />

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

                <Tabs value={engine} onValueChange={(v) => setEngine(v as DatabaseEngine)}>
                    <TabsList>
                        {ENGINES.map((e) => (
                            <TabsTrigger key={e} value={e}>
                                {ENGINE_LABELS[e]}
                                <span className="text-muted-foreground ml-1.5 text-xs">{usersForEngine(e).length}</span>
                            </TabsTrigger>
                        ))}
                    </TabsList>
                    {ENGINES.map((e) => (
                        <TabsContent key={e} value={e} className="mt-4">
                            <EngineUsersPanel engine={e} serverId={server.id} users={usersForEngine(e)} databases={dbsForEngine(e)} />
                        </TabsContent>
                    ))}
                </Tabs>
            </div>
        </ServerLayout>
    );
}
