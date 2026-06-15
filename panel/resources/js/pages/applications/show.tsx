import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import echo from '@/echo';
import AppLayout from '@/layouts/app-layout';
import { type Application, type AgentJob, type AgentJobStatus, type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ChevronDownIcon } from 'lucide-react';
import { useEffect, useState } from 'react';

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

export default function ApplicationsShow({
    application,
    server,
    phpVersions,
    jobs,
}: {
    application: Application;
    server: { id: number; name: string };
    phpVersions: string[];
    jobs: AgentJob[];
}) {
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

    const phpForm = useForm<{ php_version: string }>({
        php_version: application.php_version,
    });

    const submitPhpVersion = () => {
        phpForm.patch(route('applications.php-version', application.id), { preserveScroll: true });
    };

    const envForm = useForm<{ env_content: string }>({
        env_content: application.env_content ?? '',
    });

    const submitEnv = () => {
        envForm.patch(route('applications.env', application.id), { preserveScroll: true });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Servers', href: '/servers' },
        { title: server.name, href: `/servers/${server.id}` },
        { title: application.name, href: `/applications/${application.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={application.name} />

            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-xl font-semibold">{application.name}</h1>
                    <Badge variant={statusVariant(application.status)}>{application.status}</Badge>
                </div>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Application details</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-2 text-sm">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Domain</span>
                            <span>{application.domain ?? '—'}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Root path</span>
                            <span className="font-mono">{application.root_path}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Linux user</span>
                            <span className="font-mono">{application.linux_user}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">PHP version</span>
                            <span>{application.php_version}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>PHP version</CardTitle>
                        <CardDescription>Switch the PHP-FPM pool used by this application. Nginx is not reloaded.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Select value={phpForm.data.php_version} onValueChange={(value) => phpForm.setData('php_version', value)}>
                            <SelectTrigger className="max-w-40">
                                <SelectValue placeholder="Select a PHP version" />
                            </SelectTrigger>
                            <SelectContent>
                                {phpVersions.map((version) => (
                                    <SelectItem key={version} value={version}>
                                        PHP {version}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </CardContent>
                    <CardFooter>
                        <Button
                            onClick={submitPhpVersion}
                            disabled={phpForm.processing || phpForm.data.php_version === application.php_version}
                        >
                            Switch PHP version
                        </Button>
                    </CardFooter>
                </Card>

                <Card className="max-w-xl">
                    <CardHeader>
                        <CardTitle>Environment (.env)</CardTitle>
                        <CardDescription>Written to {application.root_path}/.env on the server.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Textarea
                            value={envForm.data.env_content}
                            onChange={(e) => envForm.setData('env_content', e.target.value)}
                            rows={10}
                            className="font-mono text-sm"
                            spellCheck={false}
                        />
                    </CardContent>
                    <CardFooter>
                        <Button onClick={submitEnv} disabled={envForm.processing}>
                            Save .env
                        </Button>
                    </CardFooter>
                </Card>

                {liveJobs.length > 0 && (
                    <Card className="max-w-xl">
                        <CardHeader>
                            <CardTitle>Provisioning progress</CardTitle>
                            <CardDescription>Live status of jobs dispatched for this application.</CardDescription>
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
