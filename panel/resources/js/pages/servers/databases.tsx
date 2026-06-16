import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import echo from '@/echo';
import ServerLayout from '@/layouts/server-layout';
import { type AgentJob, type AgentJobStatus, type BreadcrumbItem, type DatabaseEngine, type DatabaseInstanceSummary } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronDownIcon } from 'lucide-react';
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

export default function ServersDatabases({
    server,
    databases,
    jobs,
}: {
    server: { id: string; name: string; public_ip: string | null; status: string };
    databases: DatabaseInstanceSummary[];
    jobs: AgentJob[];
}) {
    const [liveJobs, setLiveJobs] = useState<AgentJob[]>(jobs);
    const [search, setSearch] = useState('');

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
        name: string;
        charset: string;
        collation: string;
    }>({
        engine: 'mysql',
        name: '',
        charset: '',
        collation: '',
    });

    const submitCreate = () => {
        form.post(route('databases.store', server.id), {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'charset', 'collation'),
        });
    };

    const destroyForm = useForm({});

    const submitDestroy = (database: DatabaseInstanceSummary) => {
        if (!confirm(`Drop database "${database.name}"? This cannot be undone.`)) {
            return;
        }
        destroyForm.delete(route('databases.destroy', database.id), { preserveScroll: true });
    };

    const showCharsetFields = form.data.engine !== 'mongodb';

    const filtered = databases.filter((d) => d.name.toLowerCase().includes(search.toLowerCase()));

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: 'Databases', href: `/servers/${server.id}/databases` },
    ];

    return (
        <ServerLayout breadcrumbs={breadcrumbs} server={server}>
            <Head title={`Databases — ${server.name}`} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-xl font-semibold">Databases</h1>

                {/* Tab navigation */}
                <div className="flex border-b">
                    <Link
                        href={route('databases.index', server.id)}
                        className="border-primary text-primary border-b-2 px-4 py-2 text-sm font-medium"
                    >
                        Databases
                    </Link>
                    <Link
                        href={route('database-users.index', server.id)}
                        className="text-muted-foreground hover:text-foreground px-4 py-2 text-sm font-medium"
                    >
                        Database Users
                    </Link>
                </div>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Databases</CardTitle>
                        <CardDescription>Schemas provisioned on this server, across MySQL/MariaDB, PostgreSQL, and MongoDB.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {databases.length > 0 && (
                            <Input
                                placeholder="Search databases..."
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                className="max-w-xs"
                            />
                        )}
                        {filtered.length > 0 && (
                            <div className="grid gap-2">
                                {filtered.map((database) => (
                                    <div key={database.id} className="flex items-center justify-between rounded-md border px-3 py-2">
                                        <div className="flex flex-col gap-1">
                                            <div className="flex items-center gap-2">
                                                <Badge variant="outline">{ENGINE_LABELS[database.engine]}</Badge>
                                                <span className="font-mono text-sm">{database.name}</span>
                                            </div>
                                            <div className="text-muted-foreground flex gap-3 text-xs">
                                                {(database.charset || database.collation) && (
                                                    <span>
                                                        {database.charset ?? '—'}
                                                        {database.collation ? ` · ${database.collation}` : ''}
                                                    </span>
                                                )}
                                                {database.created_at && <span>Added {database.created_at}</span>}
                                            </div>
                                        </div>
                                        <Button
                                            variant="destructive"
                                            size="sm"
                                            onClick={() => submitDestroy(database)}
                                            disabled={destroyForm.processing}
                                        >
                                            Drop
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                        {databases.length === 0 && <p className="text-muted-foreground text-sm">No databases yet.</p>}
                        {databases.length > 0 && filtered.length === 0 && (
                            <p className="text-muted-foreground text-sm">No databases match your search.</p>
                        )}
                    </CardContent>
                </Card>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Create database</CardTitle>
                        <CardDescription>Create a new database/schema on this server.</CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <div className="grid gap-2">
                            <Label htmlFor="engine">Engine</Label>
                            <Select value={form.data.engine} onValueChange={(value) => form.setData('engine', value as DatabaseEngine)}>
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
                            <InputError message={form.errors.engine} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="name">Name</Label>
                            <Input
                                id="name"
                                value={form.data.name}
                                onChange={(e) => form.setData('name', e.target.value)}
                                placeholder="my_database"
                                className="max-w-60 font-mono"
                            />
                            <InputError message={form.errors.name} />
                        </div>

                        {showCharsetFields && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="charset">Charset</Label>
                                    <Input
                                        id="charset"
                                        value={form.data.charset}
                                        onChange={(e) => form.setData('charset', e.target.value)}
                                        placeholder={form.data.engine === 'postgres' ? 'UTF8' : 'utf8mb4'}
                                        className="max-w-60 font-mono"
                                    />
                                    <InputError message={form.errors.charset} />
                                </div>

                                {form.data.engine !== 'postgres' && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="collation">Collation</Label>
                                        <Input
                                            id="collation"
                                            value={form.data.collation}
                                            onChange={(e) => form.setData('collation', e.target.value)}
                                            placeholder="utf8mb4_unicode_ci"
                                            className="max-w-60 font-mono"
                                        />
                                        <InputError message={form.errors.collation} />
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                    <CardFooter>
                        <Button onClick={submitCreate} disabled={form.processing || form.data.name === ''}>
                            Create database
                        </Button>
                    </CardFooter>
                </Card>

            </div>
        </ServerLayout>
    );
}
