import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
import {
    type AgentJob,
    type AgentJobStatus,
    type BreadcrumbItem,
    type DatabaseEngine,
    type DatabaseGrants,
    type DatabaseInstanceSummary,
    type DatabaseUserSummary,
    type SharedData,
} from '@/types';
import { Head, useForm, usePage } from '@inertiajs/react';
import { ChevronDownIcon, TriangleAlertIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

const ENGINE_LABELS: Record<DatabaseEngine, string> = {
    mysql: 'MySQL',
    mariadb: 'MariaDB',
    postgres: 'PostgreSQL',
    mongodb: 'MongoDB',
};

const ENGINES: DatabaseEngine[] = ['mysql', 'mariadb', 'postgres', 'mongodb'];

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'succeeded':
            return 'default';
        case 'failed':
        case 'timeout':
            return 'destructive';
        case 'running':
            return 'outline';
        default:
            return 'secondary';
    }
}

interface AgentJobUpdatedEvent {
    uuid: string;
    type: string;
    label: string | null;
    status: AgentJobStatus;
    exit_code: number | null;
    output: string | null;
}

function GrantsEditor({
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
        <div className="grid gap-2">
            {databases.map((database) => (
                <div key={database.id} className="flex items-center gap-2">
                    <Checkbox
                        id={`grant-${database.id}`}
                        checked={database.name in grants}
                        onCheckedChange={(checked) => onToggle(database.name, checked === true)}
                    />
                    <Label htmlFor={`grant-${database.id}`} className="font-mono text-sm">
                        {database.name}
                    </Label>
                </div>
            ))}
        </div>
    );
}

function DatabaseUserRow({ user, databases }: { user: DatabaseUserSummary; databases: DatabaseInstanceSummary[] }) {
    const grantsForm = useForm<{ grants: DatabaseGrants }>({
        grants: user.grants ?? {},
    });

    const deleteForm = useForm({});

    const engineDatabases = databases.filter((database) => database.engine === user.engine);

    const toggleGrant = (database: string, checked: boolean) => {
        const next = { ...grantsForm.data.grants };
        if (checked) {
            next[database] = ['ALL'];
        } else {
            delete next[database];
        }
        grantsForm.setData('grants', next);
    };

    const submitGrants = () => {
        grantsForm.patch(route('database-users.grants', user.id), { preserveScroll: true });
    };

    const submitDelete = () => {
        if (!confirm(`Delete database user "${user.username}@${user.host}"? This cannot be undone.`)) {
            return;
        }
        deleteForm.delete(route('database-users.destroy', user.id), { preserveScroll: true });
    };

    const grantedDatabases = Object.keys(user.grants ?? {});

    return (
        <Collapsible>
            <div className="flex items-center justify-between rounded-md border px-3 py-2">
                <div className="flex flex-col">
                    <div className="flex items-center gap-2">
                        <Badge variant="outline">{ENGINE_LABELS[user.engine]}</Badge>
                        <span className="font-mono text-sm">
                            {user.username}@{user.host}
                        </span>
                    </div>
                    <span className="text-muted-foreground text-xs">
                        {grantedDatabases.length > 0 ? `Access: ${grantedDatabases.join(', ')}` : 'No database access granted'}
                    </span>
                </div>
                <div className="flex items-center gap-2">
                    <CollapsibleTrigger asChild>
                        <Button variant="ghost" size="icon" className="h-7 w-7">
                            <ChevronDownIcon />
                        </Button>
                    </CollapsibleTrigger>
                    <Button variant="destructive" size="sm" onClick={submitDelete} disabled={deleteForm.processing}>
                        Delete
                    </Button>
                </div>
            </div>
            <CollapsibleContent>
                <div className="mt-2 grid gap-3 rounded-md border p-3">
                    <Label>Database access</Label>
                    <GrantsEditor databases={engineDatabases} grants={grantsForm.data.grants} onToggle={toggleGrant} />
                    <div>
                        <Button size="sm" onClick={submitGrants} disabled={grantsForm.processing}>
                            Save grants
                        </Button>
                    </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
    );
}

