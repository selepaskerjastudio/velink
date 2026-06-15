import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Label } from '@/components/ui/label';
import echo from '@/echo';
import AppLayout from '@/layouts/app-layout';
import { type AgentJob, type AgentJobStatus, type ApplicationSummary, type BreadcrumbItem, type Server, type SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ChevronDownIcon, TriangleAlertIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'online':
        case 'succeeded':
            return 'default';
        case 'offline':
        case 'failed':
        case 'timeout':
            return 'destructive';
        case 'running':
            return 'outline';
        default:
            return 'secondary';
    }
}

const COMPONENT_LABELS: Record<string, string> = {
    nginx: 'Nginx',
    certbot: 'Certbot (Let’s Encrypt)',
    php: 'PHP-FPM',
    composer: 'Composer',
    node: 'Node.js 20',
    supervisor: 'Supervisord',
    redis: 'Redis',
    mysql: 'MySQL',
    mariadb: 'MariaDB',
    postgresql: 'PostgreSQL',
    mongodb: 'MongoDB',
};

interface AgentJobUpdatedEvent {
    uuid: string;
    type: string;
    label: string | null;
    status: AgentJobStatus;
    exit_code: number | null;
    output: string | null;
}

interface ServerPresenceEvent {
    id: string;
    status: string;
    agent_version: string | null;
    last_seen_at: string | null;
}

export default function ServersShow({
    server,
    applications,
    jobs,
    provisioningComponents,
    phpVersions,
}: {
    server: Server;
    applications: ApplicationSummary[];
    jobs: AgentJob[];
    provisioningComponents: string[];
    phpVersions: string[];
}) {
    const { flash } = usePage<SharedData>().props;
    const [liveStatus, setLiveStatus] = useState(server.status);
    const [liveJobs, setLiveJobs] = useState<AgentJob[]>(jobs);

    useEffect(() => setLiveJobs(jobs), [jobs]);
    useEffect(() => setLiveStatus(server.status), [server.status]);

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

        channel.listen('.server.presence', (event: ServerPresenceEvent) => {
            setLiveStatus(event.status);
        });

        return () => {
            echo.leave(`server.${server.id}`);
        };
    }, [server.id]);

    const form = useForm<{ components: string[]; php_versions: string[] }>({
        components: [],
        php_versions: [],
    });

    const toggleComponent = (component: string, checked: boolean) => {
        form.setData('components', checked ? [...form.data.components, component] : form.data.components.filter((c) => c !== component));
    };

    const togglePhpVersion = (version: string, checked: boolean) => {
        form.setData('php_versions', checked ? [...form.data.php_versions, version] : form.data.php_versions.filter((v) => v !== version));
    };

    const submitProvision = () => {
        form.post(route('servers.provision', server.id), { preserveScroll: true });
    };

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
                    <Badge variant={statusVariant(liveStatus)}>{liveStatus}</Badge>
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

                <Card className="max-w-xl">
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle>Applications</CardTitle>
                            <CardDescription>PHP/Laravel apps hosted on this server.</CardDescription>
                        </div>
                        <Button asChild size="sm">
                            <Link href={route('applications.create', server.id)}>New application</Link>
                        </Button>
                    </CardHeader>
                    {applications.length > 0 && (
                        <CardContent className="grid gap-2">
                            {applications.map((app) => (
                                <Link
                                    key={app.id}
                                    href={route('applications.show', app.id)}
                                    className="flex items-center justify-between rounded-md border px-3 py-2 hover:bg-accent"
                                >
                                    <div className="flex flex-col">
                                        <span className="text-sm font-medium">{app.name}</span>
                                        <span className="text-muted-foreground text-xs">{app.domain ?? '—'}</span>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline">PHP {app.php_version}</Badge>
                                        <Badge variant={statusVariant(app.status)}>{app.status}</Badge>
                                    </div>
                                </Link>
                            ))}
                        </CardContent>
                    )}
                </Card>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Manage</CardTitle>
                        <CardDescription>Services, scheduled jobs, and databases on this server.</CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('services.index', server.id)}>Services</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('cron.index', server.id)}>Cron jobs</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('databases.index', server.id)}>Databases</Link>
                        </Button>
                        <Button asChild variant="outline" size="sm">
                            <Link href={route('database-users.index', server.id)}>Database users</Link>
                        </Button>
                    </CardContent>
                </Card>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Provision services</CardTitle>
                        <CardDescription>
                            Pick the services to install on this server. The agent installs everything as a series of idempotent jobs —
                            you can re-run this any time.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3">
                        {provisioningComponents
                            .filter((component) => component !== 'base')
                            .map((component) => (
                                <div key={component} className="grid gap-2">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id={`component-${component}`}
                                            checked={form.data.components.includes(component)}
                                            onCheckedChange={(checked) => toggleComponent(component, checked === true)}
                                        />
                                        <Label htmlFor={`component-${component}`}>{COMPONENT_LABELS[component] ?? component}</Label>
                                    </div>

                                    {component === 'php' && form.data.components.includes('php') && (
                                        <div className="ml-7 flex flex-wrap gap-3">
                                            {phpVersions.map((version) => (
                                                <div key={version} className="flex items-center gap-2">
                                                    <Checkbox
                                                        id={`php-${version}`}
                                                        checked={form.data.php_versions.includes(version)}
                                                        onCheckedChange={(checked) => togglePhpVersion(version, checked === true)}
                                                    />
                                                    <Label htmlFor={`php-${version}`} className="text-sm">
                                                        PHP {version}
                                                    </Label>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                    {component === 'php' && form.data.components.includes('php') && form.errors.php_versions && (
                                        <p className="ml-7 text-sm text-destructive">{form.errors.php_versions}</p>
                                    )}
                                </div>
                            ))}
                    </CardContent>
                    <CardFooter className="justify-between">
                        {form.errors.components && <p className="text-sm text-destructive">{form.errors.components}</p>}
                        <Button onClick={submitProvision} disabled={form.processing || form.data.components.length === 0}>
                            Provision selected services
                        </Button>
                    </CardFooter>
                </Card>

                {liveJobs.length > 0 && (
                    <Card className="max-w-xl">
                        <CardHeader>
                            <CardTitle>Provisioning progress</CardTitle>
                            <CardDescription>Live status of jobs dispatched to this server.</CardDescription>
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
        </AppLayout>
    );
}