export default function ServerDatabaseUsers({
    server,
    databaseUsers,
    databases,
    jobs,
}: {
    server: { id: string; name: string; public_ip: string | null; status: string };
    databaseUsers: DatabaseUserSummary[];
    databases: DatabaseInstanceSummary[];
    jobs: AgentJob[];
}) {
    const { flash } = usePage<SharedData>().props;
    const [liveJobs, setLiveJobs] = useState<AgentJob[]>(jobs);

    useEffect(() => setLiveJobs(jobs), [jobs]);

    useEffect(() => {
        const channel = echo.private(`server.${server.id}`);

        channel.listen('.agent-job.updated', (event: AgentJobUpdatedEvent) => {
            setLiveJobs((current) => {
                const index = current.findIndex((job) => job.uuid === event.uuid);
                if (index === -1) {
                    return current;
                }
                const next = [...current];
                next[index] = { ...next[index], ...event };
                return next;
            });
        });

        return () => {
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const form = useForm<{
        engine: DatabaseEngine;
        username: string;
        host: string;
        grants: DatabaseGrants;
    }>({
        engine: 'mysql',
        username: '',
        host: '%',
        grants: {},
    });

    const toggleGrant = (database: string, checked: boolean) => {
        const next = { ...form.data.grants };
        if (checked) {
            next[database] = ['ALL'];
        } else {
            delete next[database];
        }
        form.setData('grants', next);
    };

    const submitCreate = () => {
        form.post(route('database-users.store', server.id), {
            preserveScroll: true,
            onSuccess: () => form.reset('username', 'grants'),
        });
    };

    const engineDatabases = databases.filter((database) => database.engine === form.data.engine);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Database users', href: `/servers/${server.id}/database-users` },
    ];

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`Database users — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Database users</h1>

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

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Database users</CardTitle>
                        <CardDescription>Users that can connect to databases on this server.</CardDescription>
                    </CardHeader>
                    {databaseUsers.length > 0 && (
                        <CardContent className="grid gap-2">
                            {databaseUsers.map((user) => (
                                <DatabaseUserRow key={user.id} user={user} databases={databases} />
                            ))}
                        </CardContent>
                    )}
                    {databaseUsers.length === 0 && (
                        <CardContent>
                            <p className="text-muted-foreground text-sm">No database users yet.</p>
                        </CardContent>
                    )}
                </Card>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Create database user</CardTitle>
                        <CardDescription>The password is generated automatically and shown once after creation.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="engine">Engine</Label>
                            <Select
                                value={form.data.engine}
                                onValueChange={(value) => form.setData((data) => ({ ...data, engine: value as DatabaseEngine, grants: {} }))}
                            >
                                <SelectTrigger id="engine" className="max-w-60">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {ENGINES.map((engine) => (
                                        <SelectItem key={engine} value={engine}>
                                            {ENGINE_LABELS[engine]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {form.errors.engine && <p className="text-sm text-destructive">{form.errors.engine}</p>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="username">Username</Label>
                            <Input
                                id="username"
                                value={form.data.username}
                                onChange={(e) => form.setData('username', e.target.value)}
                                placeholder="my_app_user"
                                className="max-w-60 font-mono"
                            />
                            {form.errors.username && <p className="text-sm text-destructive">{form.errors.username}</p>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="host">Host</Label>
                            <Input
                                id="host"
                                value={form.data.host}
                                onChange={(e) => form.setData('host', e.target.value)}
                                placeholder="%"
                                className="max-w-60 font-mono"
                            />
                            {form.errors.host && <p className="text-sm text-destructive">{form.errors.host}</p>}
                        </div>

                        <div className="grid gap-2">
                            <Label>Database access</Label>
                            <GrantsEditor databases={engineDatabases} grants={form.data.grants} onToggle={toggleGrant} />
                            {form.errors.grants && <p className="text-sm text-destructive">{form.errors.grants}</p>}
                        </div>
                    </CardContent>
                    <CardFooter>
                        <Button onClick={submitCreate} disabled={form.processing || form.data.username === ''}>
                            Create database user
                        </Button>
                    </CardFooter>
                </Card>

                {liveJobs.length > 0 && (
                    <Card className="max-w-xl">
                        <CardHeader>
                            <CardTitle>Job progress</CardTitle>
                            <CardDescription>Live status of database user jobs dispatched to this server.</CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-2">
                            {liveJobs.map((job) => (
                                <Collapsible key={job.uuid}>
                                    <div className="flex items-center justify-between rounded-md border px-3 py-2">
                                        <div className="flex items-center gap-2">
                                            <span className="text-sm font-medium">{job.label ?? job.type}</span>
                                            {job.exit_code !== null && (
                                                <span className="text-muted-foreground text-xs">exit {job.exit_code}</span>
                                            )}
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Badge variant={statusVariant(job.status)}>{job.status}</Badge>
                                            {job.output && (
                                                <CollapsibleTrigger asChild>
                                                    <Button variant="ghost" size="icon" className="h-7 w-7">
                                                        <ChevronDownIcon />
                                                    </Button>
                                                </CollapsibleTrigger>
                                            )}
                                        </div>
                                    </div>
                                    {job.output && (
                                        <CollapsibleContent>
                                            <pre className="bg-muted mt-1 max-h-64 overflow-auto rounded-md p-3 text-xs whitespace-pre-wrap">
                                                {job.output}
                                            </pre>
                                        </CollapsibleContent>
                                    )}
                                </Collapsible>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>
        </ServerLayout>
    );
}
